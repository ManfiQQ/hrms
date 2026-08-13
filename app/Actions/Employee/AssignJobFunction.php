<?php

namespace App\Actions\Employee;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeJobFunction;
use App\Models\JobFunction;
use InvalidArgumentException;

/**
 * Assigning what a person does — `employee-master.spec.md` BR-15, §5.6.
 *
 * ⚠ JOB FUNCTION IS NOT AUTHORITY, and the two are separate structures because they answer
 * separate questions. Merging them would force the approval engine to answer questions that
 * should not exist — *"can a Live Host approve a leave request?"* (`adr/0003` decision 2).
 * Nothing in this Action touches `employee_roles`.
 *
 * ⚠ HR assigns; only Master Admin creates or deactivates the TYPES (EmployeePolicy). Keeping
 * the vocabulary under one account is what stops it drifting into three spellings of one
 * thing (`CLAUDE.md` §5).
 *
 * ⚠ Nothing is written to `audit_logs` — the row carries `created_by`, and mirroring it would
 * be two records of one fact. Same reasoning as GrantRole.
 */
class AssignJobFunction
{
    /**
     * @param  Company  $company  where the person performs this function — a real company
     *                            reference, NOT a tenant marker, and not required to be their
     *                            payroll employer. A transfer leaves this row untouched
     *                            (`adr/0003` decision 7).
     *
     * @throws InvalidArgumentException
     */
    public function execute(
        Employee $employee,
        JobFunction $function,
        Company $company,
    ): EmployeeJobFunction {
        // ⚠ A deactivated function is refused. Deactivation IS the soft delete (schema.md):
        // it disappears from the assignment picker while existing assignments stay intact.
        // Hiding it in the UI but accepting it on submit would make the withdrawal
        // presentational, which is the same failure BR-16 guards against for roles.
        if ($function->trashed()) {
            throw new InvalidArgumentException(
                "The job function \"{$function->name}\" has been deactivated and cannot be "
                .'assigned. Existing assignments stay intact and readable; Master Admin can '
                .'restore it if the workplace reopens (BR-15).'
            );
        }

        // Refused rather than silently duplicated, for the same reason as GrantRole: two live
        // rows for one (employee, company, function) make withdrawal leave one standing.
        $existing = EmployeeJobFunction::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $company->id)
            ->where('job_function_id', $function->id)
            ->exists();

        if ($existing) {
            throw new InvalidArgumentException(
                "This employee already performs \"{$function->name}\" at {$company->code}."
            );
        }

        return EmployeeJobFunction::create([
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'job_function_id' => $function->id,
            'created_by' => auth()->id(),
        ]);
    }
}
