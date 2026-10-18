<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed-asset register (Phase 5.1). Acquisition, depreciation and disposal all
 * post through the shared ledger. Supports a parent/child hierarchy (components),
 * straight-line and reducing-balance methods, single (financial) book.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_assets', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();       // component of another asset
            $t->string('tag', 60)->nullable();                     // asset code / barcode
            $t->string('name');
            $t->string('category', 80)->nullable();
            $t->unsignedBigInteger('asset_account_id');            // cost (CoA)
            $t->unsignedBigInteger('accum_account_id');            // accumulated depreciation (contra)
            $t->unsignedBigInteger('expense_account_id');          // depreciation expense (P&L)
            $t->date('acquisition_date');
            $t->decimal('cost', 18, 2);
            $t->decimal('residual_value', 18, 2)->default(0);
            $t->integer('useful_life_months')->default(60);
            $t->string('method', 20)->default('straight_line');    // straight_line | reducing_balance
            $t->decimal('annual_rate', 6, 3)->nullable();          // reducing-balance % per year
            $t->decimal('accumulated_depreciation', 18, 2)->default(0);
            $t->date('last_depreciated_on')->nullable();
            $t->string('status', 12)->default('active');           // active | disposed | written_off
            $t->date('disposal_date')->nullable();
            $t->decimal('disposal_proceeds', 18, 2)->nullable();
            $t->unsignedBigInteger('acquisition_journal_id')->nullable();
            $t->unsignedBigInteger('disposal_journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['status', 'company_id']);
            $t->index('parent_id');
        });

        Schema::create('fin_asset_depreciation', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('asset_id');
            $t->date('period_date');                               // month-end depreciated to
            $t->decimal('amount', 18, 2);
            $t->decimal('book_value_after', 18, 2);
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['asset_id', 'period_date']);               // never depreciate a month twice
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_asset_depreciation');
        Schema::dropIfExists('fin_assets');
    }
};
