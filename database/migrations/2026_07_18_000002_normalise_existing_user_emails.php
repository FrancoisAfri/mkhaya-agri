<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time normalisation of existing user e-mail addresses to lower-case + trimmed,
 * so case-insensitive login works for accounts created before the normalisation
 * mutator existed. Rows whose normalised form would collide with another account
 * (a rare "Khaya@x.com" vs "khaya@x.com" pair) are left untouched and logged, so
 * this never violates the unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('users')->select('id', 'email')->get();

        // Map normalised email -> list of ids that currently normalise to it.
        $byNormalised = [];
        foreach ($rows as $r) {
            if ($r->email === null) {
                continue;
            }
            $norm = strtolower(trim($r->email));
            $byNormalised[$norm][] = ['id' => $r->id, 'current' => $r->email];
        }

        $updated = 0;
        $skipped = [];
        foreach ($byNormalised as $norm => $entries) {
            if (count($entries) > 1) {
                // Case-only duplicates — do not merge automatically.
                $skipped[$norm] = array_column($entries, 'id');
                continue;
            }
            $entry = $entries[0];
            if ($entry['current'] !== $norm) {
                DB::table('users')->where('id', $entry['id'])->update(['email' => $norm]);
                $updated++;
            }
        }

        if (! empty($skipped)) {
            Log::warning('normalise_existing_user_emails: left case-only duplicate emails untouched', $skipped);
        }
        Log::info("normalise_existing_user_emails: normalised {$updated} email(s), " . count($skipped) . ' duplicate group(s) skipped.');
    }

    public function down(): void
    {
        // No-op: original casing is not recoverable and normalisation is desirable.
    }
};
