<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generic gap-free counter — schema.md, adr/0003 decision 9.
 *
 * Its first consumer is Employee Master, through the row for `key = 'employee_no'`, but the
 * table is deliberately generic: claim numbers and letter numbers will use it later rather
 * than each inventing their own counter.
 *
 * ⚠ THE ROW IS TAKEN WITH lockForUpdate() INSIDE THE SAME TRANSACTION AS THE INSERT IT
 * NUMBERS. MAX() + 1 is not acceptable — it collides whenever two requests read the current
 * maximum before either writes: a double-clicked Save button, two open tabs, a legacy import
 * running alongside manual entry, a seeder.
 *
 * The client's operating rule that ONE HR DOES ALL REGISTRATION does not remove this. That
 * rule prevents duplicate PEOPLE, not duplicate NUMBERS, and the two protections are
 * complementary rather than alternatives.
 *
 * ⚠ Deriving the number from employees.id was REJECTED: it leaves visible gaps whenever a
 * transaction rolls back, couples the number to a primary key, and makes the Master Admin
 * correction impossible, since a derived value cannot be edited.
 *
 * ⚠ THE SEQUENCE NEVER REWINDS. A resigned or terminated employee's number is retired with
 * them permanently and never reissued; a number vacated by a correction is burned, not
 * returned to the pool. Reissuing would point previously printed letters and payslips at the
 * wrong person. Nothing in this schema can enforce that — it is a property of never
 * decrementing next_value, and every consumer must respect it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();

            // Unique: one counter per key, and a second row for the same key would be a
            // second answer to "what is the next number" — the collision this table exists
            // to prevent, reintroduced one level up.
            $table->string('key')->unique();

            // The NEXT number to hand out, not the last one issued. Read then incremented
            // under the lock, so a reader that crashes before committing hands nothing out
            // and leaves no gap.
            $table->unsignedBigInteger('next_value')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
