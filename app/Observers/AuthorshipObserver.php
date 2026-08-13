<?php

namespace App\Observers;

use App\Exceptions\Audit\MissingAuthorshipActorException;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationHistory;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeFamilyMember;
use App\Models\EmployeeJobFunction;
use App\Models\EmployeeRole;
use App\Models\JobFunction;
use App\Services\Audit\AuthorshipContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Fills `created_by` and `updated_by` on every table that carries them — `adr/0009`.
 *
 * ⚠ WHY AN OBSERVER AND NOT A TRAIT. A trait is opt-in per model, and a model that forgets it
 * writes NULL and raises nothing — the same silent failure this exists to close. The
 * registration below is a single list, and `AuthorshipCoverageTest` fails when any model whose
 * table has `created_by` is missing from it. Without that test this is a trait in disguise.
 *
 * ⚠ WHY NOT EXPLICIT PER-ACTION ASSIGNMENT. It requires every future write path — the legacy
 * importer, queue jobs, Phase 2 modules — to remember, and `employee-master.spec.md` §5.1
 * promises automatic. Employee Master slice 2 set both columns by hand inside its Actions;
 * that was correct at the time and is now redundant rather than wrong.
 *
 * ⚠ THE COLUMNS ARE OVERWRITTEN, NOT DEFAULTED. A caller cannot supply an author, because a
 * caller supplying one is a second mechanism, and the two would eventually disagree — the
 * objection that removed `uploaded_by`, `is_active`, `is_enabled` and `secondary_company_id`.
 */
class AuthorshipObserver
{
    /**
     * Every model whose table carries the columns.
     *
     * ⚠ THIS LIST IS THE REGISTRATION, and `AuthorshipCoverageTest` compares it against the
     * live schema in both directions. Adding a table with `created_by` and forgetting this
     * list fails the suite; listing a model whose table has no such column fails it too.
     *
     * Note who is NOT here, and it is not an oversight: `audit_logs` and `security_events`
     * carry no `created_by` at all (`conventions.md` §3 — `user_id` IS the actor, so
     * `created_by` would record the same person twice), and `branches`, `departments`,
     * `positions` do not carry them yet by `adr/0008` decision 3.
     *
     * @var list<class-string<Model>>
     */
    public const MODELS = [
        Employee::class,
        EmployeeRole::class,
        EmployeeFamilyMember::class,
        EmployeeEducationHistory::class,
        EmployeeEmploymentHistory::class,
        EmployeeDocument::class,
        JobFunction::class,
        EmployeeJobFunction::class,
    ];

    public function creating(Model $model): void
    {
        $actorId = $this->actorId($model, 'creating');

        // ⚠ BOTH columns on creation, not just created_by. `adr/0009` decision 3 makes both
        // NOT NULL, so a row inserted with a null `updated_by` could not be written at all —
        // and the same shape is already familiar: Eloquent stamps `created_at` AND
        // `updated_at` on insert for exactly this reason.
        $model->created_by = $actorId;
        $model->updated_by = $actorId;
    }

    public function updating(Model $model): void
    {
        $model->updated_by = $this->actorId($model, 'updating');
    }

    /**
     * @throws MissingAuthorshipActorException
     */
    private function actorId(Model $model, string $event): int
    {
        // The deliberate escape hatch, entered on purpose and naming a real account. Checked
        // FIRST so a seeder running while somebody happens to be authenticated still attributes
        // to the actor it named, rather than to whoever the session belongs to.
        $context = app(AuthorshipContext::class);

        if ($context->isActive()) {
            return (int) $context->actorId();
        }

        $actorId = auth()->id();

        // ⚠ Null is REFUSED. If absence of a session meant "write NULL", every queue job,
        // console command and tinker session would silently produce unattributed rows — and
        // nothing would fail, which is how the columns came to be empty in the first place.
        if ($actorId === null) {
            throw MissingAuthorshipActorException::for($model, $event);
        }

        return (int) $actorId;
    }
}
