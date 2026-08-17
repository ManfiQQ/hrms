<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Policies\EmployeePolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The employee detail screen — `employee-master.spec.md` §7 and §7.1.
 *
 * ⚠ READ-ONLY, AND THAT IS A DECISION RATHER THAN AN UNFINISHED STATE (`adr/0014`). No grant,
 * revoke, edit or archive control is rendered anywhere on this screen. §5.6 is explicit that
 * hiding a control is presentation while the authorisation is the rule — so the way to keep
 * the two from disagreeing is not to render a control the policy would refuse, and a screen
 * with no write path cannot.
 *
 * ⚠ THE COMPONENT HOLDS NO AUTHORISATION LOGIC. Which record opens is
 * `EmployeePolicy::view()`; which tabs open is `viewTab()`; which Personal fields are on the
 * one that is tiered is `personalFieldsFor()`. What lives here is the reader's choice of tab
 * and the eager loading that choice implies — the same division `EmployeeList` keeps.
 */
class EmployeeDetail extends Component
{
    public Employee $employee;

    /**
     * The requested tab, as the URL gives it — UNTRUSTED, and never read directly.
     *
     * ⚠ EVERY READER OF THIS PROPERTY IS $this->activeTab, WHICH RESOLVES IT FIRST. `#[Url]`
     * means anybody can type `?tab=` and this string is whatever they typed;
     * `EmployeePolicy::viewTab()` THROWS on a name outside TABS, deliberately, because an
     * unknown tab is a programming error and not an access decision (`adr/0004`'s 2026-08-13
     * amendment). Handing this value straight to the policy would turn a typed URL into a
     * 500, and the throw — which exists to make a missing permission row announce itself —
     * would be reachable by anyone with a keyboard.
     */
    #[Url]
    public string $tab = EmployeePolicy::TAB_EMPLOYMENT;

    /** Presentation only. The tab SET is EmployeePolicy::TABS; these are its labels. */
    public const TAB_LABELS = [
        EmployeePolicy::TAB_EMPLOYMENT => 'Employment',
        EmployeePolicy::TAB_PERSONAL => 'Personal',
        EmployeePolicy::TAB_FAMILY => 'Family',
        EmployeePolicy::TAB_EDUCATION => 'Education',
        EmployeePolicy::TAB_EMPLOYMENT_HISTORY => 'Employment history',
        EmployeePolicy::TAB_DOCUMENTS => 'Documents',
        EmployeePolicy::TAB_ROLES => 'Roles & functions',
        EmployeePolicy::TAB_STATUS_HISTORY => 'Status history',
    ];

    /**
     * Labels for the Personal tab's display keys — presentation only.
     *
     * ⚠ Keyed by EVERY key in EmployeePolicy::PERSONAL_FIELDS_ALL. A key added to the policy
     * without a label here is an undefined-index error on the next render, which is the
     * failure this project prefers: loud, immediate, and impossible to ship past.
     */
    public const PERSONAL_LABELS = [
        'full_name' => 'Full name',
        'nickname' => 'Nickname',
        'email' => 'Email',
        'phone_no' => 'Phone (login username)',
        'ic_no' => 'IC number',
        'passport_no' => 'Passport number',
        'permit_expiry' => 'Permit expiry',
        'date_of_birth' => 'Date of birth',
        'gender' => 'Gender',
        'nationality' => 'Nationality',
        'address' => 'Address',
        'epf_no' => 'EPF number',
        'socso_no' => 'SOCSO number',
        'tax_no' => 'Tax number',
        'bank_name' => 'Bank',
        'bank_account_no' => 'Bank account number',
    ];

    public function mount(Employee $employee): void
    {
        // ⚠ The record gate. Out-of-scope records never reach it — `TenantScope` applies to
        // route model binding, so another company's employee is a 404 before any policy runs,
        // and this answers the narrower question of whether an in-scope record may be opened.
        Gate::authorize('view', $employee);

        $this->employee = $employee;
    }

    /**
     * ⚠ Re-authorised on every render, not only on mount — the same reasoning `EmployeeList`
     * and `ManageAccount` carry. A Livewire component is a long-lived object reachable by
     * crafted requests, so a mount-time check authorises once for the life of the page.
     */
    public function render()
    {
        Gate::authorize('view', $this->employee);

        $this->loadTabData();

        return view('livewire.employees.employee-detail');
    }

    /** Switching tabs is a read, and it is re-authorised like every other entry point. */
    public function selectTab(string $tab): void
    {
        Gate::authorize('view', $this->employee);

        // Normalised on the way IN as well as on the way out, so the URL the reader ends up
        // with is one that means something.
        $this->tab = $this->resolveTab($tab);
    }

    /**
     * The tab actually rendered — the resolved form of $this->tab, and the only value in this
     * class that may be handed to the policy or to a view.
     *
     * Two ways to be refused, one fallback:
     *
     *   not in TABS         a typed or crafted name. Refused HERE, before the policy, because
     *                       the policy answers this with an exception rather than a boolean.
     *   in TABS, not theirs a real tab this account may not read — a supervisor asking for
     *                       `?tab=family`. The policy answers false and the fallback applies.
     *
     * ⚠ Falling back to Employment is always safe, and not by luck: `view()` IS
     * `viewTab(…, TAB_EMPLOYMENT)`, so any account that got past mount() can read it.
     */
    public function getActiveTabProperty(): string
    {
        return $this->resolveTab($this->tab);
    }

    /**
     * The tabs this account may open on this record, in EmployeePolicy::TABS order.
     *
     * ⚠ Resolved ONCE per render and read from the property cache thereafter. Each entry costs
     * a `viewTab()` call, and each of those can cost one role query per company in the read
     * scope — asking again for every tab in the strip would multiply that by eight for no new
     * information.
     *
     * @return list<string>
     */
    public function getVisibleTabsProperty(): array
    {
        return array_values(array_filter(
            EmployeePolicy::TABS,
            fn (string $tab) => Gate::allows('viewTab', [$this->employee, $tab])
        ));
    }

    /**
     * The Personal-tab fields this account may read — `adr/0014` decision 1.
     *
     * ⚠ THE VIEW RENDERS FROM THIS LIST, NEVER FROM THE MODEL'S OWN COLUMNS. A partial that
     * read `$employee->ic_no` directly would be correct today and wrong the moment a field
     * moves between tiers; asking the policy per field is what keeps the screen and the rule
     * from parting company.
     *
     * @return list<string>
     */
    public function getPersonalFieldsProperty(): array
    {
        return app(EmployeePolicy::class)->personalFieldsFor(auth()->user(), $this->employee);
    }

    /**
     * The Personal tab as label/value pairs, built from the permitted list and nothing else.
     *
     * ⚠ A FIELD ABSENT FROM personalFields IS NEVER RESOLVED, not merely never printed. The
     * loop reads the policy's list, so a withheld value is not fetched, not rendered and not
     * present in the response — there is no hidden element and no greyed-out box for a browser
     * to reveal.
     *
     * @return array<string, string>
     */
    public function getPersonalRowsProperty(): array
    {
        $rows = [];

        foreach ($this->personalFields as $field) {
            $rows[self::PERSONAL_LABELS[$field]] = match ($field) {
                // Not a column on `employees` — the login username on `users` (`adr/0006`).
                'phone_no' => $this->employee->user?->phone_no,
                'nationality' => $this->employee->nationality?->name,
                'permit_expiry' => $this->employee->permit_expiry?->format('d M Y'),
                'date_of_birth' => $this->employee->date_of_birth?->format('d M Y'),
                default => $this->employee->{$field},
            } ?: '—';
        }

        return $rows;
    }

    /**
     * BR-12's cross-company line for the Employment tab — every company where this person
     * holds authority or performs work, each with what they do there.
     *
     * ⚠ It includes the payroll employer, and §7's own example shows why: *"Employer
     * (payroll): AHS · Also serving at: AHS — BDO, Account · AIM — …"*. The two lines answer
     * different questions — who pays them, and where they act — and the employer appearing in
     * both is the normal case rather than a duplicate.
     *
     * ⚠ Current authority only. `roles()` excludes revoked rows by default, and a revoked role
     * on a line headed *"also serving at"* would state something untrue about today.
     *
     * @return array<string, list<string>>
     */
    public function getCrossCompanyServiceProperty(): array
    {
        $service = [];

        foreach ($this->employee->roles as $role) {
            $service[$role->company?->name][] = str($role->role)->replace('_', ' ')->title()->toString();
        }

        foreach ($this->employee->jobFunctions as $assignment) {
            $service[$assignment->company?->name][] = $assignment->jobFunction?->name;
        }

        return array_map(
            fn (array $labels) => array_values(array_unique(array_filter($labels))),
            array_filter($service, fn ($labels, $company) => $company !== null, ARRAY_FILTER_USE_BOTH)
        );
    }

    /** Revoked authority for the Roles & Functions tab — history, kept visibly apart. */
    public function getRevokedRolesProperty(): Collection
    {
        if ($this->activeTab !== EmployeePolicy::TAB_ROLES) {
            return collect();
        }

        return $this->employee->roles()->onlyRevoked()
            ->with(['company:id,name', 'assignedBy:id,name', 'revokedBy:id,name'])
            ->orderByDesc('revoked_date')
            ->get();
    }

    public function getTabLabelsProperty(): array
    {
        return self::TAB_LABELS;
    }

    private function resolveTab(string $tab): string
    {
        if (! in_array($tab, EmployeePolicy::TABS, true)) {
            return EmployeePolicy::TAB_EMPLOYMENT;
        }

        return Gate::allows('viewTab', [$this->employee, $tab])
            ? $tab
            : EmployeePolicy::TAB_EMPLOYMENT;
    }

    /**
     * Eager loads for the ACTIVE TAB ONLY.
     *
     * ⚠ Seven tabs' worth of relations loaded on every render would be six wasted round trips
     * per page, and nothing would look wrong while it happened — the same failure the list
     * screen avoids by eager-loading its three. Loading per tab means the cost tracks what is
     * on screen.
     *
     * `loadMissing()` rather than `load()`: a tab switch is a new render on the same hydrated
     * model, and reloading a relation that survived hydration is a query bought for nothing.
     */
    private function loadTabData(): void
    {
        $this->employee->loadMissing(match ($this->activeTab) {
            EmployeePolicy::TAB_EMPLOYMENT => [
                'company:id,name',
                'branch:id,name',
                'department:id,name',
                'position:id,title',
                'directSupervisor:id,full_name',
                'manager:id,full_name',
                'previousEmployee:id,employee_no,full_name',
                'emergencyContacts',
                // The BR-12 cross-company line is built from BOTH: authority held, and work
                // performed, each grouped by the company it applies at.
                'roles.company:id,name',
                'jobFunctions.jobFunction:id,name',
                'jobFunctions.company:id,name',
            ],
            EmployeePolicy::TAB_PERSONAL => [
                'nationality:id,name',
                // ⚠ The login account, for `phone_no` alone. The employee record holds no
                // number of its own and none may be added (`adr/0006`).
                'user:id,employee_id,phone_no',
            ],
            EmployeePolicy::TAB_FAMILY => ['familyMembers'],
            EmployeePolicy::TAB_EDUCATION => ['educationHistory'],
            EmployeePolicy::TAB_EMPLOYMENT_HISTORY => ['employmentHistory'],
            EmployeePolicy::TAB_ROLES => [
                'roles.company:id,name',
                'roles.assignedBy:id,name',
                'jobFunctions.jobFunction:id,name',
                'jobFunctions.company:id,name',
            ],
            // Documents loads nothing: there is no serving path to load it for
            // (`adr/0012` decision 11). Status History is the timeline's own commit.
            default => [],
        });
    }
}
