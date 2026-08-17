<?php

use App\Models\Company;
use App\Models\Employee;

/**
 * The two `adr/0013` flags — decisions 4 and 5, deferred by that ADR's 2026-08-15 amendment
 * to the screen that would show them, and taken up by `adr/0014`.
 *
 * ⚠ BOTH ARE DISPLAY RULES WITH NO ENFORCEMENT BEHIND THEM. Nothing here asserts that a flag
 * blocks anything, because nothing may: an expired permit does not stop someone working, and
 * a missing statutory number does not stop payroll. A test that asserted a block would be
 * encoding a rule `adr/0013` explicitly refused to create.
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['code' => 'AHS']);
});

function flagged(array $attributes): Employee
{
    return Employee::factory()->forCompany(test()->company)->create($attributes);
}

it('flags a permit that has already expired', function () {
    expect(flagged(['permit_expiry' => now()->subDay()])->hasExpiredPermit())->toBeTrue();
});

it('leaves a permit expiring in the future unflagged', function () {
    expect(flagged(['permit_expiry' => now()->addMonth()])->hasExpiredPermit())->toBeFalse()
        ->and(flagged(['permit_expiry' => now()->addDay()])->hasExpiredPermit())->toBeFalse();
});

/**
 * ⚠ `adr/0013` decision 4's stated cost, asserted so it stays a decision rather than becoming
 * a surprise. A non-citizen with no date recorded is never flagged — the system knows nothing
 * about their permit and does not pretend to.
 */
it('never flags a record carrying no permit date at all', function () {
    expect(flagged(['permit_expiry' => null])->hasExpiredPermit())->toBeFalse();
});

/**
 * ⚠ THE THRESHOLD `adr/0014` SETTLED: EITHER number missing, not both. EPF and SOCSO are two
 * registrations with two agencies, so half-complete is the ordinary state — and the one that
 * matters, because it means one application has stalled while contributions accrue.
 *
 * Each half is asserted separately. A single case with both columns null would pass against
 * an `&&` implementation and prove nothing about the decision.
 */
it('flags a CONFIRMED employee missing either statutory number', function () {
    expect(flagged(['staff_status' => 'CONFIRMED', 'epf_no' => null, 'socso_no' => 'S123'])
        ->hasIncompleteStatutoryRegistration())->toBeTrue()
        ->and(flagged(['staff_status' => 'CONFIRMED', 'epf_no' => 'E123', 'socso_no' => null])
            ->hasIncompleteStatutoryRegistration())->toBeTrue()
        ->and(flagged(['staff_status' => 'CONFIRMED', 'epf_no' => null, 'socso_no' => null])
            ->hasIncompleteStatutoryRegistration())->toBeTrue();
});

/** The paired positive: a complete registration must clear the flag, or the three above prove nothing. */
it('leaves a CONFIRMED employee holding both numbers unflagged, and treats an empty string as absent', function () {
    expect(flagged(['staff_status' => 'CONFIRMED', 'epf_no' => 'E123', 'socso_no' => 'S123'])
        ->hasIncompleteStatutoryRegistration())->toBeFalse()
        ->and(flagged(['staff_status' => 'CONFIRMED', 'epf_no' => '', 'socso_no' => 'S123'])
            ->hasIncompleteStatutoryRegistration())->toBeTrue()
        ->and(flagged(['staff_status' => 'CONFIRMED', 'epf_no' => 'E123', 'socso_no' => '   '])
            ->hasIncompleteStatutoryRegistration())->toBeTrue();
});

/**
 * ⚠ The status half of the rule. Probationers, contract staff and interns hold no number
 * until they qualify, so a record without one is CORRECT rather than incomplete
 * (`adr/0013` decision 3) — flagging them would make the flag noise, and a flag that is
 * always on is a flag nobody reads.
 */
it('flags nobody below CONFIRMED, however empty the statutory columns are', function () {
    foreach (['PROBATION', 'ACTIVE', 'SUSPENDED', 'RESIGNED', 'TERMINATED'] as $status) {
        expect(flagged(['staff_status' => $status, 'epf_no' => null, 'socso_no' => null])
            ->hasIncompleteStatutoryRegistration())
            ->toBeFalse("status {$status} must not raise the statutory flag");
    }
});
