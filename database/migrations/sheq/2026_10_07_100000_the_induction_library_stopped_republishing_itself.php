<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapse the duplicate versions `sheq:inductions:install` published of its own shipped library.
 *
 * The command guards against republishing unchanged content, but compared the new content to the
 * stored one as JSON strings. Both columns are `jsonb`, which Postgres normalises on the way in —
 * keys reordered, text re-spaced — so the stored value never equalled the encoded one, the guard
 * never fired, and every run published a new version of all seven shipped inductions. The guard
 * now compares decoded values.
 *
 * This clears up what the broken guard left behind: for each shipped induction (`company_id IS
 * NULL`), keep the newest version and delete the identical older ones.
 *
 * **Only the shipped library.** A tenant's own inductions are untouched, and so is any shipped
 * version somebody has actually completed — a completion points at a version, and deleting the row
 * it points at would orphan the record that proves a person was inducted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $codes = DB::table('sheq_inductions')->whereNull('company_id')
            ->select('code')->groupBy('code')->havingRaw('count(*) > 1')->pluck('code');

        foreach ($codes as $code) {
            $versions = DB::table('sheq_inductions')
                ->whereNull('company_id')->where('code', $code)
                ->orderByDesc('version')->get(['id', 'version']);

            $keep = $versions->first();

            if ($keep === null) {
                continue;
            }

            $superseded = $versions->skip(1)->pluck('id');

            // A version somebody was inducted against stays, whatever else goes.
            $completed = DB::table('sheq_induction_completions')
                ->whereIn('induction_id', $superseded)->pluck('induction_id')->unique();

            $removable = $superseded->reject(fn ($id) => $completed->contains($id))->values();

            if ($removable->isNotEmpty()) {
                DB::table('sheq_inductions')->whereIn('id', $removable)->delete();
            }

            DB::table('sheq_inductions')->where('id', $keep->id)
                ->update(['is_active' => true, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Deleted duplicates of shipped content are not worth recreating: re-running
        // `sheq:inductions:install` rebuilds whatever the library should hold.
    }
};
