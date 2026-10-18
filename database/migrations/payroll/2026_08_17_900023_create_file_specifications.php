<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * File layouts as data, not as code.
 *
 * Every outbound file this module produces — the ACB bank file, the SARS e@syFile import, and the
 * Q Link and PIPEDEL deduction files still to come — is a fixed-width or delimited layout defined by
 * somebody else, revised on their schedule, and different for each version they publish. Written as
 * PHP that concatenates `str_pad()` calls, which is how the ACB file is written today, each of those
 * revisions is a code change, a deploy, and a regression risk on a file that moves people's salaries.
 *
 * So a layout is a row: fields with positions, lengths, padding and formats, versioned by the date
 * it takes effect. Changing a format becomes data entry by somebody who has the vendor's document in
 * front of them, and last year's version stays intact for reprinting last year's file — which
 * matters, because a bank or SARS asked to re-accept a file wants it in the format that was current
 * when the period was.
 *
 * A null company_id is a layout shipped with the product and shared by every tenant. A tenant that
 * needs a variant copies it rather than editing the shared one, so one client's bank quirk cannot
 * change everybody else's file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_specifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Null means the shipped library, shared by every tenant. Paired with the null allowance
            // on the policy below — the scope decides what Eloquent asks for, the policy decides what
            // Postgres returns, and both are needed.
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // What this file is: 'qlink_deduction', 'acb_eft', 'pipedel', 'easyfile'. Free text
            // rather than an enum, because the next integration must not need a migration.
            $table->string('code', 60);
            $table->string('name');
            $table->string('vendor', 60)->nullable();       // Q Link, BankservAfrica, SARS
            $table->unsignedSmallInteger('version')->default(1);

            // When this version applies. A file for March 2026 is built with the layout that was
            // current in March 2026, not with whatever is current today.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->string('format', 20)->default('fixed');  // fixed | delimited
            $table->string('delimiter', 4)->nullable();
            $table->string('line_ending', 8)->default('crlf');
            $table->string('encoding', 20)->default('ASCII');
            $table->boolean('pad_to_length')->default(true); // fixed only: pad the whole line out

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();               // where the layout came from, and which
                                                             // page of the vendor's document

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['code', 'effective_from']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('file_specification_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('file_specification_id')->constrained('file_specifications')->cascadeOnDelete();

            // Which line of the file this belongs to. Most vendor layouts are a header, a repeating
            // detail line, and a trailer that has to add up.
            $table->string('record_type', 20)->default('detail'); // header | detail | trailer
            $table->unsignedSmallInteger('sequence');

            $table->string('name');
            $table->string('label')->nullable();

            // Where the value comes from, as a token the renderer resolves — 'employee.account_number',
            // 'run.total_net', 'literal:0000', 'count:detail', 'sum:net_pay', 'today'. A token rather
            // than an expression on purpose: a layout editor is not a place to run arbitrary code
            // typed by whoever last spoke to the vendor.
            $table->string('source');

            // Fixed-width placement. Position is 1-based, the way vendor documents number it, because
            // a person transcribing a spec should not have to subtract one on every line.
            $table->unsignedSmallInteger('position')->nullable();
            $table->unsignedSmallInteger('length')->nullable();

            $table->string('type', 20)->default('text');   // text | number | cents | date
            // Digits by default. An amount in one of these files is written in whole cents by the
            // `cents` type, so a `number` here is an account, a branch or a count — and defaulting
            // it to two decimals writes "62123456.00" into a field the bank reads as an account.
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->string('date_format', 20)->nullable();  // as PHP formats them: Ymd, dmY

            // Both left null unless the layout says otherwise, so the model's convention applies:
            // numbers zero-filled from the left, text space-filled to the right. A default here
            // would win over that and silently right-align every name.
            $table->string('pad_char', 2)->nullable();
            $table->string('pad_side', 6)->nullable();
            $table->boolean('uppercase')->default(false);
            $table->boolean('required')->default(false);    // refuse the file if this comes out empty
            $table->string('default_value')->nullable();

            $table->timestamps();

            $table->unique(['file_specification_id', 'record_type', 'sequence'], 'file_spec_fields_order_unique');
        });

        $this->applyTenantIsolation(['file_specifications', 'file_specification_fields']);
    }

    /**
     * Row-level security that also lets the shared library through.
     *
     * Without the null allowance the shipped layouts install cleanly and then show nothing at all,
     * because Postgres refuses every row whose company_id does not match the session.
     */
    private function applyTenantIsolation(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "company_id IS NULL OR {$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_specification_fields');
        Schema::dropIfExists('file_specifications');
    }
};
