<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CRM URLs now bind by uuid (getRouteKeyName), so every row must have one.
 * Records created through the app already do; this backfills any nulls left by
 * raw seeders/imports so no detail/edit URL 404s after deploy.
 */
return new class extends Migration
{
    private array $tables = [
        'crm_leads', 'crm_contacts', 'crm_accounts', 'crm_deals',
        'crm_campaigns', 'crm_email_templates',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::table($table)->whereNull('uuid')->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
                }
            });
        }
    }

    public function down(): void
    {
        // No-op: uuids are now load-bearing and must not be cleared.
    }
};
