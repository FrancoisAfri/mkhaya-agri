<?php

namespace Tests\Feature\HR\Payroll;

use App\Modules\HR\Payroll\Models\FileSpecification;
use App\Modules\HR\Payroll\Models\FileSpecificationField;
use App\Modules\HR\Payroll\Services\FileSpecificationRenderer;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * File layouts held as data rather than as code.
 *
 * Every outbound file this module produces is a layout defined by somebody else and revised on their
 * schedule. Written as PHP that concatenates str_pad() calls — which is how the ACB bank file is
 * written today — each revision is a code change and a deploy on a file that moves salaries, usually
 * at short notice and usually in the week payroll is due.
 */
class FileSpecificationTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

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
    }

    private function spec(array $overrides = []): FileSpecification
    {
        return FileSpecification::create(array_merge([
            'code' => 'test_' . Str::random(6), 'name' => 'Test layout',
            'version' => 1, 'effective_from' => '2026-01-01',
            'format' => FileSpecification::FORMAT_FIXED,
            'company_id' => $this->companyId,
        ], $overrides));
    }

    private function field(FileSpecification $spec, array $attributes): FileSpecificationField
    {
        static $sequence = 0;

        return $spec->fields()->create(array_merge([
            'record_type' => FileSpecification::DETAIL,
            'sequence' => ++$sequence,
            'company_id' => $this->companyId,
        ], $attributes));
    }

    private function renderer(): FileSpecificationRenderer
    {
        return app(FileSpecificationRenderer::class);
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function test_a_fixed_width_line_lands_on_the_positions_the_vendor_gave(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'type', 'source' => 'literal:10', 'position' => 1, 'length' => 2]);
        $this->field($spec, ['name' => 'account', 'source' => 'row.account', 'position' => 3, 'length' => 11, 'type' => 'number']);
        $this->field($spec, ['name' => 'name', 'source' => 'row.name', 'position' => 14, 'length' => 10]);

        $file = $this->renderer()->render($spec->fresh(), [], [
            ['row' => ['account' => '62123456', 'name' => 'THANDI']],
        ]);

        // Numbers zero-filled from the left, text space-filled to the right: the banking convention,
        // and getting it the other way round produces a file that looks right and is rejected.
        $this->assertSame("1000062123456THANDI    \r\n", $file);
    }

    public function test_an_amount_is_written_in_whole_cents(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'amount', 'source' => 'row.net', 'position' => 1, 'length' => 12, 'type' => 'cents']);

        $file = $this->renderer()->render($spec->fresh(), [], [['row' => ['net' => 12345.67]]]);

        $this->assertSame("000001234567\r\n", $file);
    }

    public function test_a_date_is_written_in_the_format_the_layout_asks_for(): void
    {
        $spec = $this->spec();
        $this->field($spec, [
            'name' => 'action_date', 'source' => 'run.pay_date', 'position' => 1, 'length' => 8,
            'type' => 'date', 'date_format' => 'Ymd',
        ]);

        $file = $this->renderer()->render($spec->fresh(), ['run' => ['pay_date' => '2026-03-25']], [[]]);

        $this->assertSame("20260325\r\n", $file);
    }

    public function test_a_header_and_a_trailer_that_adds_up(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['record_type' => 'header', 'name' => 'label', 'source' => 'literal:HDR', 'position' => 1, 'length' => 3]);
        $this->field($spec, ['record_type' => 'detail', 'name' => 'amount', 'source' => 'row.net', 'position' => 1, 'length' => 8, 'type' => 'cents']);
        $this->field($spec, ['record_type' => 'trailer', 'name' => 'label', 'source' => 'literal:TRL', 'position' => 1, 'length' => 3]);
        $this->field($spec, ['record_type' => 'trailer', 'name' => 'count', 'source' => 'count', 'position' => 4, 'length' => 4, 'type' => 'number', 'decimals' => 0]);
        $this->field($spec, ['record_type' => 'trailer', 'name' => 'total', 'source' => 'sum:row.net', 'position' => 8, 'length' => 10, 'type' => 'cents']);

        $file = $this->renderer()->render($spec->fresh(), [], [
            ['row' => ['net' => 100.00]],
            ['row' => ['net' => 250.50]],
        ]);

        // The trailer is what the bank reconciles against. If it does not equal the detail lines the
        // whole batch is rejected, so it is computed from the same rows rather than passed in.
        $this->assertSame(
            "HDR\r\n00010000\r\n00025050\r\nTRL00020000035050\r\n",
            $file,
        );
    }

    public function test_a_delimited_layout_writes_columns(): void
    {
        $spec = $this->spec(['format' => FileSpecification::FORMAT_DELIMITED, 'delimiter' => ',']);
        $this->field($spec, ['name' => 'number', 'source' => 'row.number', 'sequence' => 1]);
        $this->field($spec, ['name' => 'name', 'source' => 'row.name', 'sequence' => 2]);
        // A CSV for a client's own accounts, so decimals are wanted here — they are opt-in, because
        // a `number` in a bank file is an account or a branch and two decimals would corrupt it.
        $this->field($spec, ['name' => 'amount', 'source' => 'row.net', 'sequence' => 3, 'type' => 'number', 'decimals' => 2]);

        $file = $this->renderer()->render($spec->fresh(), [], [
            ['row' => ['number' => 'EMP0001', 'name' => 'Thandi', 'net' => 9500]],
        ]);

        $this->assertSame("EMP0001,Thandi,9500.00\r\n", $file);
    }

    public function test_an_overlong_amount_keeps_its_last_digits_and_a_long_name_its_first(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'amount', 'source' => 'row.net', 'position' => 1, 'length' => 4, 'type' => 'number', 'decimals' => 0]);
        $this->field($spec, ['name' => 'name', 'source' => 'row.name', 'position' => 5, 'length' => 4]);

        $file = $this->renderer()->render($spec->fresh(), [], [['row' => ['net' => 1234567, 'name' => 'Nomvula']]]);

        // Losing the *leading* digits of an amount changes it by orders of magnitude; a name cut
        // short is still recognisably the person.
        $this->assertSame("4567Nomv\r\n", $file);
    }

    public function test_characters_a_mainframe_cannot_read_are_stripped(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'name', 'source' => 'row.name', 'position' => 1, 'length' => 10, 'uppercase' => true]);

        $file = $this->renderer()->render($spec->fresh(), [], [['row' => ['name' => 'José Müller']]]);

        // These files are read by systems that answer an accented character with a rejected batch,
        // and a name with an accent in it is not worth a failed payment run.
        $this->assertSame("JOS MLLER \r\n", $file);
    }

    public function test_a_missing_value_falls_back_to_the_layouts_default(): void
    {
        $spec = $this->spec();
        $this->field($spec, [
            'name' => 'branch', 'source' => 'row.branch', 'position' => 1, 'length' => 6,
            'default_value' => '250655',
        ]);

        $file = $this->renderer()->render($spec->fresh(), [], [['row' => ['branch' => null]]]);

        $this->assertSame("250655\r\n", $file);
    }

    // ── Refusing a file that would be wrong ──────────────────────────────────

    public function test_building_refuses_when_a_required_field_is_empty(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'account', 'source' => 'row.account', 'position' => 1, 'length' => 11, 'required' => true]);

        $this->expectException(ValidationException::class);
        $this->renderer()->build($spec->fresh(), [], [['row' => ['account' => null]]]);
    }

    public function test_building_names_the_line_the_missing_value_is_on(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'account', 'source' => 'row.account', 'position' => 1, 'length' => 11, 'required' => true]);

        try {
            $this->renderer()->build($spec->fresh(), [], [
                ['row' => ['account' => '62123456789']],
                ['row' => ['account' => null]],
            ]);
            $this->fail('A file with a missing account number was built.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('line 2', $e->errors()['file'][0]);
        }
    }

    // ── Checking a transcribed layout ────────────────────────────────────────

    public function test_a_gap_between_fields_is_reported(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'a', 'source' => 'literal:X', 'position' => 1, 'length' => 2]);
        $this->field($spec, ['name' => 'b', 'source' => 'literal:Y', 'position' => 6, 'length' => 2]);

        $problems = $this->renderer()->validateSpecification($spec->fresh());

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('3 unclaimed character(s)', $problems[0]);
    }

    public function test_fields_that_overlap_are_reported(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'a', 'source' => 'literal:X', 'position' => 1, 'length' => 5]);
        $this->field($spec, ['name' => 'b', 'source' => 'literal:Y', 'position' => 3, 'length' => 5]);

        // The two ways a transcribed fixed-width spec goes wrong, and both produce a file that is
        // either rejected or parsed with every field shifted.
        $this->assertStringContainsString(
            "inside the field before it",
            implode(' ', $this->renderer()->validateSpecification($spec->fresh())),
        );
    }

    public function test_a_date_field_with_no_format_is_reported(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'd', 'source' => 'today', 'position' => 1, 'length' => 8, 'type' => 'date', 'date_format' => null]);

        $this->assertStringContainsString('no date format', implode(' ', $this->renderer()->validateSpecification($spec->fresh())));
    }

    public function test_a_sound_layout_reports_nothing(): void
    {
        $spec = $this->spec();
        $this->field($spec, ['name' => 'a', 'source' => 'literal:X', 'position' => 1, 'length' => 2]);
        $this->field($spec, ['name' => 'b', 'source' => 'row.name', 'position' => 3, 'length' => 8]);

        $this->assertSame([], $this->renderer()->validateSpecification($spec->fresh()));
    }

    // ── Versions ─────────────────────────────────────────────────────────────

    public function test_the_layout_in_force_on_the_files_date_is_the_one_used(): void
    {
        $code = 'versioned_' . Str::random(6);
        $this->spec(['code' => $code, 'version' => 1, 'effective_from' => '2025-03-01', 'effective_to' => '2026-02-28', 'name' => 'Old']);
        $this->spec(['code' => $code, 'version' => 2, 'effective_from' => '2026-03-01', 'name' => 'New']);

        // A bank asked to re-accept a file for an old period wants it in the format that was current
        // when the period was.
        $this->assertSame('Old', FileSpecification::resolve($code, '2025-08-01')?->name);
        $this->assertSame('New', FileSpecification::resolve($code, '2026-08-01')?->name);
    }

    public function test_a_tenants_own_layout_wins_over_the_shipped_one(): void
    {
        $code = 'shared_' . Str::random(6);

        // The shipped library: a null company, shared by every tenant.
        $shared = $this->spec(['code' => $code, 'name' => 'Shipped']);
        $shared->forceFill(['company_id' => null])->save();

        $this->spec(['code' => $code, 'name' => 'Ours', 'version' => 2]);

        // A client with a bank that wants an extra reference field gets theirs, and nobody else's
        // file changes.
        $this->assertSame('Ours', FileSpecification::resolve($code, '2026-06-01')?->name);
    }

    public function test_a_shipped_layout_is_visible_when_the_tenant_has_none(): void
    {
        $code = 'shipped_' . Str::random(6);
        $shared = $this->spec(['code' => $code, 'name' => 'Shipped only']);
        $shared->forceFill(['company_id' => null])->save();

        // Both the scope and the row-level security policy have to allow a null company, or the
        // library installs cleanly and shows nothing at all.
        $this->assertSame('Shipped only', FileSpecification::resolve($code, '2026-06-01')?->name);
    }

    public function test_an_inactive_layout_is_never_chosen(): void
    {
        $code = 'inactive_' . Str::random(6);
        $this->spec(['code' => $code, 'is_active' => false]);

        $this->assertNull(FileSpecification::resolve($code, '2026-06-01'));
    }

    // ── The screen somebody transcribes a layout on ──────────────────────────

    public function test_the_editor_shows_the_line_the_layout_produces(): void
    {
        $this->actingAsAdministrator();

        $spec = $this->spec();
        $this->field($spec, ['name' => 'number', 'source' => 'employee.number', 'position' => 1, 'length' => 8]);
        $this->field($spec, ['name' => 'amount', 'source' => 'employee.net_pay', 'position' => 9, 'length' => 10, 'type' => 'cents']);

        $this->get(route('payroll.file-specs.show', $spec->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/HR/Payroll/FileSpecifications/Edit')
                // Built from sample data on purpose: a layout is transcribed before the first file
                // is ever produced, and a preview needing live payroll is one nobody sees in time.
                ->where('preview', "EMP0001 0001500000\r\nEMP0002 0001250000\r\n")
                ->where('problems', []));
    }

    public function test_a_new_version_copies_the_fields_and_closes_the_old_one(): void
    {
        $this->actingAsAdministrator();

        $code = 'revised_' . Str::random(6);
        $spec = $this->spec(['code' => $code, 'effective_from' => '2025-03-01']);
        $this->field($spec, ['name' => 'a', 'source' => 'literal:X', 'position' => 1, 'length' => 2]);

        $this->post(route('payroll.file-specs.version', $spec->uuid), ['effective_from' => '2026-03-01'])
            ->assertRedirect();

        $next = FileSpecification::where('code', $code)->orderByDesc('version')->first();

        $this->assertSame(2, $next->version);
        $this->assertCount(1, $next->fields);
        // No date may be covered by two versions at once, or "which layout applies" becomes a
        // matter of sort order.
        $this->assertSame('2026-02-28', $spec->fresh()->effective_to?->toDateString());
    }

    public function test_a_shipped_layout_cannot_be_edited_here(): void
    {
        $this->actingAsAdministrator();

        $spec = $this->spec();
        $spec->forceFill(['company_id' => null])->save();

        // Editing it would change every tenant's file. The answer is to copy it.
        $this->post(route('payroll.file-specs.fields.store', $spec->uuid), [
            'record_type' => 'detail', 'name' => 'sneaky', 'source' => 'literal:X',
        ])->assertForbidden();
    }

    private function actingAsAdministrator(): void
    {
        $user = \App\Core\User\Models\User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'spec+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => $this->companyId,
        ]);

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'payroll'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Payroll', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();

        $this->actingAs($user);
    }
}
