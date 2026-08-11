<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PolicyConfiguration;
use Illuminate\Database\Seeder;

/**
 * Authentication policy numbers, seeded per company (adr/0004 decision 6).
 *
 * None of these may be hardcoded in business logic (conventions.md §5). All six entities
 * share the same values today, which is exactly why they belong in a table — the moment one
 * diverges, code with a literal in it is wrong everywhere.
 *
 * ⚠ The throttle tiers are load-bearing, not defence in depth. The password minimum is six
 * characters, chosen by the client over the recommended eight, and the username is not
 * secret — it is the employee's phone number. Password length is therefore not carrying the
 * security here; the throttling is. Relaxing these tiers, or enforcing the counter anywhere
 * other than server-side, makes brute force practical against a system holding salary and
 * identity documents.
 *
 * HR policy numbers (annual leave days, OT rate, EPF base, sick leave tiers, lateness
 * penalties) also belong in this table and are NOT seeded here — several are still open
 * questions in CLAUDE.md §10.
 */
class PolicyConfigurationSeeder extends Seeder
{
    /**
     * @return array<string, string>
     */
    private function authPolicies(): array
    {
        return [
            // BR-A2 — minimum 6 characters, no composition rules. No forced uppercase,
            // digits or symbols: complexity rules produce Abcd1234! and passwords written
            // on paper.
            'auth.password.min_length' => '6',

            // BR-A3 — four tiers, cumulative failures. The counter resets on successful
            // login, so three typos spread over months do not eventually lock someone out.
            // Keyed on the account, not the IP.
            'auth.throttle.tier_1.attempts' => '3',
            'auth.throttle.tier_1.lock_minutes' => '5',
            'auth.throttle.tier_2.attempts' => '6',
            'auth.throttle.tier_2.lock_minutes' => '10',
            'auth.throttle.tier_3.attempts' => '9',
            'auth.throttle.tier_3.lock_minutes' => '15',

            // Permanent lock. HR or Master Admin must unlock — there is no timed release.
            'auth.throttle.tier_4.attempts' => '12',
            'auth.throttle.tier_4.permanent' => 'true',

            // BR-A6 — expires after inactivity, NOT time since login. Someone working all
            // day is never interrupted; what expires is a session left open on a shared
            // terminal at the factory or studio.
            'auth.session.inactivity_minutes' => '120',

            // BR-A21 — single-use QR activation validity. Short because a WhatsApp image
            // can be forwarded: anyone holding it before the employee scans it can activate
            // the account. HR regenerates freely if the employee misses it.
            'auth.activation.validity_hours' => '48',
        ];
    }

    public function run(): void
    {
        $companies = Company::all();

        if ($companies->isEmpty()) {
            $this->command?->warn('No companies found — run CompanySeeder first. Nothing seeded.');

            return;
        }

        $effectiveFrom = now()->toDateString();

        foreach ($companies as $company) {
            foreach ($this->authPolicies() as $key => $value) {
                PolicyConfiguration::updateOrCreate(
                    ['company_id' => $company->id, 'key' => $key],
                    ['value' => $value, 'effective_from' => $effectiveFrom]
                );
            }
        }

        $count = $companies->count() * count($this->authPolicies());
        $this->command?->info("Seeded {$count} auth policy values across {$companies->count()} companies.");
    }
}
