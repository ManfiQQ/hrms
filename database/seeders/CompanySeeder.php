<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * The six group entities: one parent and five subsidiaries (CLAUDE.md §5).
 *
 * Spelling here is binding. The legacy system spelled one company three different ways
 * across three files, which is why §5 exists as a single canonical table and why `name` and
 * `code` both carry unique indexes.
 *
 * Three traps this seeder exists to avoid repeating:
 *   - ES SOFEEYA is TWO WORDS. The joined `ESSOFEEYA` used throughout the legacy system is
 *     wrong and must not be reintroduced.
 *   - THALHAH is a BRAND under AIM, not a registered entity. It gets no row here, no
 *     company_id, and must never appear in a company picker.
 *   - AHS is a parent AND an operating tenant. It employs its own staff and holds its own
 *     authority roles, so it is seeded like any other company and appears in every picker.
 *     It is not an empty holding row.
 *
 * Master Admin may add further companies later without a migration — this is seed data and
 * a naming reference, not a schema enum (adr/0003 decision 9).
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        // The parent. parent_company_id stays null — this is the top of the hierarchy, and
        // read scope is derived from it: an employee of AHS reads the whole group
        // (adr/0004 decision 1).
        $ahs = Company::updateOrCreate(
            ['code' => 'AHS'],
            [
                'name' => 'AL HADDAD SUCCESS SDN BHD',
                'parent_company_id' => null,
                'status' => 'ACTIVE',
            ]
        );

        $subsidiaries = [
            'AIM' => 'AL HADDAD INTEGRATED MARKETING',
            'ES SOFEEYA' => 'ES SOFEEYA ENTERPRISE',
            'ZISH GLOBAL' => 'ZISH GLOBAL PLT',
            'TURSENIA TRADING' => 'TURSENIA TRADING',
            'SLEGHO' => 'SLEGHO ALYA KITCHEN',
        ];

        // ⚠ Every subsidiary must point at AHS. A mis-parented subsidiary — or one left with
        // a null parent — silently grants its staff group-wide reads, because scope is
        // derived from this column and nothing raises an error when it is wrong.
        foreach ($subsidiaries as $code => $name) {
            Company::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'parent_company_id' => $ahs->id,
                    'status' => 'ACTIVE',
                ]
            );
        }

        $this->command?->info('Seeded 6 companies: AHS (parent) + 5 subsidiaries.');
    }
}
