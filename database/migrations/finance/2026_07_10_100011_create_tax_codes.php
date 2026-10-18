<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tax registry (Phase 5.4). Jurisdictional tax codes (VAT, WHT…) with a rate and
 * the control account they post to. VAT returns are computed from the GL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_tax_codes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('code', 30);
            $t->string('name', 120);
            $t->decimal('rate', 6, 3)->default(0);            // percent
            $t->string('type', 12)->default('output');        // output | input | none | wht
            $t->string('jurisdiction', 40)->default('ZA');
            $t->unsignedBigInteger('account_id')->nullable(); // VAT control account
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });

        // Seed the standard South-African VAT codes.
        $now = now();
        foreach ([
            ['STD',  'Standard-rated (15%)', 15, 'output'],
            ['STDI', 'Standard-rated purchases (15%)', 15, 'input'],
            ['ZR',   'Zero-rated', 0, 'output'],
            ['EX',   'Exempt', 0, 'none'],
            ['WHT',  'Withholding tax', 0, 'wht'],
        ] as [$code, $name, $rate, $type]) {
            DB::table('fin_tax_codes')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => null, 'code' => $code, 'name' => $name,
                'rate' => $rate, 'type' => $type, 'jurisdiction' => 'ZA', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_tax_codes');
    }
};
