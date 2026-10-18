<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Console\InstallInspectionTemplatesCommand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The standard inspection template library.
 *
 * The content is DRAFT pending practitioner sign-off, so these tests assert the things that must
 * hold whatever the questions end up saying: the library parses, every question has a stable id,
 * installing twice does not churn versions, and the templates are shared rather than owned by
 * whichever tenant happened to run the installer.
 */
class TemplateLibraryTest extends TestCase
{
    use DatabaseTransactions;

    private function library(): array
    {
        return Yaml::parseFile(resource_path('inspection-templates/library.yaml'))['templates'];
    }

    public function test_the_library_parses_and_covers_more_than_one_industry(): void
    {
        $templates = $this->library();

        $this->assertGreaterThanOrEqual(15, count($templates));

        $industries = array_unique(array_column($templates, 'industry'));
        $this->assertGreaterThanOrEqual(8, count($industries),
            'A library that only covers one sector is not a standard library.');
        $this->assertContains('MINING', $industries);
        $this->assertContains('ALL', $industries);
    }

    public function test_every_template_declares_its_legal_basis(): void
    {
        /**
         * The citation is what makes practitioner review possible. A checklist question with no
         * stated source cannot be checked against the regulation it claims to implement.
         */
        foreach ($this->library() as $template) {
            $this->assertNotEmpty($template['basis'] ?? null,
                "{$template['code']} does not say what it is based on.");
        }
    }

    public function test_every_template_has_questions_and_a_code_that_will_not_collide(): void
    {
        $codes = [];

        foreach ($this->library() as $template) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9-]+$/', $template['code']);
            $this->assertNotContains($template['code'], $codes, "Duplicate code {$template['code']}.");
            $codes[] = $template['code'];

            $questions = 0;

            foreach ($template['sections'] as $section) {
                $this->assertNotEmpty($section['title']);
                $questions += count($section['questions'] ?? []);

                foreach ($section['questions'] as $question) {
                    $this->assertNotEmpty($question['text']);
                    $this->assertContains($question['type'],
                        ['PASS_FAIL', 'YES_NO', 'SCALE', 'NUMBER', 'TEXT'],
                        "{$template['code']} uses an unsupported question type.");
                }
            }

            $this->assertGreaterThan(0, $questions, "{$template['code']} asks nothing.");
        }
    }

    public function test_life_safety_templates_mark_their_killers_critical(): void
    {
        /**
         * A critical failure fails the whole inspection regardless of score. If fire escape and
         * fall protection are not marked critical, a site can pass at 90% with a blocked fire exit,
         * which is precisely the outcome scoring must not permit.
         */
        $byCode = collect($this->library())->keyBy('code');

        foreach (['GEN-FIRE', 'GEN-HEIGHT', 'CON-SCAFFOLD', 'MIN-UG-DAILY'] as $code) {
            $critical = 0;

            foreach ($byCode[$code]['sections'] as $section) {
                foreach ($section['questions'] as $question) {
                    $critical += empty($question['critical']) ? 0 : 1;
                }
            }

            $this->assertGreaterThan(0, $critical, "{$code} marks nothing as critical.");
        }
    }

    public function test_installing_publishes_shared_templates_not_tenant_owned_ones(): void
    {
        // A shared template belongs to everybody; one written against the installer's tenant would
        // be invisible to every other customer.
        $this->artisan(InstallInspectionTemplatesCommand::class)->assertSuccessful();

        $shared = DB::table('sheq_inspection_templates')
            ->whereNull('company_id')->where('code', 'GEN-FIRE')->first();

        $this->assertNotNull($shared);
        $this->assertNull($shared->company_id);
        $this->assertSame('PUBLISHED', $shared->status);
    }

    public function test_question_ids_are_stable_across_reinstalls(): void
    {
        /**
         * Ids key the recorded answers. If a re-install renumbered them, every answer already
         * captured would point at a different question — quietly, and unrecoverably.
         */
        $this->artisan(InstallInspectionTemplatesCommand::class)->assertSuccessful();

        $first = json_decode(DB::table('sheq_inspection_templates')
            ->whereNull('company_id')->where('code', 'GEN-FIRE')
            ->orderByDesc('version')->value('schema'), true);

        $this->artisan(InstallInspectionTemplatesCommand::class)->assertSuccessful();

        $second = json_decode(DB::table('sheq_inspection_templates')
            ->whereNull('company_id')->where('code', 'GEN-FIRE')
            ->orderByDesc('version')->value('schema'), true);

        $this->assertSame(
            array_column($first['sections'][0]['questions'], 'id'),
            array_column($second['sections'][0]['questions'], 'id')
        );
    }

    public function test_reinstalling_unchanged_templates_does_not_burn_a_version(): void
    {
        $this->artisan(InstallInspectionTemplatesCommand::class)->assertSuccessful();

        $version = DB::table('sheq_inspection_templates')
            ->whereNull('company_id')->where('code', 'GEN-FIRE')->max('version');

        $this->artisan(InstallInspectionTemplatesCommand::class)->assertSuccessful();

        $this->assertSame($version, DB::table('sheq_inspection_templates')
            ->whereNull('company_id')->where('code', 'GEN-FIRE')->max('version'),
            'Re-installing an unchanged template retired it and published an identical copy.');
    }

    public function test_a_single_industry_can_be_installed_on_its_own(): void
    {
        $this->artisan(InstallInspectionTemplatesCommand::class, ['--industry' => 'MINING'])
            ->assertSuccessful();

        $this->assertNotNull(DB::table('sheq_inspection_templates')
            ->whereNull('company_id')->where('code', 'MIN-UG-DAILY')->first());
    }
}
