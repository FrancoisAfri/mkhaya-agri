<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales documents that can carry what a farm (or any producer) sells.
 *
 * Accounting owns customers, price lists, quotes, sales orders, delivery notes, invoices and their
 * disputes; nothing else keeps a copy. To sell produce through them a line needs its grade, unit and
 * where its price came from; an order needs its delivery date and address, what it came from (a
 * supply contract, a harvest commitment) and the ledger dimensions its income lands on; an order line
 * records what the customer accepted and rejected, and what has been invoiced, so an order can be
 * invoiced delivery by delivery. Price lists gain validity dates, a currency and an optional customer,
 * and an entry can price a product by name, grade and unit as well as a catalogue item. A customer
 * carries its sales channel, delivery points and whether someone verified who it is. A dispute is a
 * record of its own, against the invoice and whatever it concerns, resolved with a credit note.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fin_invoices', 'dimensions')) {
            Schema::table('fin_invoices', fn (Blueprint $t) => $t->jsonb('dimensions')->nullable());
        }

        Schema::table('fin_parties', function (Blueprint $t) {
            $t->string('channel', 30)->nullable();                 // the sales channel margins are reported by
            $t->jsonb('delivery_points')->nullable();              // [{name, address}]
            $t->timestamp('verified_at')->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->string('verification_note', 300)->nullable();
        });

        Schema::table('fin_price_lists', function (Blueprint $t) {
            $t->unsignedBigInteger('party_id')->nullable()->index(); // null: the general list
            $t->char('currency', 3)->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
        });
        Schema::table('fin_price_list_items', function (Blueprint $t) {
            $t->unsignedBigInteger('item_id')->nullable()->change();
            $t->string('description', 150)->nullable();           // a product priced by name rather than a catalogue item
            $t->string('grade', 40)->nullable();
            $t->string('unit', 20)->nullable();
        });

        Schema::table('fin_quotes', fn (Blueprint $t) => $t->jsonb('dimensions')->nullable());
        Schema::table('fin_quote_lines', function (Blueprint $t) {
            $t->string('unit', 20)->nullable();
            $t->string('grade', 40)->nullable();
            $t->string('price_basis', 255)->nullable();           // where the price came from
        });

        Schema::table('fin_sales_orders', function (Blueprint $t) {
            $t->jsonb('dimensions')->nullable();
            $t->date('deliver_on')->nullable();
            $t->text('delivery_address')->nullable();
            $t->string('source_type', 40)->nullable();            // e.g. agri_contract, agri_commitment
            $t->unsignedBigInteger('source_id')->nullable();
            $t->index(['source_type', 'source_id']);
        });
        Schema::table('fin_sales_order_lines', function (Blueprint $t) {
            $t->string('unit', 20)->nullable();
            $t->string('grade', 40)->nullable();
            $t->string('price_basis', 255)->nullable();
            $t->decimal('qty_accepted', 14, 4)->default(0);
            $t->decimal('qty_rejected', 14, 4)->default(0);
            $t->decimal('qty_invoiced', 14, 4)->default(0);
        });
        Schema::table('fin_delivery_note_lines', function (Blueprint $t) {
            $t->decimal('qty_accepted', 14, 4)->nullable();        // null until the customer has said
            $t->decimal('qty_rejected', 14, 4)->nullable();
        });
        Schema::table('fin_delivery_notes', fn (Blueprint $t) => $t->unsignedBigInteger('invoice_id')->nullable());

        Schema::create('fin_disputes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->unsignedBigInteger('party_id');
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->string('subject_type', 40)->nullable();           // what it is about beyond the invoice: e.g. agri_dispatch, agri_contract
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('kind', 12);                               // price | quality | quantity | payment | other
            $t->decimal('amount', 18, 2)->nullable();
            $t->string('description', 1000);
            $t->string('status', 10)->default('open');            // open | resolved | rejected
            $t->string('resolution', 1000)->nullable();
            $t->unsignedBigInteger('credit_note_id')->nullable();
            $t->jsonb('dimensions')->nullable();
            $t->unsignedBigInteger('raised_by')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['party_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE fin_disputes ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE fin_disputes ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE fin_disputes FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON fin_disputes');
            DB::statement("CREATE POLICY tenant_isolation ON fin_disputes USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_disputes');
        Schema::table('fin_delivery_notes', fn (Blueprint $t) => $t->dropColumn('invoice_id'));
        Schema::table('fin_delivery_note_lines', fn (Blueprint $t) => $t->dropColumn(['qty_accepted', 'qty_rejected']));
        Schema::table('fin_sales_order_lines', fn (Blueprint $t) => $t->dropColumn(['unit', 'grade', 'price_basis', 'qty_accepted', 'qty_rejected', 'qty_invoiced']));
        Schema::table('fin_sales_orders', function (Blueprint $t) {
            $t->dropIndex(['source_type', 'source_id']);
            $t->dropColumn(['dimensions', 'deliver_on', 'delivery_address', 'source_type', 'source_id']);
        });
        Schema::table('fin_quote_lines', fn (Blueprint $t) => $t->dropColumn(['unit', 'grade', 'price_basis']));
        Schema::table('fin_quotes', fn (Blueprint $t) => $t->dropColumn('dimensions'));
        Schema::table('fin_price_list_items', fn (Blueprint $t) => $t->dropColumn(['description', 'grade', 'unit']));
        Schema::table('fin_price_lists', fn (Blueprint $t) => $t->dropColumn(['party_id', 'currency', 'valid_from', 'valid_to']));
        Schema::table('fin_parties', fn (Blueprint $t) => $t->dropColumn(['channel', 'delivery_points', 'verified_at', 'verified_by', 'verification_note']));
    }
};
