<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            // Canonical spelling is binding (CLAUDE.md §5). The legacy system spelled one
            // company three different ways across three files; the unique index plus the
            // reference table in §5 is the fix.
            $table->string('name')->unique();
            $table->string('code')->unique();

            // Self-referencing hierarchy. Fixes the legacy design, which repeated the
            // parent company as a free-text string on every row.
            //
            // This column is load-bearing beyond org display: an account's READ SCOPE is
            // derived from where its employer sits here — employed by the parent (AHS)
            // reads the whole group, employed by a subsidiary reads that subsidiary only
            // (adr/0004 decision 1). A mis-parented subsidiary therefore grants its staff
            // group-wide reads, which is why the hierarchy must be covered by a test.
            $table->foreignId('parent_company_id')->nullable()->constrained('companies');

            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
