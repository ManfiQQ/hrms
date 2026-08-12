<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" for your
    | application. You may change this value as required.
    |
    | There is no default password reset "broker" here: Laravel's email-based
    | reset flow is not used. Password reset is performed by HR or Master Admin
    | from the account management screen (auth-rbac.spec.md BR-A7), and most of
    | this workforce has no email address to send a link to.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remember Me — disabled (BR-A4)
    |--------------------------------------------------------------------------
    |
    | ⚠ NOT BUILT, AND NOT TO BE ENABLED WITHOUT AN ADR.
    |
    | Removing the checkbox from the login form is not the same as disabling the
    | feature, because the field can be posted directly. The capability is
    | therefore absent from AuthenticationService::attempt(), which takes no
    | $remember parameter, and `users` has no `remember_token` column for a
    | recaller to be minted against.
    |
    | A persistent cookie would re-authenticate someone past BR-A6's two-hour
    | inactivity window, which is what that window is for. It matters more here
    | than in most systems: much of this workforce logs in from SHARED
    | TERMINALS at the factory, studio and galleria, where a remembered login
    | means the account is never really logged out. It is also a second
    | credential that would have to be invalidated on password change and on
    | freeze — and not having it removes a thing that can be forgotten.
    |
    | This flag exists so the rule is visible in configuration rather than only
    | in a service signature. Nothing reads it to decide whether to remember;
    | there is no code path to switch on.
    |
    */

    'remember_me' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords — deliberately absent
    |--------------------------------------------------------------------------
    |
    | Laravel's 'passwords' broker configuration has been removed, along with the
    | password_reset_tokens table it pointed at. The email-based reset flow is
    | not used in this system.
    |
    | Password reset is performed by HR or Master Admin from the account
    | management screen (auth-rbac.spec.md BR-A7, §7). Most of this workforce —
    | factory crew, studio staff, live hosts — has no email address, and the
    | login identifier is employees.phone_no, not email (BR-A1).
    |
    | Do not restore this block. A broker pointing at a table that does not exist
    | reads as an unfinished feature rather than a decision.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
