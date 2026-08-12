<?php

use App\Livewire\Accounts\ManageAccount;
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
 * The account management screen — auth-rbac.spec.md §7.
 *
 * ⚠ Every operation here is an ACCOUNT operation, and that is why `ASSISTANT_DIRECTOR`
 * reaches none of them despite being able to create, edit and archive employee records. The
 * employee form is for employee data; this screen is for credentials.
 */
beforeEach(function () {
    $this->ahs = Company::factory()->create(['code' => 'AHS']);
    $this->aim = Company::factory()->subsidiary($this->ahs)->create(['code' => 'AIM']);
    $this->tursenia = Company::factory()->subsidiary($this->ahs)->create(['code' => 'TURSENIA']);

    foreach ([$this->ahs, $this->aim, $this->tursenia] as $company) {
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

    $this->target = managedAccountAt($this->aim, '0123456789');
});

function managedAccountAt(Company $company, string $phone, array $overrides = []): User
{
    $employee = Employee::factory()->forCompany($company)->create();

    return User::factory()->forEmployee($employee)->create(array_merge([
        'phone_no' => $phone,
        'password' => 'old-secret',
        'must_change_password' => false,
    ], $overrides));
}

function operatorWithRole(string $role, Company $employer, ?Company $roleAt = null): User
{
    $employee = Employee::factory()->forCompany($employer)->create();

    EmployeeRole::factory()->role($role)->forCompany($roleAt ?? $employer)
        ->create(['employee_id' => $employee->id]);

    return User::factory()->forEmployee($employee)->create([
        'phone_no' => '019'.fake()->unique()->numerify('#######'),
        'must_change_password' => false,
    ]);
}

/**
 * ⚠ ALL FIVE OPERATIONS, ALL THE OTHER ROLES. A screen where four operations are guarded and
 * one is not is the shape this test exists to catch, so every operation is driven for every
 * rejected role rather than sampled.
 */
it('refuses every account operation to every role except HR', function (string $role) {
    $operator = operatorWithRole($role, $this->aim);

    $this->actingAs($operator);

    // ⚠ assertForbidden, not an expected exception: Livewire catches AuthorizationException
    // and converts it to a 403 response. Discovered by probing rather than assumed — an
    // expectation of a thrown exception fails here, and a test written the other way round
    // would have passed while proving less.
    Livewire::test(ManageAccount::class, ['user' => $this->target])->assertForbidden();
})->with(['ASSISTANT_DIRECTOR', 'ACCOUNT', 'MANAGER', 'SUPERVISOR', 'HOD']);

it('refuses an employee holding no role at all', function () {
    $employee = Employee::factory()->forCompany($this->aim)->create();
    $operator = User::factory()->forEmployee($employee)->create([
        'phone_no' => '0188888888', 'must_change_password' => false,
    ]);

    $this->actingAs($operator);

    Livewire::test(ManageAccount::class, ['user' => $this->target])->assertForbidden();
});

/**
 * ⚠ Re-authorised per action, not once at mount. A Livewire action is its own HTTP request
 * naming the component and the method, so a mount-time check would authorise for the life of
 * the page — and the roles above could reach the operations by calling them directly.
 */
it('refuses each operation individually, not merely the mount', function (string $method) {
    $this->actingAs(operatorWithRole('HR', $this->aim));

    $component = Livewire::test(ManageAccount::class, ['user' => $this->target]);

    // The role is revoked after the page was mounted — the next action must still be refused.
    EmployeeRole::query()->update(['revoked_date' => now()->toDateString()]);

    $before = $this->target->fresh();

    $component->call($method)->assertForbidden();

    // ⚠ A 403 with the work already done would be the dangerous outcome — refused on the way
    // out, performed on the way in. Nothing about the account may have moved.
    $after = $this->target->fresh();

    expect($after->phone_no)->toBe($before->phone_no)
        ->and($after->password)->toBe($before->password)
        ->and($after->activation_token)->toBe($before->activation_token)
        ->and($after->failed_login_attempts)->toBe($before->failed_login_attempts);
})->with(['unlock', 'regenerateActivation', 'resetPassword', 'changeUsername']);

it('lets HR reset a password, forcing the employee to replace it', function () {
    $this->actingAs(operatorWithRole('HR', $this->aim));

    DB::table('sessions')->insert([
        'id' => 'live-session', 'user_id' => $this->target->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    Livewire::test(ManageAccount::class, ['user' => $this->target])
        ->set('newPassword', 'a memorable phrase')
        ->call('resetPassword')
        ->assertHasNoErrors();

    $target = $this->target->fresh();

    expect(Hash::check('a memorable phrase', $target->password))->toBeTrue()
        ->and($target->must_change_password)->toBeTrue()
        // Sessions die with the credential they were established under.
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});

it('enforces the configured minimum and no composition rules', function () {
    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $this->target])
        ->set('newPassword', '12345')
        ->call('resetPassword')
        ->assertHasErrors('newPassword');

    // Six lowercase characters, accepted deliberately: complexity rules produce Abcd1234!
    // and passwords written on paper (BR-A2).
    Livewire::test(ManageAccount::class, ['user' => $this->target])
        ->set('newPassword', 'sixchr')
        ->call('resetPassword')
        ->assertHasNoErrors();
});

/** ⚠ A password hash must never reach audit_logs — HR can read that table. */
it('audits the reset without recording the credential', function () {
    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $this->target])
        ->set('newPassword', 'a memorable phrase')
        ->call('resetPassword');

    $audit = AuditLog::query()->where('action', 'account.password_reset')->sole();

    expect($audit->field)->toBe('password_changed_at')
        ->and($audit->new_value)->not->toContain('$2y$')      // no bcrypt hash
        ->and($audit->new_value)->not->toBe('a memorable phrase');
});

it('lets HR lift a permanent lock', function () {
    $this->target->forceFill(['failed_login_attempts' => 12, 'locked_until' => now()->addMinutes(5)])->save();

    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $this->target])
        ->assertSet('isPermanentlyLocked', true)
        ->call('unlock');

    $target = $this->target->fresh();

    // ⚠ Both cleared together: the permanent lock is the counter, the timed lock is the
    // column. Clearing one leaves an account that still cannot sign in for a reason the
    // screen no longer shows.
    expect($target->failed_login_attempts)->toBe(0)
        ->and($target->locked_until)->toBeNull()
        ->and(AuditLog::query()->where('action', 'account.unlocked')->count())->toBe(1);
});

/** §5.6 — regeneration invalidates the previous token and clears BOTH timestamps. */
it('kills the previous token when a new QR is issued', function () {
    $this->target->forceFill([
        'activation_token' => 'the-old-token',
        'activation_expires_at' => now()->addHours(10),
        'activation_downloaded_at' => now()->subHour(),
        'activation_used_at' => null,
    ])->save();

    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $this->target])->call('regenerateActivation');

    $target = $this->target->fresh();

    expect($target->activation_token)->not->toBe('the-old-token')
        ->and($target->activation_token)->toHaveLength(64)
        // Leaving downloaded_at set would show HR a token as fetched when the one in play
        // has never been seen (BR-A22).
        ->and($target->activation_downloaded_at)->toBeNull()
        ->and($target->activation_used_at)->toBeNull()
        ->and($target->activation_expires_at->isFuture())->toBeTrue();
});

/** ⚠ The token is a credential and must never reach audit_logs. */
it('audits the reissue without recording the token', function () {
    $this->target->forceFill([
        'activation_token' => 'the-old-token',
        'activation_expires_at' => now()->addHours(10),
    ])->save();

    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $this->target])->call('regenerateActivation');

    $audit = AuditLog::query()->where('action', 'account.activation_regenerated')->sole();
    $newToken = $this->target->fresh()->activation_token;

    expect($audit->field)->toBe('activation_expires_at')
        ->and($audit->new_value)->not->toBe($newToken)
        ->and($audit->old_value)->not->toBe('the-old-token');
});

/**
 * ⚠ The only place a phone number changes anywhere in the system (adr/0006), and it is a
 * CREDENTIAL change — the employee signs in with the new number from the moment it saves.
 */
it('changes the login username and records it as one', function () {
    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $this->target])
        ->set('newPhoneNo', '019-876 5432')
        ->call('changeUsername')
        ->assertHasNoErrors();

    // Normalised on the way in: a value stored as typed would leave the account reachable
    // only by retyping the exact punctuation.
    expect($this->target->fresh()->phone_no)->toBe('0198765432');

    $audit = AuditLog::query()->where('action', 'account.username_changed')->sole();

    expect($audit->field)->toBe('phone_no')
        ->and($audit->old_value)->toBe('0123456789')
        ->and($audit->new_value)->toBe('0198765432');
});

it('refuses a username already used by another account', function () {
    managedAccountAt($this->aim, '0198765432');

    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $this->target])
        ->set('newPhoneNo', '0198765432')
        ->call('changeUsername')
        ->assertHasErrors('newPhoneNo');

    // Unchanged, and no audit row for something that did not happen.
    expect($this->target->fresh()->phone_no)->toBe('0123456789')
        ->and(AuditLog::query()->where('action', 'account.username_changed')->count())->toBe(0);
});

it('refuses a username BR-A1 would not accept', function (string $bad) {
    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $this->target])
        ->set('newPhoneNo', $bad)
        ->call('changeUsername')
        ->assertHasErrors('newPhoneNo');

    expect($this->target->fresh()->phone_no)->toBe('0123456789');
})->with(['12345', 'not-a-number', '']);

/** BR-A22's three states — and there is deliberately no "sent". */
it('reports the activation state', function (array $attributes, string $expected) {
    $this->target->forceFill($attributes)->save();

    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $this->target->fresh()])
        ->assertSet('activationState', $expected);
})->with([
    'no token issued' => [['activation_token' => null, 'activation_used_at' => null], 'none'],
    'generated, not downloaded' => [['activation_token' => 't', 'activation_downloaded_at' => null, 'activation_used_at' => null], 'generated'],
    'downloaded, not redeemed' => [['activation_token' => 't', 'activation_downloaded_at' => '2026-08-01 09:00:00', 'activation_used_at' => null], 'downloaded'],
    'redeemed' => [['activation_token' => 't', 'activation_used_at' => '2026-08-02 09:00:00'], 'redeemed'],
]);

/**
 * ⚠ Read scope bounds WHICH accounts, independently of the role — a subsidiary-employed HR
 * approves across the group but reads one company (conventions.md §2).
 */
it('refuses an HR reaching outside their read scope', function () {
    $outsider = managedAccountAt($this->tursenia, '0177777777');

    $this->actingAs(operatorWithRole('HR', $this->aim));

    Livewire::test(ManageAccount::class, ['user' => $outsider])->assertForbidden();
});

it('lets Master Admin manage any account', function () {
    $this->actingAs(User::factory()->masterAdmin()->create());

    Livewire::test(ManageAccount::class, ['user' => $this->target])
        ->call('unlock')
        ->assertHasNoErrors();
});

it('serves the screen over HTTP to HR', function () {
    $this->actingAs(operatorWithRole('HR', $this->aim));

    $this->get(route('accounts.manage', $this->target))
        ->assertOk()
        ->assertSee('Account management')
        ->assertSee($this->target->phone_no);
});
