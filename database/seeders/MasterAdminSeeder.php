<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the first account in the system.
 *
 * No account here is created by self-signup — every account is provisioned either by this
 * seeder (exactly once, for the first account) or by an already-authenticated account with
 * authority to do so (adr/0001 decision 5).
 *
 * Credentials come from the environment and never from literals in this file: .env is
 * gitignored, seeder files are not, and a password written here would stay in git history
 * permanently even after being edited out.
 *
 * See docs/modules/auth-rbac.spec.md §5.8.
 */
class MasterAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('MASTER_ADMIN_EMAIL');
        $password = env('MASTER_ADMIN_PASSWORD');

        // Fail loudly rather than fall back to a default credential (adr/0001 decision 5).
        if (blank($email) || blank($password)) {
            throw new RuntimeException(
                'MASTER_ADMIN_EMAIL and MASTER_ADMIN_PASSWORD must both be set in .env. '
                .'This seeder never falls back to a default credential — see adr/0001 '
                .'decision 5. Both keys are listed in .env.example.'
            );
        }

        // Idempotent: re-running must not create a second Master Admin (spec §5.8).
        // FULL is the single mechanism that identifies a Master Admin — there is no
        // is_master_admin column, and none may be added (adr/0004 decision 2).
        if (User::query()->where('system_access', 'FULL')->exists()) {
            $this->command?->info('Master Admin already exists — nothing to do.');

            return;
        }

        $user = new User();
        $user->name = 'Master Admin';
        $user->email = $email;

        // Assigned in plain text on purpose: the User model casts password to 'hashed',
        // which hashes once. Passing an already-hashed value would be hashed again only if
        // the cast did not check Hash::isHashed() first — it does, but assigning plain text
        // is the unambiguous form.
        $user->password = $password;

        // No employee record, and it stays that way. The account submits nothing, approves
        // nothing in the normal chain, and exists for oversight and data repair. Having
        // nothing of its own to approve is what makes "no self-approval" structural rather
        // than a check that can be forgotten (adr/0001 decision 4).
        $user->employee_id = null;

        $user->system_access = 'FULL';

        // The initial credential was handled outside the application — typed into a .env,
        // possibly shared, possibly copied from a deployment note. This is the account that
        // most needs that credential retired, not the one that should be exempt
        // (adr/0001 decision 5, BR-A23).
        $user->must_change_password = true;

        $user->save();

        $this->command?->info('Master Admin created: '.$email);
        $this->command?->warn('The password is temporary — it must be changed on first login.');
    }
}
