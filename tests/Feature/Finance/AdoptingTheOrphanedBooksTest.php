<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "115 quotes belong to nobody — they appear in EVERY business."
 *
 * Straight off a live install. Documents written before tenancy carry neither a company nor a
 * business, and the scope reads that as "belongs to nobody" and shows them everywhere — which is
 * right for the system's own contra accounts and badly wrong for a client's quote history.
 *
 * Adopting them is only half of it. A number series consults the books once, when it is created,
 * so a business that adopts 115 quotes still has a series pointing at 2 and will hand the next
 * three documents numbers a client has already seen.
 */
class AdoptingTheOrphanedBooksTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private FinEntity $business;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('The command refuses to guess when an install holds several companies.');
        }

        $this->companyId = (int) DB::table('companies')->value('id');
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);

        config(['edition.edition' => 'self_hosted']);

        $this->business = FinEntity::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('is_default', true)->first()
            ?? FinEntity::withoutGlobalScopes()->create([
                'company_id' => $this->companyId,
                'name'       => 'Mkhaya '.Str::random(4),
                'status'     => 'active',
                'is_default' => true,
            ]);
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** A document from before tenancy: no company, no business. */
    private function orphanQuote(string $number): int
    {
        $party = FinParty::withoutGlobalScopes()->create([
            'kind' => 'customer', 'name' => 'Client '.Str::random(5),
            'company_id' => $this->companyId, 'entity_id' => $this->business->id,
        ]);

        return DB::table('fin_quotes')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => null, 'entity_id' => null,
            'party_id' => $party->id, 'number' => $number, 'quote_date' => now()->toDateString(),
            'currency' => 'ZAR', 'status' => 'draft', 'total' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $id = $this->orphanQuote('QUO-000115');

        $this->artisan('finance:adopt-orphaned-documents')->assertSuccessful();

        $this->assertNull(DB::table('fin_quotes')->where('id', $id)->value('company_id'));
    }

    public function test_the_orphans_are_adopted_by_the_default_business(): void
    {
        $id = $this->orphanQuote('QUO-000115');

        $this->artisan('finance:adopt-orphaned-documents --apply')->assertSuccessful();

        $row = DB::table('fin_quotes')->where('id', $id)->first();

        $this->assertSame($this->companyId, (int) $row->company_id);
        $this->assertSame($this->business->id, (int) $row->entity_id);
    }

    public function test_an_adopted_quote_stops_appearing_in_other_businesses(): void
    {
        $id = $this->orphanQuote('QUO-000115');

        $other = FinEntity::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'name'       => 'Mkhaya Move '.Str::random(4),
            'status'     => 'active',
        ]);

        /*
         * As the install sees it: a platform administrator on a self-hosted box, who has no
         * CurrentCompany, so CompanyScope is not filtering and EntityScope's "belongs to nobody"
         * allowance is the only thing left deciding. That is exactly how 115 quotes ended up on
         * every business's screen.
         */
        $asPlatformAdmin = function (int $entityId) use ($id) {
            app(CurrentCompany::class)->clear();

            try {
                return app(CurrentEntity::class)->run($entityId, fn () => FinQuote::whereKey($id)->exists());
            } finally {
                app(CurrentCompany::class)->set($this->companyId);
            }
        };

        $this->assertTrue($asPlatformAdmin($other->id), 'The fixture is not reproducing the reported leak.');

        $this->artisan('finance:adopt-orphaned-documents --apply')->assertSuccessful();

        $this->assertFalse(
            $asPlatformAdmin($other->id),
            'The adopted quote is still on the other business\'s screen.'
        );

        // And it is still on its own business's screen — adoption must not hide it from everyone.
        $this->assertTrue($asPlatformAdmin($this->business->id));
    }

    public function test_the_numbering_catches_up_so_no_number_is_reissued(): void
    {
        $this->orphanQuote('QUO-000115');

        // The install's shape: a series that has only ever counted the one quote raised since
        // businesses were introduced.
        FinNumberSeries::withoutEntityScope()->updateOrCreate(
            ['company_id' => $this->companyId, 'entity_id' => $this->business->id, 'doc_type' => 'quote'],
            ['prefix' => 'QUO-', 'next_number' => 2]
        );

        $this->artisan('finance:adopt-orphaned-documents --apply')->assertSuccessful();

        $this->assertSame(
            'QUO-000116',
            app(CurrentEntity::class)->run(
                $this->business->id,
                fn () => FinNumberSeries::next($this->companyId, 'quote', 'QUO-')
            ),
            'The next quote reuses a number a client has already been sent.'
        );
    }

    public function test_the_systems_own_rows_are_left_where_they_are(): void
    {
        $before = DB::table('fin_account_mappings')
            ->whereNull('company_id')->whereNull('entity_id')->count();

        $this->orphanQuote('QUO-000115');
        $this->artisan('finance:adopt-orphaned-documents --apply')->assertSuccessful();

        // Bad Debts and the other contras belong to nobody on purpose; every business posts to
        // them. Adopting them into one would break posting in all the others.
        $this->assertSame(
            $before,
            DB::table('fin_account_mappings')->whereNull('company_id')->whereNull('entity_id')->count(),
            'The shared account mappings were adopted into one business.'
        );
    }

    public function test_the_ledger_is_reported_but_not_moved(): void
    {
        $journal = DB::table('fin_journals')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => null, 'entity_id' => null,
            'journal_no' => 'JNL-'.Str::upper(Str::random(8)), 'journal_date' => now()->toDateString(),
            'source_module' => 'test', 'currency' => 'ZAR', 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->orphanQuote('QUO-000115');

        $this->artisan('finance:adopt-orphaned-documents --apply')
            ->expectsOutputToContain('EVERY business')
            ->assertSuccessful();

        /*
         * Left where it is, deliberately. Which business a ledger belongs to is not something to
         * infer from a row count, and a journal moved into the wrong books is harder to undo than
         * one left alone — but the operator has to be told, because an unstamped journal is in
         * every business's trial balance.
         */
        $this->assertNull(
            DB::table('fin_journals')->where('id', $journal)->value('entity_id'),
            'The ledger was moved without anybody deciding whose books it is.'
        );
    }
}
