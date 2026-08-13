<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * ⚠ `WithoutModelEvents` WAS USED HERE AND HAS BEEN REMOVED — adr/0009, 2026-08-13.
     *
     * It is Laravel scaffolding, not a decision anyone here made, and it silently disabled
     * EVERY model event for the whole seeding run — AuthorshipObserver included. Seeded rows
     * therefore bypassed the mechanism entirely.
     *
     * ⚠ THE NOT NULL CONSTRAINT IS THE ONLY REASON THIS WAS EVER NOTICED. While the columns
     * were nullable it failed silently and wrote NULL for every seeded row, which is exactly
     * the defect adr/0009 exists to close — reproduced by a framework default nobody chose.
     * Fail-closed found in one run what fail-open had hidden since the first seeder.
     *
     * Do not reintroduce it. If a future seeder genuinely needs events suppressed, suppress
     * them for that seeder and say why — not for every model event in the system at once.
     */
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The first account in the system (adr/0001 decision 5). Idempotent, so running
        // db:seed more than once will not create a second Master Admin.
        $this->call(MasterAdminSeeder::class);

        // Order matters: policy configurations are per company, so the companies must
        // exist first. Both are idempotent (updateOrCreate).
        $this->call(CompanySeeder::class);
        $this->call(PolicyConfigurationSeeder::class);

        // The job-function vocabulary (BR-15). Independent of the two above — it carries no
        // company_id, because the list is group-wide and Master Admin owns it. Idempotent,
        // and it will not resurrect a function that has been deactivated.
        $this->call(JobFunctionSeeder::class);

        // No test/demo user is seeded. Every account other than Master Admin and Director
        // belongs to an employee and is created in the same transaction as that employee's
        // record (BR-A20). A standalone account with no employee record is a shape this
        // system does not otherwise allow, and seeding one would make it look permitted.
    }
}
