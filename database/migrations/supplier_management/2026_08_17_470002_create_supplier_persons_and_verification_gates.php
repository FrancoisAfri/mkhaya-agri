<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of Supplier Due Diligence: the people, the PIN, and the two gates.
 *
 * `supplier_directors` holds a first name, a surname, a phone number and an email. That is enough to
 * put a name on a form and nowhere near enough to screen anybody: a sanctions or PEP check on "John
 * Smith" with no identifier, nationality or date of birth returns either nothing or everything, and
 * both answers are useless. `supplier_persons` is the screening subject — identifiers, nationality,
 * role, appointment dates, and where each fact came from.
 *
 * Directors are copied across rather than moved. The existing table stays exactly as it is, so every
 * screen and export that reads it keeps working while the new one takes over; nothing is dropped in
 * the same migration that introduces its replacement.
 *
 * Identifiers are encrypted at the application layer (see SupplierPerson's casts) because they are
 * personal information about identifiable people, collected for one stated purpose. The columns are
 * text rather than short strings for exactly that reason — ciphertext is longer than an ID number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_persons', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            // Kept as given, plus a normalised form for matching. Registries write "SMITH, John
            // Andrew" where a form says "John Smith", and screening has to treat those as one person.
            $t->string('first_name', 120)->nullable();
            $t->string('surname', 120)->nullable();
            $t->string('full_name', 250)->nullable();
            $t->string('normalised_name', 250)->nullable()->index();
            $t->json('previous_names')->nullable();

            // Identifiers — encrypted, and optional. A supplier who declines to give a director's ID
            // number gets a thinner screening, not a failed one.
            $t->string('id_type', 20)->nullable();          // sa_id | passport | other
            $t->text('id_number')->nullable();
            $t->char('nationality', 2)->nullable();
            $t->date('date_of_birth')->nullable();

            $t->string('role', 30)->default('director');    // director | member | shareholder | ubo | key_person | trustee
            $t->string('position', 120)->nullable();
            $t->date('appointed_at')->nullable();
            $t->date('resigned_at')->nullable();
            $t->decimal('ownership_percentage', 6, 3)->nullable();

            $t->string('tel', 40)->nullable();
            $t->string('email', 190)->nullable();

            // Where this person came from, which decides what a discrepancy means: a director the
            // registry lists and the supplier did not disclose is a different problem from one the
            // supplier disclosed and the registry has never heard of.
            $t->string('source', 20)->default('supplier');  // supplier | registry | screening | manual
            $t->boolean('is_screening_subject')->default(true);
            $t->text('notes')->nullable();

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'supplier_id', 'role'], 'supplier_persons_role_idx');
        });

        // The supplier's TCS PIN — their authorisation for a third party to check their tax
        // compliance status. Encrypted at the model, and deliberately not a certificate upload:
        // a PDF says what was true the day it was printed.
        Schema::table('suppliers', function (Blueprint $t) {
            $t->text('tcs_pin')->nullable()->after('tax_reference_number');
            $t->date('tcs_pin_expires_at')->nullable()->after('tcs_pin');
        });

        // Two gates, off by default.
        //
        // Defaulting them on would block every activation and every payment the moment this ships,
        // because no customer has a verification source configured yet — every check would answer
        // "unavailable" and the system would grind. The mismatch refusal in BankingGate needs no
        // setting: a bank account whose holder does not match the supplier is stopped regardless.
        Schema::table('supplier_settings', function (Blueprint $t) {
            $t->boolean('require_bank_verification')->default(false)->after('kyc');
            $t->boolean('require_tax_verification')->default(false)->after('require_bank_verification');
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            DB::statement("ALTER TABLE supplier_persons ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE supplier_persons ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE supplier_persons FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON supplier_persons');
            DB::statement("CREATE POLICY tenant_isolation ON supplier_persons USING ({$policy}) WITH CHECK ({$policy})");
        }

        $this->copyExistingDirectors();
    }

    public function down(): void
    {
        Schema::table('supplier_settings', function (Blueprint $t) {
            $t->dropColumn(['require_bank_verification', 'require_tax_verification']);
        });

        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropColumn(['tcs_pin', 'tcs_pin_expires_at']);
        });

        Schema::dropIfExists('supplier_persons');
    }

    /**
     * Copy every existing director across, tenant by tenant.
     *
     * Row-level security is why this is not one INSERT … SELECT: with `app.company_id` unset the
     * policy lets everything through, but the *default* on the new table's company_id would then
     * evaluate to NULL and every copied person would belong to nobody. Carrying the company_id
     * explicitly from the source row is what keeps each person with their own tenant.
     */
    private function copyExistingDirectors(): void
    {
        if (! Schema::hasTable('supplier_directors')) {
            return;
        }

        DB::table('supplier_directors')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(500, function ($directors) {
                $rows = [];

                foreach ($directors as $director) {
                    $first = trim((string) ($director->first_name ?? ''));
                    $last = trim((string) ($director->surname ?? ''));
                    $full = trim("{$first} {$last}");

                    $rows[] = [
                        'uuid' => (string) \Illuminate\Support\Str::uuid(),
                        'company_id' => $director->company_id,
                        'supplier_id' => $director->supplier_id,
                        'first_name' => $first ?: null,
                        'surname' => $last ?: null,
                        'full_name' => $full ?: null,
                        'normalised_name' => $full !== '' ? $this->normalise($full) : null,
                        'role' => 'director',
                        'source' => 'supplier',
                        'is_screening_subject' => true,
                        'tel' => $director->tel ?? null,
                        'email' => $director->email ?? null,
                        'created_at' => $director->created_at ?? now(),
                        'updated_at' => $director->updated_at ?? now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('supplier_persons')->insert($rows);
                }
            });
    }

    /** Same rule as FieldComparator::normalisePersonName — word order must not decide identity. */
    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z\s]/', ' ', $value);
        $parts = array_values(array_filter(
            preg_split('/\s+/', (string) $value) ?: [],
            fn ($p) => $p !== '' && ! in_array($p, ['mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'adv', 'rev', 'jr', 'snr', 'sr', 'ii', 'iii'], true)
        ));
        sort($parts);

        return implode(' ', $parts);
    }
};
