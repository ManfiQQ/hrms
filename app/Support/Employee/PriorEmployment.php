<?php

namespace App\Support\Employee;

use Carbon\CarbonInterface;

/**
 * The answer to *"has this person worked here before?"* — `adr/0015` decision 5.
 *
 * ⚠ THIS IS AN ANSWER, NOT A RECORD, AND THE CLASS EXISTS TO MAKE THAT STRUCTURAL. Returning an
 * `Employee` would hand the caller every column on the row — twelve identity and statutory
 * fields `adr/0014` tiers by role — through a lookup that answers one question. Five properties
 * cannot leak a thirteenth.
 *
 * **What it carries, and why each one is here:**
 *
 * - `employeeId` — the only field the flow strictly needs. It is what
 *   `previous_employee_id` is set to.
 * - `fullName` — so HR can confirm this is the person they mean. ⚠ It is also the ONLY guard
 *   against a mistyped identifier landing on somebody else's record: an IC one digit wrong is a
 *   valid IC belonging to a real other employee, and the name is what makes that visible.
 * - `employeeNo` — the human handle HR uses everywhere else.
 * - `companyName` — the prior employer. ⚠ See the note below; this field was nearly removed.
 * - `servedFrom` / `servedTo` — ⚠ these carry the DISAMBIGUATION LOAD when several prior
 *   records match. Somebody who left and returned twice has two superseded records with the
 *   SAME IC and the SAME NAME, because the functional index only constrains live rows and every
 *   superseded row indexes to NULL. Name cannot separate them. Dates can.
 *
 * **⚠ `companyName` is returned deliberately, and the argument for removing it was made and
 * withdrawn on 2026-08-17. Do not remove it on a privacy argument.**
 *
 * The case for removing it: a TURSENIA-employed HR reads one company only
 * (`adr/0004` decision 1), so telling them the prior employment was at AIM discloses group
 * structure across a tenant boundary.
 *
 * The reason it stays: **linking is an act, not a read.** Setting `previous_employee_id` fixes
 * prior service across employers — the thing a Leave spec will later compute entitlement from
 * (BR-13). An HR who links an AIM record without being shown "AIM" is performing a
 * cross-company act blind, and hiding the employer hides what they are doing rather than
 * protecting anything. The six companies are also not a secret: `CLAUDE.md` §5 lists them and
 * the employee list renders them in its own filter.
 *
 * **What is NOT here, and must not be added:** date of birth, IC, passport, address, bank
 * details, statutory numbers, department, position, level, salary — nothing. If a caller needs
 * those it is not using this lookup; it is reading a record, and that goes through
 * `EmployeePolicy`.
 */
final readonly class PriorEmployment
{
    public function __construct(
        public int $employeeId,
        public string $fullName,
        public string $employeeNo,
        public string $companyName,
        public ?CarbonInterface $servedFrom,
        public ?CarbonInterface $servedTo,
    ) {}

    /**
     * ⚠ BOTH DATES ARE NULLABLE, AND NEITHER IS GUARANTEED.
     *
     * `employees.join_date` is nullable — the legacy import carries records without one — and
     * `servedTo` comes from the most recent terminal row on `employee_status_history`, which the
     * lookup reads through the employee relationship. A record can be terminal with no ledger
     * row: `AccountExpiry` documents exactly that state and asserts it by test.
     *
     * So this answers *"can HR read a service period off this at all"*, and a caller that renders
     * the dates without asking is rendering "– to –".
     */
    public function hasServicePeriod(): bool
    {
        return $this->servedFrom !== null && $this->servedTo !== null;
    }
}
