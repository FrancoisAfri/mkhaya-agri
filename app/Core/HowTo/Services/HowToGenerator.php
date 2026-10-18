<?php

namespace App\Core\HowTo\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates and back-fills baseline how-to content so EVERY module + ribbon in the
 * product is answerable by the Mkhaya AI bot (and the /how-to page) out of the box.
 *
 * The baseline is derived from each ribbon's own metadata (name, menu path, module,
 * description) — genuinely useful navigation + purpose text, written to be idempotent.
 * Hand-curated entries (HowToSeeder) and later AI-enriched content sit alongside these
 * and are preferred by the ranking in HowToKnowledgeService; the baseline just
 * guarantees no ribbon is a dead end. Used by both HowToCoverageSeeder (bulk back-fill)
 * and RibbonObserver (auto-add when a new ribbon ships).
 */
class HowToGenerator
{
    /** Back-fill baseline how-tos for every active module + ribbon that lacks coverage. */
    public function ensureAll(): int
    {
        $created = 0;

        $modules = DB::table('security_modules')->get(['id', 'name', 'code_name']);
        foreach ($modules as $module) {
            $ribbons = DB::table('security_module_ribbons')
                ->where('module_id', $module->id)
                ->get(['id', 'ribbon_name', 'ribbon_path', 'description']);

            foreach ($ribbons as $ribbon) {
                $created += $this->ensureForRibbon($module, $ribbon);
            }
        }

        return $created;
    }

    /**
     * Ensure a single ribbon has baseline how-to coverage. Accepts either DB rows or
     * Eloquent models (Modules / Ribbon) so the observer can pass its fresh model.
     *
     * @return int number of how-to rows created
     */
    public function ensureForRibbon(object $module, object $ribbon): int
    {
        // If this ribbon already has ANY how-to, leave it alone (respects curated /
        // enriched content and keeps the call idempotent).
        $exists = DB::table('module_how_tos')
            ->where('module_id', $module->id)
            ->where('ribbon_id', $ribbon->id)
            ->exists();

        if ($exists) {
            return 0;
        }

        $now = now();
        $hasSource = \Illuminate\Support\Facades\Schema::hasColumn('module_how_tos', 'source');
        $rows = array_map(fn ($qa) => array_merge([
            'module_id' => $module->id,
            'ribbon_id' => $ribbon->id,
            'question' => $qa['question'],
            'answer' => $qa['answer'],
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $hasSource ? ['source' => 'baseline'] : []), $this->baselineFor($module, $ribbon));

        DB::table('module_how_tos')->insert($rows);

        return count($rows);
    }

    /** @return array<int, array{question:string, answer:string}> */
    public function baselineFor(object $module, object $ribbon): array
    {
        $feature = trim((string) ($ribbon->ribbon_name ?? 'this feature'));
        $moduleName = trim((string) ($module->name ?? 'the module'));
        $path = trim((string) ($ribbon->ribbon_path ?? ''));
        $desc = trim((string) ($ribbon->description ?? ''));

        // Only use the ribbon description when it adds meaning (it's often just the
        // ribbon name repeated, e.g. "Dashboard").
        $descSentence = ($desc !== '' && strcasecmp($desc, $feature) !== 0)
            ? " {$desc}."
            : '';

        $menuPath = "{$moduleName} → {$feature}";
        $urlHint = $path !== '' ? " (menu path: {$path})" : '';

        return [
            [
                'question' => "How do I use {$feature} in {$moduleName}?",
                'answer' => "Open the {$moduleName} module from the main menu, then choose \"{$feature}\".{$descSentence} "
                    . "Complete the fields shown on the screen and use the action buttons (add, edit, view, or export where available) to work with your records. "
                    . "Changes are saved against your company and follow any approval steps configured for this area. You'll find it under {$menuPath}{$urlHint}.",
            ],
            [
                'question' => "Where do I find {$feature}?",
                'answer' => "{$feature} lives inside the {$moduleName} module. Open {$moduleName} from the sidebar and select \"{$feature}\"{$urlHint}. "
                    . "If you don't see it, your role may not have access yet — ask your administrator to grant you permission to {$moduleName}.",
            ],
            [
                'question' => "What can I do on the {$feature} screen?",
                'answer' => "The {$feature} screen lets you view and manage {$feature} within {$moduleName}.{$descSentence} "
                    . "Depending on your access level you can add new records, edit or view existing ones, and export where that option is shown. "
                    . "Everything you see and change here is limited to your own company's data.",
            ],
        ];
    }
}
