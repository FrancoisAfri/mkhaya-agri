<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a "Marketing & Sales" module category and moves CRM and the AI Content Marketing &
 * SEO Suite into it (they previously lived under Operations & Support). Categories and their
 * access are global (not tenant-scoped). Anyone who could see the Operations & Support group
 * keeps visibility of the moved modules by carrying their category access across, so no one
 * loses access. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $categoryId = DB::table('security_categories')->where('name', 'Marketing & Sales')->value('id');
        if (! $categoryId) {
            $categoryId = DB::table('security_categories')->insertGetId([
                'name'        => 'Marketing & Sales',
                'description' => 'Marketing & Sales',
                'icon'        => 'bullhorn',
                'active'      => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // Move CRM + AI Content Marketing & SEO into the new category (by code_name, so it
        // works regardless of module ids in any environment).
        DB::table('security_modules')
            ->whereIn('code_name', ['crm', 'content_marketing'])
            ->update(['category_id' => $categoryId, 'updated_at' => $now]);

        // Carry visibility over: every user who can see Operations & Support (where these
        // modules used to live) also gets access to Marketing & Sales at the same level.
        $opsId = DB::table('security_categories')->where('name', 'Operations & Support')->value('id');
        if ($opsId) {
            $opsAccess = DB::table('security_category_access')->where('category_id', $opsId)->where('active', true)->get();
            foreach ($opsAccess as $row) {
                $exists = DB::table('security_category_access')
                    ->where('category_id', $categoryId)->where('user_id', $row->user_id)->exists();
                if (! $exists) {
                    DB::table('security_category_access')->insert([
                        'user_id'      => $row->user_id,
                        'category_id'  => $categoryId,
                        'access_level' => $row->access_level,
                        'active'       => true,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $categoryId = DB::table('security_categories')->where('name', 'Marketing & Sales')->value('id');
        if (! $categoryId) {
            return;
        }

        // Move the modules back to Operations & Support.
        $opsId = DB::table('security_categories')->where('name', 'Operations & Support')->value('id');
        if ($opsId) {
            DB::table('security_modules')
                ->whereIn('code_name', ['crm', 'content_marketing'])
                ->update(['category_id' => $opsId]);
        }

        DB::table('security_category_access')->where('category_id', $categoryId)->delete();
        DB::table('security_categories')->where('id', $categoryId)->delete();
    }
};
