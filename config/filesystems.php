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

        // Files are stored in the DATABASE (tenant_files table), tenant-scoped by
        // RLS, via the custom 'database' driver (see AppServiceProvider). The whole
        // app keeps using Storage:: as normal; url() points at the /dbfiles route.
        'local' => [
            'driver'   => 'database',
            'disk_key' => 'local',
            'url'      => rtrim(env('APP_URL', 'http://localhost'), '/') . '/dbfiles/local',
            'throw'    => false,
        ],

        'public' => [
            'driver'     => 'database',
            'disk_key'   => 'public',
            'url'        => rtrim(env('APP_URL', 'http://localhost'), '/') . '/dbfiles/public',
            'visibility' => 'public',
            'throw'      => false,
        ],

        // Document Management. Private by design: a document is served through the module's own
        // controller, which checks entitlement, tenancy and confidentiality before a byte moves —
        // a public disk URL would bypass all three.
        'documents' => [
            'driver'     => 'database',
            'disk_key'   => 'documents',
            'url'        => rtrim(env('APP_URL', 'http://localhost'), '/') . '/dbfiles/documents',
            'visibility' => 'private',
            'throw'      => false,
        ],

        'whistleblower' => [
            'driver'     => 'database',
            'disk_key'   => 'whistleblower',
            'url'        => rtrim(env('APP_URL', 'http://localhost'), '/') . '/dbfiles/whistleblower',
            'visibility' => 'private',
            'throw'      => false,
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
