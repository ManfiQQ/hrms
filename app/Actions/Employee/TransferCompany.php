<?php

namespace App\Actions\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationHistory;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeFamilyMember;
use App\Models\EmployeeStatusHistory;
use App\Models\Scopes\TenantScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moving an employee between group entities — `employee-master.spec.md` §5.7, BR-17,
 * `adr/0003` decision 7, `adr/0010`.
 *
 * ⚠ A DISTINCT ACTION, NOT A FIELD EDIT, and `employees.company_id` is deliberately absent
 * from `Employee::$fillable` to make that structural. An edit path that let the column change
 * like any other would miss the cascade entirely — and miss it silently, because a
 * half-transferred employee has no field that says so.
 *
 * ⚠ A TRANSFER REASSIGNS STATUTORY RESPONSIBILITY for this employee's EPF, SOCSO and EA Form
 * between two legal entities (§5.7). That is why the audit row is mandatory, why it carries
 * the actor, and why all of it is one transaction.
 */
class TransferCompany
{
    /**
     * ⚠ BR-AT13's declaration. `company_id` could not be registered until an Action existed to
     * write it — AuditedFields carried a note saying exactly that, and this is the Action.
     */
    public const AUDITS = [
        Employee::class => ['company_id'],
    ];

    /**
     * The four DESCRIPTIVE child tables — `company_id` is a tenant marker denormalized from
     * the employee, so it moves with them (`adr/0003` decision 7).
     *
     * ⚠ `employee_status_history` is absent because it is an EVENT table, frozen forever.
     * `employee_roles` and `employee_job_functions` are absent because their `company_id` is a
     * company REFERENCE — a Manager role at AIM is still a Manager role at AIM after the
     * person's payroll moves, so cascading it would corrupt the data rather than merely
     * misplace it.
     *
     * @var list<class-string>
     */
    private const CASCADES = [
        EmployeeFamilyMember::class,
        EmployeeEducationHistory::class,
        EmployeeEmploymentHistory::class,
        EmployeeDocument::class,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The authority that will survive this transfer, untouched.
     *
     * ⚠ THIS IS HALF OF `adr/0010` DECISION 2, NOT A CONVENIENCE METHOD. The screen calls it
     * BEFORE the confirm button and shows HR what remains — *"this transfer leaves 2 roles and
     * 1 job function in place at AIM"* — because authority that persists after the employment
     * relationship changes is a silent bug, and the answer belongs at the point of decision
     * rather than in a record read afterwards.
     *
     * A stored snapshot was refused on the ground that HR would see the same information at a
     * better moment. **If the UI never calls this, nothing was traded — something was dropped**
     * (`adr/0010` §2a). §7 does not exist yet; this method is the contract it must honour.
     *
     * Both sets are read live rather than frozen: `employee_roles` and
     * `employee_job_functions` already record every grant with its dates, so *which roles are
     * live* is a query, never a record to keep a second time.
     *
     * @return array{roles: \Illuminate\Support\Collection, jobFunctions: \Illuminate\Support\Collection}
     */
    public function survivingAuthority(Employee $employee): array
    {
        return [
            // EmployeeRole's NotRevokedScope already excludes revoked rows, so this is current
            // authority by default rather than by remembering a filter.
            'roles' => $employee->roles()->with('company')->get(),
            'jobFunctions' => $employee->jobFunctions()->with(['company', 'jobFunction'])->get(),
        ];
    }

    /**
     * @param  int  $departmentId  ⚠ REQUIRED, never defaulted to the current value. The
     *                             department carries approval routing per (department,
     *                             company): after a transfer the old department's HOD can no
     *                             longer approve for this person, because an HOD approves only
     *                             within their own employees.company_id. Leaving it implicit
     *                             would change somebody's approval chain with nobody choosing
     *                             it. branch_id and position_id are deliberately NOT required —
     *                             both nullable, neither carries routing.
     * @param  string  $effectiveDate  the date responsibility moves. Statutory filings are
     *                                 answered from this, not from when HR typed it.
     * @return array{employee: Employee, roles: \Illuminate\Support\Collection, jobFunctions: \Illuminate\Support\Collection}
     *
     * @throws InvalidArgumentException
     */
    public function execute(
        Employee $employee,
        Company $destination,
        int $departmentId,
        string $effectiveDate,
        ?string $reason = null,
    ): array {
        $this->assertTransferable($employee, $destination);

        $origin = $employee->company;
        $originDepartmentId = $employee->department_id;

        // Read before the write, so the caller receives what it would have shown HR even
        // though nothing here changes it. Identical either side — stated so nobody "fixes" it
        // by moving it inside the transaction to be safe.
        $surviving = $this->survivingAuthority($employee);

        DB::transaction(function () use ($employee, $destination, $departmentId, $origin, $originDepartmentId, $effectiveDate, $reason) {
            // ⚠ Assigned directly. company_id is not fillable, deliberately (§8 test 2), so
            // mass assignment here would silently do nothing at all.
            $employee->company_id = $destination->id;
            $employee->department_id = $departmentId;
            $employee->save();

            $this->cascadeDescriptive($employee, $destination);

            // ⚠ BOTH LEDGER ROWS FREEZE TO THE NEW COMPANY (adr/0010 decision 3). Freezing
            // them to the old employer would open the new company's reporting with a gap — a
            // person appearing from nowhere — while freezing to the new one leaves the old
            // company's history simply ending, which is correct. The absence of further rows
            // is the departure; the absence of an arrival row is a gap.
            $this->ledgerRow(
                $employee,
                'EMPLOYER',
                (string) $origin->id,
                (string) $destination->id,
                $origin->code,
                $destination->code,
                $effectiveDate,
                $reason,
            );

            // §5.3 — any department_id change writes its own row, and a transfer is no
            // exception. A shared department kept across the transfer is a legitimate no-op
            // (adr/0002), and a no-op is not a change: writing a row for one would put an
            // event in the ledger that never happened.
            if ($originDepartmentId !== $departmentId) {
                $this->ledgerRow(
                    $employee,
                    'DEPARTMENT',
                    (string) $originDepartmentId,
                    (string) $departmentId,
                    $this->departmentName($originDepartmentId),
                    $this->departmentName($departmentId) ?? (string) $departmentId,
                    $effectiveDate,
                    $reason,
                );
            }

            // ⚠ Inside the transaction, so a transfer can never land without its audit record
            // (§5.7). When a filing is queried later this is what shows WHO made the
            // reassignment — the only thing distinguishing an ordinary HR transfer from a
            // Master Admin support intervention after the fact.
            $this->audit->record(
                action: 'employee.company_transfer',
                subject: $employee,
                field: 'company_id',
                old: (string) $origin->id,
                new: (string) $destination->id,
                reason: $reason,
            );
        });

        return [
            'employee' => $employee,
            'roles' => $surviving['roles'],
            'jobFunctions' => $surviving['jobFunctions'],
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertTransferable(Employee $employee, Company $destination): void
    {
        // ⚠ Refused BEFORE the transaction opens: nothing to roll back, and the caller gets
        // the same answer whether or not a database is reachable.
        if ($employee->hasTerminalStatus()) {
            throw new InvalidArgumentException(
                "This employee is {$employee->staff_status}, which is terminal (BR-2), and a "
                .'terminal record cannot be transferred. A returning employee gets a NEW record '
                .'with a NEW employee_no, linked back through employees.previous_employee_id — '
                .'never a transfer and never a reactivation (adr/0003 decision 9, BR-A18). '
                .'⚠ Using a transfer here would erase the break in service that decides leave '
                .'entitlement, and it would erase it silently.'
            );
        }

        if ($employee->company_id === $destination->id) {
            throw new InvalidArgumentException(
                "This employee is already employed by {$destination->code}. A no-op is not a "
                .'transfer, and the ledger records only what happened.'
            );
        }
    }

    /**
     * ⚠ TENANT SCOPE LIFTED, AND TRASHED ROWS INCLUDED — `adr/0010` decision 4.
     *
     * The row set belongs to the EMPLOYEE, not to the reader. A cascade filtered by the acting
     * account's read scope would update only the rows that account can see and leave the rest
     * behind — fewer rows updated, no error, and a record half-transferred in a way nothing
     * reports.
     *
     * ⚠ THIS SCOPE LIFT IS DEFENSIVE TODAY, NOT LOAD-BEARING — and the dependency is the part
     * worth knowing. EmployeePolicy requires the employee's company to be inside the actor's
     * read scope, so no account permitted to transfer can have a scope that excludes the old
     * company while the child rows still carry it.
     *
     * It is therefore NOT covered by a test, deliberately: one would have to bypass the policy
     * to reach the state the policy forbids, which asserts behaviour for an unreachable path
     * and claims coverage that does not exist. A test like that is worse than none — the next
     * reader would believe this line is guarded when what guards it is EmployeePolicy.
     *
     * ⚠ THE DAY THAT POLICY LOOSENS, THIS BECOMES THE ONLY THING STANDING, and nothing will
     * announce the change. adr/0010 decision 4 justifies it on principle — the rows belong to
     * the employee, not to the reader. Keep it for that reason, not because a test demands it.
     *
     * Soft-deleted rows move too. An archived document is still that person's, and one left
     * carrying the old `company_id` would come back into the wrong tenant the moment anybody
     * restored it.
     *
     * ⚠ `updated_by` IS SET BY HAND HERE, and that is not redundant with AuthorshipObserver: a
     * query-builder mass update fires no model events at all, so the observer never runs. This
     * is the one place in the module where writing it explicitly is required rather than
     * merely harmless.
     */
    private function cascadeDescriptive(Employee $employee, Company $destination): void
    {
        foreach (self::CASCADES as $model) {
            $model::withoutGlobalScope(TenantScope::class)
                ->withTrashed()
                ->where('employee_id', $employee->id)
                ->update([
                    'company_id' => $destination->id,
                    'updated_by' => auth()->id(),
                ]);
        }
    }

    private function ledgerRow(
        Employee $employee,
        string $changeType,
        ?string $oldValue,
        string $newValue,
        ?string $oldLabel,
        string $newLabel,
        string $effectiveDate,
        ?string $reason,
    ): void {
        EmployeeStatusHistory::create([
            // The NEW employer — see the note at the call site.
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'change_type' => $changeType,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'old_label' => $oldLabel,
            'new_label' => $newLabel,
            'effective_date' => $effectiveDate,
            'reason' => $reason,
            'changed_by' => auth()->id(),
        ]);
    }

    /**
     * Resolved now so it can be frozen. Scope lifted: a shared department belongs to no single
     * company, and the reader's scope must not decide whether the ledger gets a readable label
     * (`adr/0002`).
     */
    private function departmentName(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return Department::withoutGlobalScopes()->whereKey($id)->value('name');
    }
}
