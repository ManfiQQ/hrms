<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The first account in the system (adr/0001 decision 5). Idempotent, so running
        // db:seed more than once will not create a second Master Admin.
        $this->call(MasterAdminSeeder::class);

        // No test/demo user is seeded. Every account other than Master Admin and Director
        // belongs to an employee and is created in the same transaction as that employee's
        // record (BR-A20). A standalone account with no employee record is a shape this
        // system does not otherwise allow, and seeding one would make it look permitted.
    }
}
