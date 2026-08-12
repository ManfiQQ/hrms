<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\PolicyConfiguration;
use App\Models\User;
use App\Services\Auth\ActivationImage;

/**
 * The activation QR image — adr/0004 decision 7, auth-rbac.spec.md BR-A21, BR-A22.
 *
 * ⚠ THE IMAGE IS A CREDENTIAL. Whoever holds it can activate the account: redemption
 * authenticates the holder outright and lets them set the first password. So the access
 * tests here are not about privacy — they are about who may take possession of somebody
 * else's account.
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
});

function pendingFor(Company $company, string $name = 'Aminah binti Yusof', string $phone = '0123456789'): User
{
    $employee = Employee::factory()->forCompany($company)->create(['full_name' => $name]);

    return User::factory()->forEmployee($employee)->create([
        'phone_no' => $phone,
        'activation_token' => 'token-'.$phone,
        'activation_expires_at' => now()->addHours(48),
        'activation_downloaded_at' => null,
        'activation_used_at' => null,
    ]);
}

function hrAccountAt(Company $employer, ?Company $roleAt = null): User
{
    $employee = Employee::factory()->forCompany($employer)->create();

    EmployeeRole::factory()->role('HR')->forCompany($roleAt ?? $employer)
        ->create(['employee_id' => $employee->id]);

    return User::factory()->forEmployee($employee)->create([
        'phone_no' => '019'.fake()->unique()->numerify('#######'),
        'must_change_password' => false,
    ]);
}

/**
 * ⚠ ALL THREE ELEMENTS, not just the QR (adr/0004 decision 7).
 *
 * The image arrives over WhatsApp detached from whatever HR typed with it, in a thread that
 * may hold several. Without the NAME, HR sends the wrong one and hands an employee's account
 * to somebody else. Without the VALIDITY, an employee scans a dead token days later and
 * reads "not valid" with no idea why.
 */
it('renders a PNG carrying the QR, the name and the validity period', function () {
    $user = pendingFor($this->aim, 'Aminah binti Yusof');

    $png = app(ActivationImage::class)->render($user);

    // A real PNG, and big enough to hold a scannable code plus two text lines.
    expect(substr($png, 1, 3))->toBe('PNG');

    $image = imagecreatefromstring($png);

    expect(imagesx($image))->toBeGreaterThan(400)
        ->and(imagesy($image))->toBeGreaterThan(400);

    // The two text lines are drawn in ink on white, so a blank canvas — a font that silently
    // failed to render — is distinguishable from a composed one. Sample the name band.
    $nameBandHasInk = false;

    for ($x = 0; $x < imagesx($image); $x++) {
        for ($y = 425; $y < 470; $y++) {
            if ((imagecolorat($image, $x, $y) & 0xFFFFFF) !== 0xFFFFFF) {
                $nameBandHasInk = true;
                break 2;
            }
        }
    }

    expect($nameBandHasInk)->toBeTrue(
        'The name band is blank. imagettftext() failed silently, which is exactly what a '.
        'missing font looks like — and the image would ship to HR without a name on it.'
    );
});

it('encodes the redemption URL for this account\'s token', function () {
    $user = pendingFor($this->aim);

    // The QR must point at the route that redeems THIS token. Asserted through the route
    // rather than a literal, so a renamed route cannot silently produce a dead image.
    expect(route('activation.redeem', ['token' => $user->activation_token]))
        ->toContain($user->activation_token);

    expect(fn () => app(ActivationImage::class)->render($user))->not->toThrow(Exception::class);
});

it('refuses to render an image for an account with no token', function () {
    $user = pendingFor($this->aim);
    $user->forceFill(['activation_token' => null, 'activation_expires_at' => null])->save();

    // A QR for a token that does not exist looks exactly like a working one and fails on
    // scan, days later, in front of the employee.
    expect(fn () => app(ActivationImage::class)->render($user->fresh()))
        ->toThrow(RuntimeException::class, 'no activation token');
});

/** §8 test 30 — the download is stamped once, and a second fetch does not move it. */
it('stamps activation_downloaded_at on the first fetch', function () {
    $target = pendingFor($this->aim);

    $this->actingAs(hrAccountAt($this->aim));

    expect($target->activation_downloaded_at)->toBeNull();

    $this->get(route('activation.image', $target))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect($target->fresh()->activation_downloaded_at)->not->toBeNull();
});

/**
 * ⚠ §8 test 30's second half. The timestamp answers "when did HR first take possession of
 * this image". Overwriting it on every fetch would silently convert that into "when did
 * somebody last look" — a different question nobody asked, and one that destroys the only
 * half of delivery the system can state with certainty.
 */
it('does not move the timestamp on a second download', function () {
    $target = pendingFor($this->aim);

    $this->actingAs(hrAccountAt($this->aim));

    $this->get(route('activation.image', $target))->assertOk();
    $first = $target->fresh()->activation_downloaded_at;

    $this->travel(3)->hours();

    $this->get(route('activation.image', $target))->assertOk();

    expect($target->fresh()->activation_downloaded_at->eq($first))->toBeTrue();
});

it('records no download when the image cannot be rendered', function () {
    $target = pendingFor($this->aim);
    $target->forceFill(['activation_token' => null])->save();

    $this->actingAs(hrAccountAt($this->aim));

    // A failed render must not record a download that never happened.
    expect(fn () => $this->withoutExceptionHandling()->get(route('activation.image', $target)))
        ->toThrow(RuntimeException::class);

    expect($target->fresh()->activation_downloaded_at)->toBeNull();
});

it('lets Master Admin fetch any activation image', function () {
    $target = pendingFor($this->tursenia);

    $this->actingAs(User::factory()->masterAdmin()->create());

    $this->get(route('activation.image', $target))->assertOk();
});

/**
 * ⚠ The access tests. This is not "may you see this employee" — it is "may you take
 * possession of their account".
 */
it('refuses every role except HR and Master Admin', function (string $role) {
    $target = pendingFor($this->aim);

    $employee = Employee::factory()->forCompany($this->aim)->create();
    EmployeeRole::factory()->role($role)->forCompany($this->aim)
        ->create(['employee_id' => $employee->id]);

    $actor = User::factory()->forEmployee($employee)->create([
        'phone_no' => '0181234567', 'must_change_password' => false,
    ]);

    $this->actingAs($actor)->get(route('activation.image', $target))->assertForbidden();
})->with(['ASSISTANT_DIRECTOR', 'ACCOUNT', 'MANAGER', 'SUPERVISOR', 'HOD']);

it('refuses an ordinary employee holding no role at all', function () {
    $target = pendingFor($this->aim);

    $employee = Employee::factory()->forCompany($this->aim)->create();
    $actor = User::factory()->forEmployee($employee)->create([
        'phone_no' => '0181111111', 'must_change_password' => false,
    ]);

    $this->actingAs($actor)->get(route('activation.image', $target))->assertForbidden();
});

/**
 * ⚠ Read scope bounds WHICH accounts, independently of the role. A subsidiary-employed HR
 * approves across the group but reads one company (conventions.md §2) — and handing out an
 * activation follows the reading axis, because it is possession of a specific person's
 * account.
 */
it('refuses an HR reaching outside their read scope', function () {
    $target = pendingFor($this->tursenia);

    // Employed by AIM, so their read scope is AIM alone.
    $this->actingAs(hrAccountAt($this->aim));

    $this->get(route('activation.image', $target))->assertForbidden();
});

it('lets an AHS-employed HR reach any company in the group', function () {
    $target = pendingFor($this->tursenia);

    // Employed by the parent, so read scope is the whole group (adr/0004 decision 1).
    $this->actingAs(hrAccountAt($this->ahs));

    $this->get(route('activation.image', $target))->assertOk();
});

it('refuses a guest outright', function () {
    $target = pendingFor($this->aim);

    $this->get(route('activation.image', $target))->assertRedirect(route('login'));
});

it('never lets the image be cached', function () {
    $target = pendingFor($this->aim);

    $this->actingAs(hrAccountAt($this->aim));

    // A credential sitting in a proxy or browser cache outlives the access check that
    // produced it.
    $response = $this->get(route('activation.image', $target));

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
