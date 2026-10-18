<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spare parts / MRO (Maintenance-Repair-Operations) inventory tables for the
 * Asset Management module.
 *
 * These are NEW tables created after the Postgres RLS blanket migration, so they
 * are NOT covered by row-level security. Tenant isolation is enforced at the app
 * layer via the BelongsToCompany trait on the models (company_id column +
 * CompanyScope global scope). Each table carries a nullable, indexed company_id
 * so the scope/auto-stamp can operate.
 *
 * `location_id` / `supplier_id` reference the existing asset_locations /
 * asset_suppliers lookups by nullable id (no hard FK — loose coupling).
 * `work_order_id` on a stock transaction is an optional, nullable reference to a
 * parallel work-order feature — kept as a plain nullable unsignedBigInteger with
 * no FK/import by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_parts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('part_number')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('unit_of_measure')->default('each');
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('quantity_on_hand', 12, 2)->default(0);
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->decimal('reorder_quantity', 12, 2)->default(0);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            // active | inactive | discontinued
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_part_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('part_id')->index();
            // receipt | issue | adjustment
            $table->string('type');
            // Movement magnitude (positive). Direction is implied by `type`.
            $table->decimal('quantity', 12, 2);
            // Resulting quantity_on_hand snapshot after this movement.
            $table->decimal('balance_after', 12, 2);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->unsignedBigInteger('work_order_id')->nullable()->index();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_part_transactions');
        Schema::dropIfExists('asset_parts');
    }
};
