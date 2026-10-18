<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Storage Quota
    |--------------------------------------------------------------------------
    |
    | Per-company file storage is metered against the plan's storage_gb plus any
    | purchased packs / granted credits. Files live in the tenant_files table.
    | Buying is done in fixed packs (like AI/SMS top-ups); larger recurring
    | blocks are offered as add-ons in the subscription builder.
    |
    */

    'quota' => [
        'enabled'        => env('STORAGE_QUOTA_ENABLED', true),
        'warn_threshold' => (float) env('STORAGE_QUOTA_WARN_AT', 0.8), // banner at 80% used
        'grace_percent'  => (int) env('STORAGE_QUOTA_GRACE_PERCENT', 10),
        // Instant-buy pricing: GB per pack, and Rand per pack (matches the +5 GB add-on).
        'pack_gb'        => (int) env('STORAGE_QUOTA_PACK_GB', 5),
        'pack_price'     => (float) env('STORAGE_QUOTA_PACK_PRICE', 49),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Encryption
    |--------------------------------------------------------------------------
    |
    | Files live in Postgres, so a database dump is a document dump. Encrypting at
    | the application layer is what keeps a backup, a replica or a restored
    | snapshot from being a readable set of contracts.
    |
    | Keys are a ring, not a single value: `key_id` names the one new files are
    | encrypted with, and every key listed stays available for decryption. To
    | rotate, add a key, point key_id at it, and leave the old one in place —
    | existing files keep opening and nothing needs re-encrypting that day.
    |
    | The default ring points at APP_KEY so an installation works out of the box.
    | Set STORAGE_ENCRYPTION_KEY to separate document access from session and
    | cookie access, which is worth doing: APP_KEY tends to be the more widely
    | shared of the two.
    |
    */

    'encryption' => [
        'enabled' => (bool) env('STORAGE_ENCRYPTION_ENABLED', true),
        'key_id'  => env('STORAGE_ENCRYPTION_KEY_ID', 'app'),
        'keys'    => array_filter([
            'app'      => env('STORAGE_ENCRYPTION_KEY') ?: env('APP_KEY'),
            // Add retired keys here so files written under them stay readable.
            'previous' => env('STORAGE_ENCRYPTION_KEY_PREVIOUS'),
        ]),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Scanning
    |--------------------------------------------------------------------------
    |
    | Type and magic-byte checks establish that a file is what it claims to be.
    | They say nothing about whether it is safe — a genuine PDF carrying an
    | exploit passes every one of them. The server never executes an uploaded
    | file, but a colleague opening it in Acrobat or Word does, and the library
    | is what distributed it to them.
    |
    | driver 'none' runs the structural checks only. Point it at clamav in any
    | installation holding real documents.
    |
    | on_unavailable decides what happens when clamd cannot be reached. It
    | blocks by default: a scanner that is down has not found nothing, and
    | treating those the same turns an outage into an ingestion window.
    |
    */

    'malware' => [
        'driver'         => env('STORAGE_MALWARE_DRIVER', 'none'),
        'on_unavailable' => env('STORAGE_MALWARE_ON_UNAVAILABLE', 'block'),
        'clamav'         => [
            'address' => env('STORAGE_MALWARE_CLAMAV_ADDRESS', 'tcp://127.0.0.1:3310'),
            'timeout' => (float) env('STORAGE_MALWARE_CLAMAV_TIMEOUT', 10),
        ],
    ],

];
