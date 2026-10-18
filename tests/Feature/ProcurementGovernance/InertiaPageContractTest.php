<?php

namespace Tests\Feature\ProcurementGovernance;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The seam between the controllers and the Vue pages.
 *
 * `Inertia::render('Modules/…/Show')` is a string. Rename or move the component and nothing fails
 * until someone opens the page in production and gets a blank screen — there is no compiler, no
 * import, and no route test that would notice. This walks the controllers, extracts every rendered
 * component name, and checks the file is really there.
 *
 * It runs in both directions: an orphaned page nobody renders is usually a page that was renamed
 * and left behind, which is worth knowing about too.
 */
class InertiaPageContractTest extends TestCase
{
    private const CONTROLLERS = 'app/Modules/Procurement/Governance/Http/Controllers';

    private const PAGES = 'resources/js';

    /** @return list<string> every component name passed to Inertia::render() */
    private function renderedComponents(): array
    {
        $components = [];

        foreach (File::allFiles(base_path(self::CONTROLLERS)) as $file) {
            preg_match_all(
                "/Inertia::render\(\s*'([^']+)'/",
                $file->getContents(),
                $matches,
            );

            foreach ($matches[1] as $component) {
                $components[$component] = true;
            }
        }

        return array_keys($components);
    }

    public function test_every_rendered_page_component_exists(): void
    {
        $components = $this->renderedComponents();

        $this->assertNotEmpty($components, 'No Inertia pages were found — the extraction is broken.');

        foreach ($components as $component) {
            $this->assertFileExists(
                base_path(self::PAGES.'/'.$component.'.vue'),
                "Controller renders '{$component}', which has no matching Vue component.",
            );
        }
    }

    public function test_every_module_page_is_reachable_from_a_controller(): void
    {
        $rendered = $this->renderedComponents();

        $orphans = [];

        foreach (File::allFiles(resource_path('js/Modules/ProcurementGovernance')) as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }

            // Partials are composed into pages rather than rendered by a controller.
            if (str_contains($file->getPathname(), '/Partials/')) {
                continue;
            }

            $component = 'Modules/ProcurementGovernance/'.str_replace(
                ['\\', '.vue'],
                ['/', ''],
                ltrim(str_replace(resource_path('js/Modules/ProcurementGovernance'), '', $file->getPathname()), '/'),
            );

            if (! in_array($component, $rendered, true)) {
                $orphans[] = $component;
            }
        }

        $this->assertSame([], $orphans, 'These pages exist but no controller renders them.');
    }

    public function test_every_page_declares_the_capability_context_it_needs(): void
    {
        /*
         * §12 asks pages to render only the capabilities and integrations a tenant actually has.
         * They can only do that if the controller sends the context, and forgetting it fails open —
         * the page renders a button for a module the tenant does not have, which 403s on click.
         *
         * Evaluator-facing pages are exempt: an appointed committee member reaches their workspace
         * without any module-configuration surface at all.
         *
         * The bidder's portal is exempt for a stronger reason than convenience. It is served to
         * someone outside the organisation holding nothing but an invitation token, and the
         * capability context describes which modules the *tenant* has bought and switched on.
         * Sending it would tell every bidder about the buyer's internal configuration in exchange
         * for nothing — the page has no integration actions to gate, because it offers a bidder
         * exactly one action.
         */
        $exempt = ['Evaluations/Workspace', 'Evaluations/Review', 'Portal/Bid'];

        foreach (File::allFiles(base_path(self::CONTROLLERS)) as $file) {
            $source = $file->getContents();

            preg_match_all(
                "/Inertia::render\(\s*'([^']+)'(.*?)(?=Inertia::render\(|\z)/s",
                $source,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [, $component, $payload]) {
                if (! str_starts_with($component, 'Modules/ProcurementGovernance/')) {
                    continue;
                }

                $short = str_replace('Modules/ProcurementGovernance/', '', $component);

                if (in_array($short, $exempt, true)) {
                    continue;
                }

                $this->assertStringContainsString(
                    'context',
                    $payload,
                    "{$component} is rendered without the capability context, so it cannot gate "
                    .'integration actions correctly.',
                );
            }
        }
    }
}
