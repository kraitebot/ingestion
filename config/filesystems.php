<?php

declare(strict_types=1);

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
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
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

        // Backblaze B2 — DB backup destination. Spatie/laravel-backup
        // uploads dumps here via the S3-compatible API. The bucket
        // (`kraite-backups`) is private, server-side encrypted, has
        // Object Lock disabled, and "Keep only the last version"
        // lifecycle so spatie's retention pruning is permanent.
        //
        // Adaptive retries (max_attempts=10) harden the multipart
        // upload path against B2's sporadic per-part `InternalError
        // (server): internal incident` 500s. The legacy default
        // (3 attempts) was insufficient — a single failed part on
        // a 1.1 GB / 200-part dump aborted the whole transfer, and
        // backups failed once or twice every few days for purely
        // transient reasons. Adaptive mode adds client-side rate
        // limiting on top of standard exponential backoff so a
        // throttled B2 endpoint does not feed itself.
        'b2' => [
            'driver' => 's3',
            'key' => env('B2_KEY_ID'),
            'secret' => env('B2_APPLICATION_KEY'),
            'region' => env('B2_REGION'),
            'bucket' => env('B2_BUCKET'),
            'endpoint' => env('B2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'report' => false,
            'retries' => [
                'mode' => 'adaptive',
                'max_attempts' => 10,
            ],
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
