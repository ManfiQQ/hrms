<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_configurations', function (Blueprint $table) {
            $table->id();

            // Per company. All six current entities happen to share the same values today,
            // which is exactly why the values must not be hardcoded — the moment one
            // diverges, code with a literal in it is wrong everywhere (conventions.md §5).
            $table->foreignId('company_id')->constrained('companies');

            // Every configurable HR policy number lives here: annual leave days, OT rate,
            // EPF contribution base, sick leave tiers, lateness penalty amounts.
            //
            // The Auth numbers live here too and are not literals in code (BR-A2, BR-A3,
            // BR-A6, BR-A21): password minimum length, the four failed-login throttle
            // tiers, the session inactivity window, and the 48-hour activation validity.
            $table->string('key');
            $table->text('value');

            // When this value takes effect. A policy change is a new row from a date, not
            // an overwrite of history.
            $table->date('effective_from');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_configurations');
    }
};
