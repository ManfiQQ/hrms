<?php

namespace App\Livewire\Employees;

use App\Actions\Employee\ChangeEmployeeAssignment;
use App\Http\Requests\Employee\EmployeeUpdateRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Nationality;
use App\Models\Position;
use App\Policies\EmployeePolicy;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

/**
 * Editing an employee record — `employee-master.spec.md` §5.1 and §6.4.
 *
 * ⚠ A SEPARATE COMPONENT FROM `EmployeeCreate`, AND THE REASON IS NOT TIDINESS. Three things
 * differ in kind rather than in degree:
 *
 * - **`phone_no` is not rendered here at all.** It is the login username, a credential changed
 *   from the account management screen by `HR` or Master Admin (§6.4, `adr/0006` decision 7,
 *   `adr/0004` decision 6). On the create form it is required; here it must not exist.
 * - **`staff_status` is not rendered here at all.** It has its own Action —
 *   `ChangeEmployeeStatus` — which validates the BR-2 transition, writes the ledger row and
 *   performs the BR-A15 freeze in one transaction. A second path writing the same fact is the
 *   shape this project has refused repeatedly.
 * - **Registration runs one Action; editing runs two kinds.** Plain columns are saved on the
 *   model; `position_id`, `department_id` and `level` go through `ChangeEmployeeAssignment`,
 *   because each writes a `employee_status_history` row that the caller must not be able to
 *   forget (§5.3).
 *
 * One component would need a mode branch around almost every field, including fields that must
 * be ABSENT on one path — and "absent" expressed as a branch is one edit away from "present".
 */
class EmployeeEdit extends Component
{
    public Employee $employee;

    /** @var array<string, mixed> the editable fields, keyed as the FormRequest names them */
    public array $form = [];

    /**
     * ⚠ REQUIRED BY `ChangeEmployeeAssignment` AND DELIBERATELY NOT "TODAY". The date a change
     * APPLIES and the date HR typed it are different facts, and `employee_status_history` carries
     * both precisely so rules can use the right one — a promotion is typically effective before
     * anybody enters it.
     *
     * ⚠ ONE DATE FOR THE WHOLE SAVE, WHICH IS A CHOICE AND NOT AN OBVIOUS ONE. Moving somebody's
     * department and level in one edit is one event to HR; per-field dates would let a save
     * record two events that did not happen separately. If a real case ever needs them apart,
     * that is two saves.
     */
    public string $effective_date = '';

    /**
     * The three fields whose changes are ledger events, not column writes (§5.3).
     *
     * ⚠ Keys are this form's field names; values are the `change_type` `ChangeEmployeeAssignment`
     * expects. `STAFF_STATUS` is deliberately absent — see the class docblock.
     */
    public const LEDGER_FIELDS = [
        'position_id' => 'POSITION',
        'department_id' => 'DEPARTMENT',
        'level' => 'LEVEL',
    ];

    /**
     * The policy's DISPLAY keys that are not column names — translated once, here.
     *
     * ⚠ WITHOUT THIS THE NATIONALITY FIELD CANNOT SAVE AT ALL, AND IT FAILS SILENTLY THREE TIMES
     * OVER. `writableFieldsFor()` returns `nationality`, because `PERSONAL_FIELDS_ALL` lists
     * display keys and the column is `nationality_id` (`adr/0014`). Bind the form to
     * `nationality` and: the rules, which are keyed `nationality_id`, see no such field and
     * `sometimes` skips them; the intersection with the editable set keeps a key that is not a
     * column; and `fill(['nationality' => 5])` is dropped without error because it is not in
     * `$fillable`. Three no-ops in a row, and the field simply never changes.
     *
     * Translating at this one boundary keeps form keys, rule keys and column names in one
     * namespace, which is what makes the three checks above line up.
     */
    private const DISPLAY_KEY_COLUMNS = [
        'nationality' => 'nationality_id',
    ];

    public function mount(Employee $employee): void
    {
        // ⚠ Enforced in the component rather than by route middleware, because every Livewire
        // action is its own request and a mount-time-only check would authorise once for the life
        // of the page. `save()` authorises again.
        Gate::authorize('update', $employee);

        $this->employee = $employee;
        $this->effective_date = now()->toDateString();

        foreach ($this->editableFields() as $field) {
            $this->form[$field] = $this->currentValue($field);
        }
    }

    /**
     * Every field this account may edit on THIS record — the personal set from the policy, plus
     * the employment fields the form owns.
     *
     * ⚠ THE PERSONAL HALF IS `writableFieldsFor()` AND NOTHING ELSE. It is derived from what the
     * actor may READ, so a tier that reads four fields can never be offered twelve to write. That
     * derivation is the whole of `adr/0014` extended to writing, and restating it here as a
     * literal list would be the second copy this project refuses.
     *
     * @return list<string>
     */
    public function editableFields(): array
    {
        $personal = array_map(
            fn (string $field) => self::DISPLAY_KEY_COLUMNS[$field] ?? $field,
            app(EmployeePolicy::class)->writableFieldsFor(auth()->user(), $this->employee),
        );

        // ⚠ NOT PERSONAL-TAB KEYS, so `writableFieldsFor()` cannot express them — it subtracts
        // from a personal set and these were never in it. They are gated by `update()` alone,
        // which is the same gate `writableFieldsFor()` calls first, so an actor refused the record
        // is refused these too rather than by a separate check that could disagree.
        $employment = [
            'branch_id', 'department_id', 'position_id', 'fingerprint_id', 'level',
            'employment_type', 'join_date', 'probation_end_date', 'confirmation_date',
            'direct_supervisor_id', 'manager_id', 'attendance_type', 'work_start_time',
            'work_end_time', 'ot_after_time', 'working_days', 'offday', 'hours_enabled',
            'previous_employee_id',
        ];

        return $personal === [] ? [] : array_values(array_merge($personal, $employment));
    }

    public function save(): void
    {
        Gate::authorize('update', $this->employee);

        $request = EmployeeUpdateRequest::create('/employees/'.$this->employee->id, 'PATCH', $this->payload());
        $request->setUserResolver(fn () => auth()->user());

        // ⚠ The update rules read the record being edited — `Rule::unique()->ignore($id)` and the
        // "at least one identity document" rule, which asks what the record will hold AFTER the
        // payload is applied rather than what the payload contains. Both need the route binding.
        $route = new RoutingRoute(['PATCH'], '/employees/{employee}', []);
        $route->bind($request);
        $route->setParameter('employee', $this->employee);
        $request->setRouteResolver(fn () => $route);

        // ⚠ VALIDATED AGAINST THE PAYLOAD FOR THE SAME REASON AS EmployeeCreate — plus one that
        // is specific to this form: its fields are nested under `form.`, so `$this->validate()`
        // would look for rules named `form.ic_no` and find rules named `ic_no`. Every field would
        // validate against nothing at all, and the form would accept anything.
        $validated = Validator::make($this->payload(), $request->rules(), $request->messages())->validate();

        // ⚠ A FIELD OUTSIDE THE EDITABLE SET IS DISCARDED, NOT TRUSTED. A crafted Livewire request
        // can set any public property, and `$this->form` is public. Intersecting with the policy's
        // answer here is what stops a supervisory-tier actor writing an IC by posting one.
        $editable = $this->editableFields();
        $changes = collect($validated)->only($editable);

        DB::transaction(function () use ($changes) {
            // ⚠ THE LEDGER FIELDS FIRST AND THROUGH THE ACTION, because each writes a
            // `employee_status_history` row in the same transaction as the change and the caller
            // must not be able to forget it (§5.3). Writing them on the model here would produce
            // a record whose history has a gap exactly where a promotion was.
            //
            // ⚠ Pointer added 2026-08-19 — "in the same transaction" is true HERE, and is no
            // longer true of §5.3 in general. §5.3.1 splits the write by date for
            // `staff_status`: effective later than today, the ledger row is written and the
            // status change itself is deferred to the scheduled task. This loop is unaffected
            // on two counts — LEDGER_FIELDS holds POSITION, DEPARTMENT and LEVEL only, and
            // §5.3.6 confines the deferral to `staff_status`, leaving `ChangeEmployeeAssignment`
            // deliberately unexamined against a future `effective_date`. Nothing changes here;
            // the sentence above must not be carried to a caller that changes status.
            foreach (self::LEDGER_FIELDS as $field => $changeType) {
                if (! $changes->has($field)) {
                    continue;
                }

                $new = $changes->get($field);

                // ⚠ SKIPPED WHEN UNCHANGED, because `ChangeEmployeeAssignment` THROWS on a no-op:
                // a ledger row for a change that did not happen would put an event in the record
                // that never occurred. A form posts every field, so most saves reach this line
                // with nothing to do.
                if ((string) $this->employee->{$field} === (string) $new) {
                    continue;
                }

                app(ChangeEmployeeAssignment::class)->execute(
                    employee: $this->employee,
                    changeType: $changeType,
                    newValue: $field === 'level' ? $new : (int) $new,
                    effectiveDate: $this->effective_date,
                );
            }

            // ⚠ ONE TRANSACTION AROUND BOTH KINDS OF WRITE. `ChangeEmployeeAssignment` opens its
            // own, which nests here rather than committing independently — so a validation-clean
            // save that fails on the plain columns does not leave a ledger row describing a
            // promotion that was rolled back.
            $this->employee->fill($changes->except(array_keys(self::LEDGER_FIELDS))->all());
            $this->employee->save();
        });

        $this->redirectRoute('employees.show', ['employee' => $this->employee->id], navigate: true);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $payload = [];

        foreach ($this->form as $key => $value) {
            $payload[$key] = $value === '' ? null : $value;
        }

        return $payload;
    }

    /**
     * ⚠ No display-key special case here any more: `editableFields()` translates them through
     * DISPLAY_KEY_COLUMNS before this is ever called, so every field name reaching this method is
     * a real column. A second translation here would be the second copy that goes stale.
     */
    private function currentValue(string $field): mixed
    {
        return $this->employee->{$field};
    }

    public function render()
    {
        return view('livewire.employees.employee-edit', [
            'editable' => $this->editableFields(),
            'departmentOptions' => Department::query()->orderBy('name')->get(),
            'positionOptions' => Position::query()->orderBy('title')->get(),
            // ⚠ NOT `Nationality::query()`, AND THE DIFFERENCE FROM THE CREATE FORM IS A DECISION
            // ALREADY TAKEN — `adr/0013` decision 6, enforced by EmployeeUpdateRequest's
            // selectableNationality(). Registration refuses a withdrawn nationality outright,
            // because deactivation exists to remove a value from the picker. Editing must still
            // offer the one this employee ALREADY HOLDS even if it has since been withdrawn:
            // otherwise the select cannot render their current value, and HR who came to fix a
            // bank number would silently change their nationality on save.
            //
            // This mirrors that rule rather than restating it, and EmployeeEditTest asserts the
            // two agree — a validation rule that admits a value the form cannot offer is a field
            // nobody can save.
            'nationalityOptions' => Nationality::withTrashed()
                ->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->orWhere('id', $this->employee->nationality_id))
                ->orderBy('name')
                ->get(),
        ]);
    }
}
