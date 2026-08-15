<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Auth\ReadScopeResolver;
use App\Services\Auth\RoleChecker;
use InvalidArgumentException;

/**
 * Authorisation for Employee Master — `employee-master.spec.md` §6 and §6.2.
 *
 * ⚠ EVERY ROLE QUESTION GOES THROUGH RoleChecker, WHICH TAKES A company_id. "What authority
 * does this person have?" has no answer until a company is named — one employee may be
 * MANAGER at AIM and hold nothing at AHS. A permission function without that argument is a
 * bug, not a convenience (`adr/0003` decision 1). Nothing here queries `employee_roles`
 * directly, so `revoked_date IS NULL` is never a filter anyone can forget.
 *
 * ⚠ THREE SCOPES COEXIST HERE AND MUST NOT COLLAPSE INTO EACH OTHER (conventions.md §2):
 *
 *   Structure  where a person works       branches / departments
 *   Approval   whose requests they act on employee_roles.role + employees.company_id
 *   Read       whose records they see     the employer's position in the hierarchy
 *
 * They disagree by design. A subsidiary-employed `HR` approves across the whole group while
 * reading **one company only**, and this class implements the reading axis alone. **Holding
 * an approval stage is never an input to a visibility check** (`adr/0004` decision 1). An
 * implementation where the two always agree has merged them, and the merge is a silent
 * widening of access rather than a simplification.
 *
 * ⚠ SALARY APPEARS NOWHERE IN THIS FILE. Employee Master holds no salary data (§10
 * decision 3), and the one place the salary question is answered is
 * RoleChecker::canReadSalary(). Anticipating the Payroll check here would be a second answer
 * to it, and `adr/0003` decision 5 is unconditional: no `HR` reads salary, at any scope.
 */
class EmployeePolicy
{
    /**
     * The detail view's tabs (§7). Access differs PER TAB, not per record
     * (`adr/0004` decision 8).
     */
    public const TAB_EMPLOYMENT = 'employment';

    public const TAB_PERSONAL = 'personal';

    public const TAB_FAMILY = 'family';

    public const TAB_EDUCATION = 'education';

    public const TAB_EMPLOYMENT_HISTORY = 'employment_history';

    public const TAB_DOCUMENTS = 'documents';

    public const TAB_STATUS_HISTORY = 'status_history';

    /**
     * ⚠ THE EIGHTH TAB, DECIDED 2026-08-13. `adr/0004` decision 8's table named seven while
     * §7 listed eight, so this one had never been decided — and the code answered `false`
     * silently, which is behaviour nobody chose.
     *
     * **Administrative tier only**, plus the employee for their own record. Reasoning in
     * §6.2 and in `adr/0004` decision 8's amendment note; the short form is that this tab is
     * **not the long version of the Employment tab's cross-company line**. It adds three
     * things that line does not have: REVOKED roles, job functions, and the grant/revoke
     * controls.
     */
    public const TAB_ROLES = 'roles';

    /**
     * Every tab the detail view has (§7). Used to refuse an unknown one rather than let it
     * fall through — see viewTab().
     */
    public const TABS = [
        self::TAB_EMPLOYMENT,
        self::TAB_PERSONAL,
        self::TAB_FAMILY,
        self::TAB_EDUCATION,
        self::TAB_EMPLOYMENT_HISTORY,
        self::TAB_DOCUMENTS,
        self::TAB_ROLES,
        self::TAB_STATUS_HISTORY,
    ];

    /**
     * The only two tabs a SUPERVISOR, MANAGER or HOD may read.
     *
     * ⚠ Why these two and nothing else. A supervisor needs to know *who reports to me* and
     * *how do I reach them*. They do not need a copy of someone's IC, their spouse's identity
     * card number, or where they went to school — none of it bears on supervision.
     *
     * Restricting them to Employment alone was considered and REJECTED as too tight: a
     * supervisor who cannot find a phone number in the system will find it on WhatsApp
     * instead, and the organisation loses the control entirely. A system that blocks a
     * legitimate need gets routed around rather than obeyed.
     *
     * The emergency contact is the deliberate exception, and it is not a third tab: name and
     * number are surfaced ON the Employment tab, because a supervisor is likely the first
     * person present at an accident (Employee::emergencyContacts()).
     */
    public const SUPERVISORY_TABS = [
        self::TAB_EMPLOYMENT,
        self::TAB_PERSONAL,
    ];

    /**
     * The supervisory tier — bounded twice over: the REPORTING LINE and own company.
     *
     * ⚠ PUBLIC SINCE 2026-08-15, because `Employee::scopeVisibleTo()` expresses the same rule
     * as a query and must read the same role set. `adr/0011` accepts that the RULE exists in
     * two forms and requires a guard over both; it does not follow that the LIST OF ROLES
     * should exist twice as well, and a second copy would drift silently — the guard compares
     * outcomes, so two role lists that disagree on a role nobody holds today would agree
     * forever and part company the day it is granted.
     *
     * ⚠ THE DEPARTMENT HALF WAS REPLACED 2026-08-14 BY `adr/0011`. This constant's comment
     * read *"own department AND own company (BR-10)"* until then, and BR-10 is where the rule
     * came from — a rule about **approval authority**, borrowed to answer a **visibility**
     * question. `adr/0002` decision 5 forbids exactly that inference in the other direction.
     *
     * Department equality is symmetric and supervision is not. It meant *"sits in the same
     * department"*, which is wrong both ways: it granted colleagues who report to nobody here,
     * and refused this actor's own subordinates sitting elsewhere.
     *
     * BR-10 is untouched and still governs approval routing, which resolves
     * `(department, company) → HOD` from the role row.
     */
    public const SUPERVISORY_ROLES = ['SUPERVISOR', 'MANAGER', 'HOD'];

    /**
     * The administrative tier — reads every tab within its read scope.
     *
     * ⚠ ACCOUNT IS HERE ON THE AUTHORITY OF `adr/0004` DECISION 8, whose table groups
     * "HR / Asst Director / Account" as reading every tab. §6.4's prose says `ACCOUNT` grants
     * nothing in this module — that is about the ACTION matrix in §6, where it holds no
     * create, edit, archive or grant ability, and it does not. Read and write are separate
     * questions here, and the ADR is the later and more specific statement of the read half.
     */
    public const ADMINISTRATIVE_ROLES = ['HR', 'ASSISTANT_DIRECTOR', 'ACCOUNT'];

    /** The tier that may create, edit and archive employee records (§6). */
    private const WRITE_ROLES = ['HR', 'ASSISTANT_DIRECTOR'];

    public function __construct(
        private readonly RoleChecker $roles,
        private readonly ReadScopeResolver $scope,
    ) {}

    /**
     * May this account open the employee LIST at all? — spec §5.4, §7.
     *
     * ⚠ THIS IS NOT "CAN THEY SEE AT LEAST ONE EMPLOYEE". That test is true for EVERYBODY,
     * because `viewTab()` grants every account its own record before any role check, so it
     * would put the screen in front of a clerk whose list is one row long — themselves — under
     * a search box and seven filters. The dashboard refuses the same shape for the same
     * reason: a screen that answers a question nobody asked.
     *
     * So the gate is: **any authority role, or a `system_access` other than STANDARD.**
     * Answered from data the account already carries, with no query over `employees` — the
     * alternative, counting the visible set on every page load, spends a query to answer a
     * question the role already answers.
     *
     * ⚠ WHAT THIS DELIBERATELY DOES NOT GUARANTEE: a supervisor whose subordinates' BR-8
     * columns were never filled still sees the link and still opens a list of one row —
     * themselves. That is CORRECT, not a defect. They genuinely hold the role, and the empty
     * list is `adr/0011` decision 4 made visible: an employee nobody names is read by nobody
     * below `HR`, and the gap should be seen rather than smoothed over.
     */
    public function viewAny(User $actor): bool
    {
        if ($actor->system_access !== 'STANDARD') {
            return true;
        }

        return $this->hasAnyRoleInScope($actor, EmployeeRole::ROLES);
    }

    /**
     * May this account see this employee record at all?
     *
     * The gate in front of every tab. Passing it grants the Employment tab and nothing more —
     * viewTab() decides the rest.
     */
    public function view(User $actor, Employee $employee): bool
    {
        return $this->viewTab($actor, $employee, self::TAB_EMPLOYMENT);
    }

    /**
     * Tab-level read access — `adr/0004` decision 8, spec §6.2.
     *
     * ⚠ Resolved per tab rather than per record, and the order of the checks below is the
     * order of the decision: who you are, then whether the record is in your scope, then
     * which tabs your role opens.
     */
    public function viewTab(User $actor, Employee $employee, string $tab): bool
    {
        // ⚠ AN UNKNOWN TAB THROWS RATHER THAN RETURNING false, and the difference is the whole
        // reason TAB_ROLES needed deciding at all.
        //
        // Until 2026-08-13 an unrecognised string fell through to the supervisory branch,
        // failed the SUPERVISORY_TABS test and returned false — quietly. That is how the
        // eighth tab acquired an access rule nobody chose: not by a wrong decision, but by a
        // silent default standing in for a missing one.
        //
        // A tab name that is not in TABS is a programming error, not an access decision, and
        // an access decision is the one thing this method must never invent. Same reasoning as
        // ChangeEmployeeAssignment refusing an unknown change_type.
        if (! in_array($tab, self::TABS, true)) {
            throw new InvalidArgumentException(
                "\"{$tab}\" is not a tab on the employee detail view. The eight are: "
                .implode(', ', self::TABS).'. Adding one means deciding its row in '
                .'employee-master.spec.md §6.2 first — a tab with no decided rule gets a '
                .'silent default, which is what this check exists to prevent.'
            );
        }

        // FULL and VIEW_ONLY hold no employee record at all, so no role check could ever
        // answer for them — which is precisely why system_access exists as a separate
        // dimension from employee_roles (adr/0004 decision 2).
        if (in_array($actor->system_access, ['FULL', 'VIEW_ONLY'], true)) {
            return true;
        }

        // Own record — every tab. Documents are narrowed further by type, not by tab: the
        // employee retrieves six of the seven, and OTHER is withheld (§6.3, viewDocument()).
        if ($actor->employee_id === $employee->id) {
            return true;
        }

        // ⚠ Read scope is checked BEFORE any role, and independently of it. This is the axis
        // separation above, in code: a subsidiary-employed HR fails here for another
        // company's employee even though their HR role approves that employee's requests.
        if (! in_array($employee->company_id, $this->scope->resolve($actor), true)) {
            return false;
        }

        // The administrative tier reads every tab within scope.
        if ($this->hasAnyRoleInScope($actor, self::ADMINISTRATIVE_ROLES)) {
            return true;
        }

        // ⚠ The supervisory tier is bounded TWICE — the reporting line and own company — and
        // the company comparison reads `employees.company_id` on BOTH sides, never
        // `employee_roles.company_id` for the subject. The role row says where authority
        // applies; the employee row says who employs the person, and the same-company rule is
        // about employment (adr/0002 decision 4, adr/0003 decision 6).
        //
        // A shared department is exactly where the company half is most likely to be got
        // wrong, because the two employees are visibly colleagues.
        if (! in_array($tab, self::SUPERVISORY_TABS, true)) {
            return false;
        }

        $actorEmployee = $this->employeeOf($actor);

        if ($actorEmployee === null) {
            return false;
        }

        if ($actorEmployee->company_id !== $employee->company_id) {
            return false;
        }

        // ⚠ REPLACED THE DEPARTMENT COMPARISON 2026-08-14 — `adr/0011` decision 1. This read
        // `$actorEmployee->department_id !== $employee->department_id` until then.
        //
        // The shape changed, not just the column. The old check was SYMMETRIC — one column
        // compared across two rows — and supervision is not symmetric. This one reads the
        // SUBJECT's two BR-8 foreign keys against the ACTOR's employee id, in that direction
        // only: the subject names its supervisor, never the reverse. Flipping the operands
        // would grant an employee their own supervisor's record.
        //
        // ⚠ ONE LEVEL, NO TRAVERSAL (decision 2). A boundary that walks the chain moves when
        // one FK is edited, and a cycle in imported data hangs the query. The cost is recorded
        // rather than discovered: an HOD over a Manager over a Supervisor does NOT reach the
        // staff, because no third column points at them.
        //
        // Both columns are nullable, so an employee who reports to nobody fails this without
        // a special case — `null === <id>` is never true. That is decision 4, and it is a
        // decision: such a record is read by nobody below HR, which makes an unfilled column a
        // visible gap instead of a silent default.
        $reportsToActor = $employee->direct_supervisor_id === $actorEmployee->id
            || $employee->manager_id === $actorEmployee->id;

        if (! $reportsToActor) {
            return false;
        }

        return $this->roles->hasAnyRole($actor, self::SUPERVISORY_ROLES, $employee->company_id);
    }

    /**
     * May this account open a specific document? §6.3, `adr/0004` decision 9.
     *
     * ⚠ The employee's own access is per TYPE, which no tab-level check can express. Seven of
     * the eight are already theirs in any real sense — they submitted the identity documents
     * and the photo, and the letters are addressed to them — and withholding the scans protects
     * nothing while turning a confirmation letter for a bank loan into an HR errand.
     *
     * ⚠ Counts updated 2026-08-14 when `PHOTO` joined the enum (adr/0013 decision 7). The
     * behaviour did not change: the check reads EMPLOYEE_READABLE_TYPES, so the list is what
     * governs and these numbers are prose about it. They are corrected because a comment
     * carrying a stale count is how a reader learns to distrust the comments.
     *
     * `OTHER` is withheld from the employee. It is the escape hatch for documents that do not
     * fit the fixed types, which makes it the natural home for internal notes and
     * investigation material; hiding it gives it a defined purpose rather than leaving it an
     * undifferentiated bucket.
     */
    public function viewDocument(User $actor, Employee $employee, string $type): bool
    {
        if ($actor->employee_id === $employee->id) {
            return in_array($type, \App\Models\EmployeeDocument::EMPLOYEE_READABLE_TYPES, true);
        }

        return $this->viewTab($actor, $employee, self::TAB_DOCUMENTS);
    }

    /**
     * May this account register a new employee at this company? (§6.)
     *
     * ⚠ Takes a company_id rather than an Employee, because there is no record yet — and the
     * question is still per company. HR employed by a subsidiary may not register staff into
     * a sibling company.
     */
    public function create(User $actor, int $companyId): bool
    {
        if ($actor->isMasterAdmin()) {
            return true;
        }

        if (! in_array($companyId, $this->scope->resolve($actor), true)) {
            return false;
        }

        return $this->hasAnyRoleInScope($actor, self::WRITE_ROLES);
    }

    /** May this account edit this employee's record? `ASSISTANT_DIRECTOR` may (§6.4). */
    public function update(User $actor, Employee $employee): bool
    {
        if ($actor->isMasterAdmin()) {
            return true;
        }

        // ⚠ VIEW_ONLY reads the whole group and writes nothing — the one place its read scope
        // and its abilities part company (adr/0004 decision 2).
        if ($actor->system_access === 'VIEW_ONLY') {
            return false;
        }

        if (! in_array($employee->company_id, $this->scope->resolve($actor), true)) {
            return false;
        }

        return $this->hasAnyRoleInScope($actor, self::WRITE_ROLES);
    }

    /**
     * Soft delete only — an employee with dependent records is archived, never hard-deleted,
     * and hard deletion is not exposed anywhere (§5.2).
     */
    public function archive(User $actor, Employee $employee): bool
    {
        return $this->update($actor, $employee);
    }

    /**
     * May this account grant this role at this company? BR-16, `adr/0003` decision 3.
     *
     * ⚠ THIS IS THE POLICY HALF OF A TWO-LAYER RULE, and it is the skippable half. The
     * structural half lives on the EmployeeRole model, which refuses a restricted grant on
     * every write path whether or not anybody consulted this method. Both exist because they
     * fail differently: this one produces a clean refusal for a UI that asked, and the model
     * hook catches the code that never asked at all.
     *
     * Hiding a restricted role from the picker is presentation. The authorization must reject
     * it on submit regardless of what the form offered (§5.6).
     */
    public function grantRole(User $actor, Employee $employee, string $role, int $companyId): bool
    {
        // The four restricted roles are Master Admin's alone, hardcoded, with no
        // configurability to switch off (BR-16). This is what makes "only ACCOUNT reads
        // salary" enforceable at all: an HR who could grant roles freely would grant
        // themselves ACCOUNT and read every salary in the group.
        if (in_array($role, EmployeeRole::RESTRICTED, true)) {
            return $actor->isMasterAdmin();
        }

        if ($actor->isMasterAdmin()) {
            return true;
        }

        if ($actor->system_access === 'VIEW_ONLY') {
            return false;
        }

        // MANAGER and SUPERVISOR are routine appointments HR makes within its read scope.
        // ⚠ The company being granted AND the employee's employer must both sit in scope: the
        // first is where the authority will apply, the second is whose record is being
        // changed, and they are not the same question.
        $scope = $this->scope->resolve($actor);

        if (! in_array($companyId, $scope, true) || ! in_array($employee->company_id, $scope, true)) {
            return false;
        }

        return $this->hasAnyRoleInScope($actor, ['HR']);
    }

    /**
     * Revoking follows the same authority as granting.
     *
     * ⚠ Deliberately symmetrical. An account that could grant `MANAGER` but not withdraw it
     * would leave HR unable to undo its own routine appointment, and an account that could
     * revoke `ACCOUNT` without being able to grant it could strip the only salary reader in a
     * company — a denial of service dressed as an ordinary edit.
     */
    public function revokeRole(User $actor, Employee $employee, string $role, int $companyId): bool
    {
        return $this->grantRole($actor, $employee, $role, $companyId);
    }

    /**
     * May this account move an employee to another group company? §6, §5.7.
     *
     * ⚠ ADDED 2026-08-13, AND ITS ABSENCE UNTIL THEN IS WORTH RECORDING. `TransferCompany`
     * shipped with a comment asserting that "EmployeePolicy requires the employee's company to
     * be inside the actor's read scope" — a protection that **did not exist**. Nothing
     * authorised a transfer at all. No test caught it, because the Action had never been
     * reached through an authorised path: §7's UI does not exist, so the only caller was a
     * test calling the Action directly (`conventions.md` §9).
     *
     * **`HR` and Master Admin, each acting directly.** Neither approves the other and Master
     * Admin is not an escalation above HR — it is a second capable actor so the work never
     * stalls on one person's availability (§5.7 item 7). HR is the ordinary actor; a transfer
     * is an HR operation, not an administrative repair.
     *
     * ⚠ `ASSISTANT_DIRECTOR` is excluded despite holding create / edit / archive. A transfer
     * is not a profile edit: it reassigns statutory responsibility for this employee's EPF,
     * SOCSO and EA Form between two legal entities, and §6's matrix names two actors for it,
     * not three.
     *
     * ⚠ `ACCOUNT` is excluded too. It reads the most data in the system and administers none
     * of it — the same line `UserPolicy` draws for account operations.
     */
    public function transfer(User $actor, Employee $employee, Company $destination): bool
    {
        if ($actor->isMasterAdmin()) {
            return true;
        }

        // Reads the whole group, writes nothing, approves nothing (adr/0004 decision 2).
        //
        // ⚠ CURRENTLY UNFALSIFIABLE, AND KEPT ANYWAY — the same shape as TransferCompany's
        // tenant-scope lift, and worth knowing before somebody deletes it as dead code.
        // Removing this line leaves every test green: a VIEW_ONLY account holds a NULL
        // employee_id by definition (adr/0004 decision 2), RoleChecker returns false for any
        // account with no employee record, so the HR check below already refuses it.
        //
        // It stays because it states the rule where the decision is made rather than leaving
        // it to emerge from two other classes agreeing. The day VIEW_ONLY gains an employee
        // record — or a fourth system_access value arrives — this is the line that already
        // says no, and nothing will announce that change.
        //
        // The same redundancy exists on create(), update(), grantRole() and
        // assignJobFunction(), for the same reason.
        if ($actor->system_access === 'VIEW_ONLY') {
            return false;
        }

        $scope = $this->scope->resolve($actor);

        // ⚠ BOTH COMPANIES, AND THE FIRST ONE IS LOAD-BEARING BEYOND THIS METHOD.
        //
        // The employee's CURRENT employer must be in scope — that is the check
        // TransferCompany's cascade relies on when it explains why its tenant-scope lift is
        // defensive rather than load-bearing. Remove it and that comment becomes false again,
        // and the cascade's lift becomes the only thing standing.
        //
        // The DESTINATION is checked for the same reason grantRole() checks the company a role
        // is granted in: handing an employee to a company this account cannot even read is a
        // reassignment of statutory responsibility made blind.
        if (! in_array($employee->company_id, $scope, true) || ! in_array($destination->id, $scope, true)) {
            return false;
        }

        return $this->hasAnyRoleInScope($actor, ['HR']);
    }

    /**
     * HR assigns job functions freely within its read scope (BR-15, §6).
     *
     * The VOCABULARY is Master Admin's — see manageJobFunctionTypes(). Assigning from it is
     * ordinary HR work, which is the whole distinction BR-15 draws: keeping the vocabulary
     * under one account is what stops it drifting into three spellings of one thing.
     */
    public function assignJobFunction(User $actor, Employee $employee, int $companyId): bool
    {
        if ($actor->isMasterAdmin()) {
            return true;
        }

        if ($actor->system_access === 'VIEW_ONLY') {
            return false;
        }

        $scope = $this->scope->resolve($actor);

        if (! in_array($companyId, $scope, true) || ! in_array($employee->company_id, $scope, true)) {
            return false;
        }

        return $this->hasAnyRoleInScope($actor, ['HR']);
    }

    /**
     * Creating and deactivating `job_functions` types — Master Admin only (BR-15, §6).
     *
     * ⚠ No target argument, and no company. The vocabulary is group-wide and carries no
     * `company_id` at all; the question is "may you administer the list", not "may you act on
     * this row".
     */
    public function manageJobFunctionTypes(User $actor): bool
    {
        return $actor->isMasterAdmin();
    }

    /**
     * Editing `employee_no` — Master Admin only, and audited (BR-13, §6).
     *
     * ⚠ Not an ordinary field edit. A number vacated by a correction is BURNED, never returned
     * to the pool, because reissuing it would point previously printed letters and payslips at
     * the wrong person. The sequence never rewinds.
     */
    public function editEmployeeNo(User $actor, Employee $employee): bool
    {
        return $actor->isMasterAdmin();
    }

    /**
     * Does the actor hold any of these roles at any company in their read scope?
     *
     * ⚠ The company is still named on every check — the loop names each one in turn rather
     * than dropping the argument. Read scope narrows WHICH companies may be named; it never
     * removes the need to name one (`adr/0003` decision 1). Same shape as
     * UserPolicy::manageAccount().
     *
     * @param  list<string>  $roles
     */
    private function hasAnyRoleInScope(User $actor, array $roles): bool
    {
        foreach ($this->scope->resolve($actor) as $companyId) {
            if ($this->roles->hasAnyRole($actor, $roles, $companyId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The actor's own employee record, read WITHOUT tenant scope.
     *
     * ⚠ Same reasoning as ReadScopeResolver's identical lift, and it is not an optimisation.
     * Employee carries TenantScope, which resolves the reader's scope, which would load this
     * employee again. Resolving *who you are* must precede *what you may see*. It is safe
     * because it is keyed on the account's own employee_id and can return that row and
     * nothing else.
     */
    private function employeeOf(User $actor): ?Employee
    {
        if ($actor->employee_id === null) {
            return null;
        }

        return Employee::withoutGlobalScope(TenantScope::class)->find($actor->employee_id);
    }
}
