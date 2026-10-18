<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Mkhaya self-promotion targets (Authority Network #15). PLATFORM-GLOBAL config, managed by
 * platform admins — the products/services advertised in network articles, each with its own
 * landing URL and rotating anchors. No tenant column / no RLS (global like plans). Seeded from
 * the former config/authority.php defaults so promotion keeps working out of the box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mkhaya_promo_targets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->index();
            $table->string('type')->default('service');  // product|service
            $table->string('name');
            $table->string('url');
            $table->json('anchors')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $base = env('AUTHORITY_MKHAYA_URL', 'https://mkhayamk.co.za');
        $now = now();
        $rows = [
            ['product', 'Mkhaya Hub', $base, ['all-in-one business platform', 'AI business software']],
            ['service', 'HR & Payroll', rtrim($base, '/') . '/hr', ['HR software', 'payroll and HR software']],
            ['service', 'ERP', rtrim($base, '/') . '/erp', ['ERP platform']],
            ['service', 'CRM', rtrim($base, '/') . '/crm', ['CRM solution']],
            ['service', 'Automation', rtrim($base, '/') . '/automation', ['business automation platform']],
        ];
        foreach ($rows as $i => [$type, $name, $url, $anchors]) {
            DB::table('mkhaya_promo_targets')->insert([
                'uuid' => (string) Str::uuid(), 'type' => $type, 'name' => $name, 'url' => $url,
                'anchors' => json_encode($anchors), 'active' => true, 'sort' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mkhaya_promo_targets');
    }
};
