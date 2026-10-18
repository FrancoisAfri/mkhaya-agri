<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "When i edit mkhaya mk it will also edit mkhaya move, and the same the other way round."
 *
 * Reproduced from a live install: the default business had no settings row of its own and no
 * pre-businesses row either, while the second business had one. Resolution ended on a lookup for
 * "any settings row of this company", took the first, and handed the default business the second
 * business's letterhead. Both screens then edited one row — the diagnostic showed it plainly, a
 * row filed under Mkhaya Move carrying the name MKHAYA MK (PTY) LTD.
 *
 * A row that names a business belongs to that business and is never the answer for another one.
 */
class LetterheadsDoNotBleedTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private FinEntity $mk;

    private FinEntity $move;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        // The install's shape: no settings anywhere for this company yet.
        FinSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->delete();

        $this->mk = FinEntity::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('is_default', true)->first()
            ?? $this->business('Mkhaya', true);

        $this->move = $this->business('Mkhaya Move', false);
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function business(string $name, bool $default): FinEntity
    {
        return FinEntity::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'name'       => $name.' '.Str::random(4),
            'status'     => 'active',
            'is_default' => $default,
        ]);
    }

    private function saveLetterhead(FinEntity $business, string $name): void
    {
        app(CurrentEntity::class)->run($business->id, function () use ($name) {
            FinSetting::current()->fill(['company_name' => $name])->save();
        });
    }

    private function letterheadOf(FinEntity $business): ?string
    {
        return app(CurrentEntity::class)->run(
            $business->id,
            fn () => FinSetting::current()->company_name
        );
    }

    public function test_the_second_business_saving_first_does_not_claim_the_defaults_letterhead(): void
    {
        // The order the install fell into: Mkhaya Move was set up first, so it owned the only row.
        $this->saveLetterhead($this->move, 'MKHAYA MOVE (PTY) LTD');

        $this->assertNull(
            $this->letterheadOf($this->mk),
            'The default business opened another business\'s letterhead, so saving would edit theirs.'
        );
    }

    public function test_editing_one_business_does_not_edit_the_other(): void
    {
        $this->saveLetterhead($this->move, 'MKHAYA MOVE (PTY) LTD');
        $this->saveLetterhead($this->mk, 'MKHAYA MK (PTY) LTD');

        $this->assertSame('MKHAYA MK (PTY) LTD', $this->letterheadOf($this->mk));
        $this->assertSame('MKHAYA MOVE (PTY) LTD', $this->letterheadOf($this->move));
    }

    public function test_it_holds_in_the_other_order_too(): void
    {
        $this->saveLetterhead($this->mk, 'MKHAYA MK (PTY) LTD');
        $this->saveLetterhead($this->move, 'MKHAYA MOVE (PTY) LTD');

        $this->assertSame('MKHAYA MK (PTY) LTD', $this->letterheadOf($this->mk));
        $this->assertSame('MKHAYA MOVE (PTY) LTD', $this->letterheadOf($this->move));
    }

    public function test_each_business_ends_up_with_a_row_of_its_own(): void
    {
        $this->saveLetterhead($this->move, 'MKHAYA MOVE (PTY) LTD');
        $this->saveLetterhead($this->mk, 'MKHAYA MK (PTY) LTD');

        foreach ([$this->mk, $this->move] as $business) {
            $this->assertSame(
                1,
                FinSetting::withoutGlobalScopes()
                    ->where('company_id', $this->companyId)->where('entity_id', $business->id)->count(),
                "{$business->name} does not have exactly one settings row."
            );
        }
    }

    public function test_the_pre_businesses_row_is_still_adopted_by_the_default(): void
    {
        // An install whose letterhead predates businesses must keep reading it, and the default
        // business is the tenant as it was — so that row is its row.
        FinSetting::withoutGlobalScopes()->create([
            'company_id'   => $this->companyId,
            'entity_id'    => null,
            'company_name' => 'THE OLD LETTERHEAD',
        ]);

        $this->assertSame('THE OLD LETTERHEAD', $this->letterheadOf($this->mk));

        // And it is not the second business's, which starts blank rather than inheriting a VAT
        // number that is not its own.
        $this->assertNull($this->letterheadOf($this->move));
    }
}
