<?php

namespace App\Livewire\Employees;

use App\Actions\Employee\CreateEmployee;
use App\Http\Requests\Employee\EmployeeStoreRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Nationality;
use App\Models\Position;
use App\Policies\EmployeePolicy;
use App\Services\Auth\ReadScopeResolver;
use App\Services\Employee\PriorEmploymentLookup;
use App\Support\Employee\PriorEmployment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Registering an employee — `employee-master.spec.md` §5.1, and `adr/0015` decision 5's checkbox.
 *
 * ⚠ THIS COMPONENT WRITES NOTHING ITSELF. Every row is created by `CreateEmployee`, which is the
 * only way an employee comes into existence (BR-A20): the employee, the `employee_no` claimed
 * from the locked sequence, the account, the activation token and the release of a rejoiner's
 * prior claim all land in one transaction or none of them do. A component that inserted rows
 * would be a second registration path, and the sequence collision BR-13 exists to prevent would
 * arrive through it.
 *
 * ⚠ VALIDATION IS `EmployeeStoreRequest`'s RULES, NOT A COPY OF THEM. The request object is built
 * from this component's own data and its `rules()` and `messages()` are handed to Livewire. Two
 * copies of one rule set is the shape this project refuses by name, and here the copy would be
 * the one HR actually meets while the tested one sat unused.
 *
 * ⚠ AND VALIDATION RUNS BEFORE ANY TRANSACTION OPENS. `CreateEmployee` claims the `employee_no`
 * under `lockForUpdate()` inside its transaction, so an invalid payload that reached the Action
 * would open a transaction, take the sequence row, and roll back. It rolls back cleanly — the
 * number is not burned — but the correct place to refuse is before the door, and
 * `EmployeeCreateTest` asserts the sequence does not move on a rejected payload.
 */
class EmployeeCreate extends Component
{
    /** The payroll and legal employer. Bounded by read scope, never trusted from input (§5.1). */
    public string $company_id = '';

    /**
     * ⚠ THE LOGIN USERNAME, AND IT IS NOT AN EMPLOYEE COLUMN. It lives on `users` (`adr/0006`),
     * so it is absent from `writableFields()` below and passed to `CreateEmployee` separately.
     * The form collects it because BR-A20 creates the account in the same transaction, and
     * `adr/0006` decision 7 makes this the person's only number — there is no second contact
     * field and none may be added.
     */
    public string $phone_no = '';

    public string $full_name = '';

    public string $nickname = '';

    public string $email = '';

    public string $ic_no = '';

    public string $passport_no = '';

    public string $permit_expiry = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $nationality_id = '';

    public string $address = '';

    public string $epf_no = '';

    public string $socso_no = '';

    public string $tax_no = '';

    public string $bank_name = '';

    public string $bank_account_no = '';

    public string $department_id = '';

    public string $branch_id = '';

    public string $position_id = '';

    public string $fingerprint_id = '';

    public string $level = 'STAFF';

    public string $employment_type = 'FULL-TIME';

    /**
     * ⚠ THE TERMINAL STATUSES ARE NOT OFFERED, and the exclusion is the rule rather than an
     * oversight. `RESIGNED` and `TERMINATED` freeze the account in the same transaction and are
     * reached through `ChangeEmployeeStatus`; a registration form is not where an employment ends
     * (BR-2, `adr/0004` decision 5).
     */
    public string $staff_status = 'PROBATION';

    public string $join_date = '';

    public string $probation_end_date = '';

    public string $confirmation_date = '';

    public string $direct_supervisor_id = '';

    public string $manager_id = '';

    public string $attendance_type = 'FIXED';

    public string $work_start_time = '09:00';

    public string $work_end_time = '18:00';

    public string $ot_after_time = '18:00';

    /** @var list<string> */
    public array $working_days = ['MON', 'TUE', 'WED', 'THU', 'FRI'];

    /** @var list<string> */
    public array $offday = ['SUN'];

    public bool $hours_enabled = false;

    // ─── The rejoiner block — `adr/0015` decision 5 ─────────────────────────────────────────

    /**
     * ⚠ THE CHECKBOX IS WHAT MAKES THE SEARCH LEGITIMATE, and without it a duplicate IC is simply
     * refused — which is the protection against a genuine duplicate, two records for one person
     * created by accident. Asking first is what separates "this is the same person returning"
     * from "somebody typed an IC that already exists".
     */
    public bool $has_worked_here_before = false;

    /** The identifier HR types to find the prior record: an IC, a passport, or a phone number. */
    public string $prior_identifier = '';

    public ?int $previous_employee_id = null;

    /**
     * The answer, held for display — the six fields of `PriorEmployment` as a plain array.
     *
     * ⚠ NOT THE DTO ITSELF, AND THE REASON IS LIVEWIRE RATHER THAN DESIGN. A public property is
     * dehydrated to JSON between requests and rehydrated on the next one, and Livewire supports
     * only a fixed set of types — a plain readonly class is refused outright with
     * *"Property type not supported in Livewire for property"*. Found by the first test run.
     *
     * ⚠ THE NARROWING IS NOT WEAKENED BY THIS. `PriorEmploymentLookup` still returns the DTO, so
     * the six-field boundary is enforced where the query happens; this array is built FROM it and
     * can hold nothing the DTO does not carry. What would weaken it is building this array from an
     * `Employee` instead — which is why the service returns what it returns.
     *
     * @var array{employeeId: int, fullName: string, employeeNo: string, companyName: string, servedFrom: ?string, servedTo: ?string}|null
     */
    public ?array $priorEmployment = null;

    public ?string $priorLookupMessage = null;

    /** Set once, after a successful registration — the activation QR is HR's next step. */
    public ?string $registeredEmployeeNo = null;

    public function mount(): void
    {
        // ⚠ THE DOOR ONLY. The company is chosen in the form, so `create` cannot be authorised
        // against one yet — this refuses an account that could not register into ANY company in
        // its scope, and save() authorises again against the company actually chosen.
        abort_unless($this->companies()->isNotEmpty(), 403);
    }

    /**
     * The companies this account may register into — read scope narrowed to where it may write.
     *
     * ⚠ Read scope alone would be wrong. `VIEW_ONLY` reads the whole group and writes nothing
     * (`adr/0004` decision 2), so the list is filtered by `create` per company rather than by
     * `ReadScopeResolver` alone.
     *
     * @return Collection<int, Company>
     */
    public function companies(): Collection
    {
        return Company::query()
            ->whereIn('id', app(ReadScopeResolver::class)->resolve(auth()->user()))
            ->orderBy('name')
            ->get()
            ->filter(fn (Company $company) => Gate::allows('create', [Employee::class, $company->id]))
            ->values();
    }

    /**
     * Personal-tab keys this account may write on a new record.
     *
     * ⚠ THE SUBJECT-BASED `writableFieldsFor()` IS NOT USED HERE, AND THE REASON IS THAT THERE IS
     * NO SUBJECT. It takes an `Employee`, and on a registration form none exists. Passing an
     * unsaved model would be worse than not passing one: `personalFieldsFor()` compares
     * `$actor->employee_id === $employee->id`, and on an unsaved record that is `null === null`
     * for any account holding no employee record — a false "this is my own record" match.
     *
     * So the set is derived from the same two constants the method derives from, and the
     * authorisation is `create`, which `mount()` and `save()` both enforce. `EmployeeCreateTest`
     * asserts this list equals `writableFieldsFor()` on the record that registration produces, so
     * the two cannot drift.
     *
     * @return list<string>
     */
    public function writableFields(): array
    {
        return array_values(array_diff(
            EmployeePolicy::PERSONAL_FIELDS_ALL,
            EmployeePolicy::NEVER_WRITABLE_ON_THIS_FORM,
        ));
    }

    /**
     * *"Has this person worked here before?"* — `adr/0015` decision 5.
     *
     * ⚠ AUTHORISED ON EVERY CALL, NOT ONCE AT MOUNT. Every Livewire action is its own request, so
     * a mount-time check would authorise for the life of the page. The gate is the same one the
     * form is behind: an account that may register into at least one company in its scope.
     *
     * ⚠ THERE IS NO HTTP ROUTE FOR THIS, and that is what keeps the narrow shape safe. An
     * endpoint keyed on an IC that answers with a name lets anybody holding an account probe
     * whether a given IC has ever been employed here.
     */
    public function findPriorEmployment(): void
    {
        abort_unless($this->companies()->isNotEmpty(), 403);

        $this->priorEmployment = null;
        $this->previous_employee_id = null;
        $this->priorLookupMessage = null;

        try {
            $answer = app(PriorEmploymentLookup::class)->find($this->prior_identifier);
        } catch (InvalidArgumentException) {
            // ⚠ The blank identifier. The service throws rather than answering null, because an
            // empty search would otherwise match every employee whose ic_no is empty and link
            // this registration to a stranger. Here that becomes a message, not a 500.
            $this->priorLookupMessage = 'Type an IC, passport or phone number to search for the prior record.';

            return;
        }

        if ($answer === null) {
            $this->priorLookupMessage = 'No ended employment matches that identifier. '
                .'The search is an exact match — check the number, and note that an IC is stored '
                .'without dashes.';

            return;
        }

        // ⚠ Six keys, copied from the DTO and nothing else. Dates are strings because a Carbon
        // instance is not a Livewire-safe property type either.
        $this->priorEmployment = [
            'employeeId' => $answer->employeeId,
            'fullName' => $answer->fullName,
            'employeeNo' => $answer->employeeNo,
            'companyName' => $answer->companyName,
            'servedFrom' => $answer->servedFrom?->toDateString(),
            'servedTo' => $answer->servedTo?->toDateString(),
        ];

        $this->previous_employee_id = $answer->employeeId;
    }

    /**
     * ⚠ Clearing the checkbox clears the link. Leaving `previous_employee_id` set behind an
     * unticked box would file a rejoiner as one silently, and `CreateEmployee` would supersede a
     * record nobody meant to touch.
     */
    public function updatedHasWorkedHereBefore(bool $value): void
    {
        if (! $value) {
            $this->prior_identifier = '';
            $this->previous_employee_id = null;
            $this->priorEmployment = null;
            $this->priorLookupMessage = null;
        }
    }

    public function save(): void
    {
        // ⚠ AUTHORISED AGAINST THE COMPANY ACTUALLY CHOSEN, not the one authorised at mount. The
        // field is bound by `Rule::in(...)` on the resolved scope as well — two checks on the
        // same fact, because §5.1's original rule was that company_id never comes from input at
        // all, and bounding the choice is what preserves it once `adr/0004` made scope derived.
        Gate::authorize('create', [Employee::class, (int) $this->company_id]);

        // ⚠ BEFORE THE TRANSACTION. See the class docblock: an invalid payload reaching
        // `CreateEmployee` would open a transaction and take the sequence row before rolling
        // back. Refusing here means the sequence is never touched at all.
        $request = EmployeeStoreRequest::create('/employees', 'POST', $this->payload());
        $request->setUserResolver(fn () => auth()->user());

        // ⚠ VALIDATED AGAINST THE PAYLOAD, NOT AGAINST THE COMPONENT'S PROPERTIES, and the
        // difference is not cosmetic — it was found by the first smoke run. `$this->validate()`
        // validates and returns `$this->all()`, where an untouched box is the EMPTY STRING. The
        // normalisation in payload() would then never reach the Action, and `permit_expiry`
        // arrived at the insert as '' — `Incorrect date value: ''`, a 500 rather than a message.
        //
        // Validating the payload means the SAME data is checked and saved, which is also what an
        // HTTP form does: ConvertEmptyStringsToNull runs before validation, so `nullable` and
        // `date` see null. Validator::validate() throws ValidationException, which Livewire
        // catches and renders into the error bag exactly as $this->validate() would.
        $validated = Validator::make($this->payload(), $request->rules(), $request->messages())->validate();

        $result = app(CreateEmployee::class)->execute(
            // ⚠ `phone_no` and `company_id` are REMOVED from the attribute array rather than
            // filtered by the model. `phone_no` belongs to the account (`adr/0006`) and
            // `company_id` is set by the Action from the Company it is handed — it is absent from
            // Employee's `$fillable` precisely so it can never arrive from request input, and the
            // whole tenant boundary rests on that.
            attributes: collect($validated)->except(['phone_no', 'company_id'])->all(),
            phoneNo: $this->phone_no,
            employer: Company::findOrFail((int) $this->company_id),
        );

        $this->registeredEmployeeNo = $result['employee']->employee_no;

        $this->redirectRoute('employees.show', ['employee' => $result['employee']->id], navigate: true);
    }

    /**
     * ⚠ THE PAYLOAD IS BUILT FROM THE COMPONENT'S OWN PROPERTIES so the rules are evaluated
     * against exactly what will be submitted. Empty strings are converted to null, which is what
     * an HTTP form does through `ConvertEmptyStringsToNull` — without it `nullable` columns would
     * receive `''` and `required_without` would read an untouched box as filled.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $payload = [];

        foreach ($this->all() as $key => $value) {
            if (in_array($key, ['has_worked_here_before', 'prior_identifier', 'priorEmployment',
                'priorLookupMessage', 'registeredEmployeeNo'], true)) {
                continue;
            }

            $payload[$key] = $value === '' ? null : $value;
        }

        return $payload;
    }

    public function render()
    {
        return view('livewire.employees.employee-create', [
            'companyOptions' => $this->companies(),
            'departmentOptions' => Department::query()->orderBy('name')->get(),
            'positionOptions' => Position::query()->orderBy('title')->get(),
            'nationalityOptions' => Nationality::query()->orderBy('name')->get(),
            'writable' => $this->writableFields(),
        ]);
    }
}
