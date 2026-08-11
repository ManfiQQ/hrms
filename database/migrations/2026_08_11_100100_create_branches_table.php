<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            // NULLABLE ON PURPOSE — NULL means SHARED, not missing data.
            //
            //   NULL = shared / group-level, available across all companies (HQ,
            //          Marketing, Logistics)
            //   set  = company-dedicated, belongs to that one company (AIM's factory)
            //
            // Branches spanning companies is a common pattern in this group, not an edge
            // case: AIM, TURSENIA and ES SOFEEYA staff share one Logistics branch
            // (adr/0002 decision 1).
            //
            // This is NOT a multi-tenancy violation. The column exists from the migration
            // that creates the table, which is what Principle #4 requires; branches hold
            // no personal or financial data. Sensitive employee data stays scoped to
            // employees.company_id, which is NOT NULL. Shared structure, scoped data.
            //
            // ⚠ The global scope on this table must resolve to
            //      company_id IS NULL OR company_id = :current_company
            // A plain equality check silently hides every shared branch — fewer rows, no
            // error, presenting as "Logistics disappeared" rather than as a bug.
            $table->foreignId('company_id')->nullable()->constrained('companies');

            $table->string('name');
            $table->string('address')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
