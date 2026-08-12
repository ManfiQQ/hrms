<?php

use App\Actions\Auth\CreateMasterAdmin;
use App\Actions\Auth\RemoveMasterAdmin;
use App\Exceptions\Auth\MasterAdminLimitException;
use App\Livewire\Accounts\MasterAdmins;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\PolicyConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * BR-A13 and §5.8 — creating and standing down Master Admins.
 *
 * ⚠ THE LIMITS ARE ASSERTED AGAINST THE ACTIONS, NOT THE SCREEN. §5.8 says they are enforced
 * in `CreateMasterAdmin` and `RemoveMasterAdmin`, "not in a controller and not in the UI" —
 * so the tests that matter here bypass the component entirely. A cap that only holds when
 * called through a form is not a cap.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);

    foreach ([$this->ahs, $this->aim] as $company) {
        foreach ([
            'auth.password.min_length' => '6',
            'auth.throttle.tier_4.attempts' => '12',
            'auth.activation.validity_hours' => '48',
        ] as $key => $value) {
            PolicyConfiguration::create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => now()->toDateString(),
            ]);
        }
    }

    $this->admin = User::factory()->masterAdmin()->create([
        'phone_no' => '0111111111', 'must_change_password' => false,
    ]);
});

function masterAdminCount(): int
{
    return User::query()->where('system_access', 'FULL')->count();
}

/** ⚠ Through the Action directly — the cap must hold with no UI in the picture. */
it('refuses a fourth Master Admin, called directly', function () {
    User::factory()->masterAdmin()->create(['phone_no' => '0112222222']);
    User::factory()->masterAdmin()->create(['phone_no' => '0113333333']);

    expect(masterAdminCount())->toBe(3);

    expect(fn () => app(CreateMasterAdmin::class)->execute('A Fourth', '0114444444'))
        ->toThrow(MasterAdminLimitException::class);

    // Nothing created — not the account, not a burned username.
    expect(masterAdminCount())->toBe(3)
        ->and(User::query()->where('phone_no', '0114444444')->exists())->toBeFalse();
});

it('creates up to three', function () {
    app(CreateMasterAdmin::class)->execute('Second', '0112222222');
    app(CreateMasterAdmin::class)->execute('Third', '0113333333');

    expect(masterAdminCount())->toBe(3);
});

/**
 * ⚠ Through the Action directly — the floor must hold with no UI in the picture.
 *
 * ⚠ THE FIRST VERSION OF THIS TEST WAS EMPTY, and §9's rule is what caught it. It arranged
 * for one Master Admin and then had that account remove ITSELF — which is refused by the
 * self-removal rule, a different check entirely. It passed with the floor check deleted from
 * the Action, proving only that self-removal works.
 *
 * The floor is reachable only when somebody OTHER than the last Master Admin does the
 * removing, which the screen prevents but the Action must still refuse: §5.8 puts the limit
 * in the Action precisely so a console command or a future caller cannot walk past it.
 */
it('refuses to stand down the last Master Admin, called directly', function () {
    expect(masterAdminCount())->toBe(1);

    $employee = Employee::factory()->forCompany($this->aim)->create();
    $someoneElse = User::factory()->forEmployee($employee)->create(['phone_no' => '0115555555']);

    // Not self-removal — a different actor entirely, so the floor is what has to stop this.
    expect(fn () => app(RemoveMasterAdmin::class)->execute($this->admin, $someoneElse))
        ->toThrow(MasterAdminLimitException::class, 'only Master Admin');

    expect(masterAdminCount())->toBe(1)
        ->and($this->admin->fresh()->system_access)->toBe('FULL');
});

it('allows the second-to-last removal but not the last', function () {
    $second = User::factory()->masterAdmin()->create(['phone_no' => '0112222222']);

    expect(masterAdminCount())->toBe(2);

    // Two exist: this one goes.
    app(RemoveMasterAdmin::class)->execute($second, $this->admin);
    expect(masterAdminCount())->toBe(1);

    // One left: it stays, whoever asks.
    expect(fn () => app(RemoveMasterAdmin::class)->execute($this->admin, $second->fresh()))
        ->toThrow(MasterAdminLimitException::class, 'only Master Admin');

    expect(masterAdminCount())->toBe(1);
});

/**
 * ⚠ Refused separately from the last-one rule, because it fails for a different reason and at
 * a different time. Removing your own access mid-session leaves a page that appears to work
 * and refuses every action, and the account cannot undo it.
 */
it('refuses self-removal even when three exist', function () {
    User::factory()->masterAdmin()->create(['phone_no' => '0112222222']);
    User::factory()->masterAdmin()->create(['phone_no' => '0113333333']);

    expect(fn () => app(RemoveMasterAdmin::class)->execute($this->admin, $this->admin))
        ->toThrow(MasterAdminLimitException::class, 'your own');

    expect($this->admin->fresh()->system_access)->toBe('FULL');
});

/**
 * ⚠ Removal is a DEMOTION, not a deletion — audit_logs.user_id references users, and this
 * account has spent its life doing the things most worth being able to account for. An
 * actorless audit row has lost the only thing it was for.
 */
it('stands an account down to VIEW_ONLY, keeping the row and its history', function () {
    $other = User::factory()->masterAdmin()->create(['phone_no' => '0112222222']);

    DB::table('sessions')->insert([
        'id' => 'admin-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    app(RemoveMasterAdmin::class)->execute($other, $this->admin);

    $other->refresh();

    expect($other->exists)->toBeTrue()
        ->and($other->system_access)->toBe('VIEW_ONLY')
        ->and($other->isMasterAdmin())->toBeFalse()
        // Sessions end with the privilege, or the page keeps offering operations the account
        // may no longer perform.
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(0);
});

/**
 * ⚠ VIEW_ONLY rather than STANDARD, and the difference is structural: STANDARD derives read
 * scope from the account's employer, and this account has none — it would throw
 * OrphanedAccountException on the next scoped read. That exception marks data corruption, and
 * deliberately creating the state it flags would make it meaningless.
 */
it('leaves the stood-down account able to read without an employee record', function () {
    $other = User::factory()->masterAdmin()->create(['phone_no' => '0112222222']);

    app(RemoveMasterAdmin::class)->execute($other, $this->admin);

    expect($other->fresh()->employee_id)->toBeNull()
        ->and(app(App\Services\Auth\ReadScopeResolver::class)->resolve($other->fresh()))
        ->not->toBeEmpty();
});

it('refuses to remove an account that is not a Master Admin', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    $ordinary = User::factory()->forEmployee($employee)->create(['phone_no' => '0114444444']);

    expect(fn () => app(RemoveMasterAdmin::class)->execute($ordinary, $this->admin))
        ->toThrow(MasterAdminLimitException::class, 'not a Master Admin');
});

/** ⚠ No password is created — the same reasoning that rejected the IC number. */
it('creates the account with no usable password and an activation token', function () {
    $result = app(CreateMasterAdmin::class)->execute('Second', '0112222222');

    $user = $result['user'];

    foreach (['0112222222', 'Second', 'password', ''] as $guess) {
        expect(Hash::check($guess, $user->password))->toBeFalse();
    }

    expect($user->employee_id)->toBeNull()      // no employee record, ever
        ->and($user->must_change_password)->toBeTrue()
        ->and($result['activationToken'])->toHaveLength(64)
        ->and($user->activation_expires_at->isFuture())->toBeTrue();
});

it('normalises the username and refuses one already taken', function () {
    $result = app(CreateMasterAdmin::class)->execute('Second', '+6011-222 2222');

    expect($result['user']->phone_no)->toBe('0112222222');

    expect(fn () => app(CreateMasterAdmin::class)->execute('Third', '011-222 2222'))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('audits both operations against system_access', function () {
    $result = app(CreateMasterAdmin::class)->execute('Second', '0112222222');
    app(RemoveMasterAdmin::class)->execute($result['user'], $this->admin);

    $created = AuditLog::query()->where('action', 'master_admin.created')->sole();
    $removed = AuditLog::query()->where('action', 'master_admin.removed')->sole();

    expect($created->field)->toBe('system_access')
        ->and($created->new_value)->toBe('FULL')
        ->and($removed->old_value)->toBe('FULL')
        ->and($removed->new_value)->toBe('VIEW_ONLY');
});

/** Master Admin alone — HR may reset a password but may not mint an account that bypasses scope. */
it('refuses the screen to every role except Master Admin', function (string $role) {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    EmployeeRole::factory()->role($role)->forCompany($this->aim)->create(['employee_id' => $employee->id]);

    $operator = User::factory()->forEmployee($employee)->create([
        'phone_no' => '018'.fake()->unique()->numerify('#######'), 'must_change_password' => false,
    ]);

    $this->actingAs($operator);

    Livewire::test(MasterAdmins::class)->assertForbidden();
})->with(['HR', 'ASSISTANT_DIRECTOR', 'ACCOUNT', 'MANAGER', 'HOD']);

it('refuses each action individually, not merely the mount', function (string $method) {
    $this->actingAs($this->admin);

    $component = Livewire::test(MasterAdmins::class);

    // Access withdrawn after the page was mounted — the next action must still be refused.
    $other = User::factory()->masterAdmin()->create(['phone_no' => '0112222222']);
    $this->admin->forceFill(['system_access' => 'VIEW_ONLY'])->save();
    $this->actingAs($this->admin->fresh());

    $before = masterAdminCount();

    $component->call($method, ...($method === 'remove' ? [$other->id] : []))->assertForbidden();

    // ⚠ Refused on the way out but performed on the way in would be the dangerous outcome.
    expect(masterAdminCount())->toBe($before);
})->with(['create', 'remove']);

it('lets Master Admin drive both operations through the screen', function () {
    $this->actingAs($this->admin);

    Livewire::test(MasterAdmins::class)
        ->set('name', 'Second Admin')
        ->set('phoneNo', '0112222222')
        ->call('create')
        ->assertHasNoErrors();

    expect(masterAdminCount())->toBe(2);

    $created = User::query()->where('phone_no', '0112222222')->sole();

    Livewire::test(MasterAdmins::class)
        ->call('remove', $created->id)
        ->assertHasNoErrors();

    expect(masterAdminCount())->toBe(1)
        ->and($created->fresh()->system_access)->toBe('VIEW_ONLY');
});

it('reports the ceiling and the floor to the screen', function () {
    $this->actingAs($this->admin);

    // ⚠ instance(), not assertSet: these are computed properties, and assertSet reads public
    // state — it returns null for them and would pass against any implementation.
    $component = Livewire::test(MasterAdmins::class)->instance();

    expect($component->atCapacity)->toBeFalse()
        ->and($component->isLastRemaining)->toBeTrue();

    User::factory()->masterAdmin()->create(['phone_no' => '0112222222']);
    User::factory()->masterAdmin()->create(['phone_no' => '0113333333']);

    $component = Livewire::test(MasterAdmins::class)->instance();

    expect($component->atCapacity)->toBeTrue()
        ->and($component->isLastRemaining)->toBeFalse();
});

it('serves the screen over HTTP to Master Admin', function () {
    $this->actingAs($this->admin);

    $this->get(route('master-admins'))->assertOk()->assertSee('Master Admins');
});
