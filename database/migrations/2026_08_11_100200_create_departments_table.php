<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();

            // NULLABLE ON PURPOSE — NULL means SHARED, not missing data. Same carve-out as
            // branches (adr/0002 decision 1): HQ Marketing is staffed from several
            // companies, while AIM's factory departments belong to AIM alone.
            //
            // ⚠ Same query rule: company_id IS NULL OR company_id = :current_company.
            //
            // Sharing a department is a shared PLACE, not a shared approval pool. An HOD
            // approves only for employees sharing their own employees.company_id, inside a
            // shared department as much as anywhere else (adr/0002 decision 4). Do not
            // infer authority scope from structure scope.
            $table->foreignId('company_id')->nullable()->constrained('companies');

            $table->foreignId('branch_id')->nullable()->constrained('branches');

            $table->string('name');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
