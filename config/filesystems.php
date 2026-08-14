<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),

            /*
             * ⚠ TURNED OFF 2026-08-14. It shipped as `true` — Laravel's own default, chosen
             * by nobody here — and it is not an option about URLs. It registers TWO ROUTES
             * on this application at boot (FilesystemServiceProvider::serveFiles):
             *
             *     GET  /storage/{path}   storage.local          where('path', '.*')
             *     PUT  /storage/{path}   storage.local.upload   where('path', '.*')
             *
             * Both are registered outside every route group, so they carry NO MIDDLEWARE:
             * not `web`, not Authenticate, not EnsureAccountIsActive. The PUT one calls
             * Storage::put() directly with the raw request body.
             *
             * Their only gate is a valid relative signature, and a signature is a bearer
             * token: it carries no identity, so EmployeePolicy is never consulted, a frozen
             * account is not stopped, and a forwarded link works for whoever holds it. This
             * disk is where employee IC scans, passports and certificates will live
             * (employee-master.spec.md §6.3).
             *
             * Nothing in this application used it — verified before the change: no
             * Storage:: call anywhere in app/, resources/, routes/ or tests/, and the QR
             * image is rendered in memory and streamed, never stored (ActivationImage).
             *
             * Turning it back on re-opens an unauthenticated write route.
             * RouteProtectionGuardTest fails if that happens; conventions.md §9.
             */
            'serve' => false,

            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
