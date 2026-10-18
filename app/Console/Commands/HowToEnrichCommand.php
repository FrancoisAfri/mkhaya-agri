<?php

namespace App\Console\Commands;

use App\Core\AI\Services\AIChatProviderChain;
use App\Support\Hubs\HubCardCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Enriches the how-to knowledge base into rich, descriptive step-by-step guides using
 * the FREE-first AI provider chain (config/ai.php → 'chat'), so the one-time generation
 * costs ~nothing and the resulting content then answers users' "how do I…" questions
 * with ZERO AI tokens (HowToKnowledgeService serves it straight from the DB).
 *
 * Only rows with source='baseline' are replaced — hand-curated ('curated') and already
 * AI-enriched ('ai') content is left untouched, so the command is safe to re-run and
 * resumable (each ribbon flips to 'ai' once done).
 *
 * Usage:
 *   php artisan howto:enrich                 # enrich all baseline ribbons
 *   php artisan howto:enrich --module=leave  # limit to modules matching a name/code
 *   php artisan howto:enrich --limit=50      # cap how many ribbons this run
 *   php artisan howto:enrich --force         # re-enrich even ribbons already 'ai'
 */
class HowToEnrichCommand extends Command
{
    protected $signature = 'howto:enrich
        {--module= : Only ribbons whose module name/code_name matches this}
        {--limit=0 : Max ribbons to enrich this run (0 = no cap)}
        {--sleep=0 : Seconds to pause between ribbons (throttle free-tier rate limits)}
        {--force : Re-enrich ribbons whose content is already AI-enriched}';

    protected $description = 'Generate rich, descriptive how-to content via the free-first AI chain';

    public function handle(AIChatProviderChain $chain): int
    {
        if (! DB::getSchemaBuilder()->hasColumn('module_how_tos', 'source')) {
            $this->error('The module_how_tos.source column is missing — run migrations first.');
            return self::FAILURE;
        }

        $ribbons = $this->targetRibbons();
        $total = $ribbons->count();
        if ($total === 0) {
            $this->info('Nothing to enrich — every ribbon already has curated or AI content.');
            return self::SUCCESS;
        }

        $this->info("Enriching {$total} ribbon(s) via the free-first AI chain…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done = 0;
        $failed = 0;
        $sleep = max(0, (int) $this->option('sleep'));

        foreach ($ribbons as $r) {
            try {
                // Hub-aware: if this ribbon is a hub, generate feature-specific Q&As covering every
                // card behind it (so coverage stays granular after the hub nav consolidation);
                // otherwise fall back to per-ribbon generation.
                $cards = HubCardCatalog::cards((string) $r->module_code, (string) $r->ribbon_path);
                $qas = $cards ? $this->generateForHub($chain, $r, $cards) : $this->generateFor($chain, $r);
                if (! empty($qas)) {
                    $this->replaceContent((int) $r->module_id, (int) $r->ribbon_id, $qas);
                    $done++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("  {$r->ribbon_name}: {$e->getMessage()}");
            }

            $bar->advance();

            // Throttle to stay under free-tier rate limits when few providers are keyed.
            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Enriched {$done} ribbon(s); {$failed} skipped/failed.");

        return self::SUCCESS;
    }

    /** Ribbons that still need enrichment (baseline; or everything with --force). */
    private function targetRibbons(): \Illuminate\Support\Collection
    {
        $enrichable = $this->option('force') ? ['baseline', 'ai'] : ['baseline'];

        $q = DB::table('security_module_ribbons as r')
            ->join('security_modules as m', 'r.module_id', '=', 'm.id')
            // The ribbon must have at least one how-to row that is enrichable and none
            // that are curated/manual (never overwrite hand-written content).
            ->whereExists(function ($sub) use ($enrichable) {
                $sub->select(DB::raw(1))->from('module_how_tos as h')
                    ->whereColumn('h.ribbon_id', 'r.id')
                    ->whereIn('h.source', $enrichable);
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))->from('module_how_tos as h2')
                    ->whereColumn('h2.ribbon_id', 'r.id')
                    ->whereIn('h2.source', ['curated', 'manual']);
            });

        if ($module = $this->option('module')) {
            $q->where(function ($w) use ($module) {
                $w->where('m.name', 'ilike', "%{$module}%")->orWhere('m.code_name', 'ilike', "%{$module}%");
            });
        }

        $q->select('r.id as ribbon_id', 'r.module_id', 'r.ribbon_name', 'r.ribbon_path', 'r.description', 'm.name as module_name', 'm.code_name as module_code')
            ->orderBy('r.module_id')->orderBy('r.sort_order');

        if (($limit = (int) $this->option('limit')) > 0) {
            $q->limit($limit);
        }

        return $q->get();
    }

    private const SYSTEM = 'You write clear, friendly end-user help for an HR/ERP web app called Mkhaya Agri. '
        . 'Produce practical, step-by-step guidance a non-technical employee can follow. '
        . 'Return ONLY valid JSON: an array of objects, each {"question": "...", "answer": "..."}. '
        . 'Questions must be real things a user would ask. Answers must be descriptive (3-6 sentences), '
        . 'reference the on-screen buttons/steps, and stay specific to the feature. No markdown fences.';

    /** Per-ribbon Q&A (for Dashboards / direct ribbons that are not hubs). */
    private function generateFor(AIChatProviderChain $chain, object $r): array
    {
        $user = "Module: {$r->module_name}\nFeature (menu item): {$r->ribbon_name}\n"
            . 'Menu path: ' . ($r->ribbon_path ?: 'n/a') . "\n"
            . 'Description: ' . ($r->description ?: 'n/a') . "\n\n"
            . 'Write 3 to 5 how-to Q&A for this feature.';

        return $this->askAi($chain, self::SYSTEM, $user);
    }

    /**
     * Hub-aware Q&A: one call covering EVERY feature card behind a hub ribbon, so a consolidated
     * hub (e.g. "Sell") still gets granular per-feature help (Leads, Contacts, Deals …).
     *
     * @param  array<int, array{key:string, title:string, path:string}>  $cards
     */
    private function generateForHub(AIChatProviderChain $chain, object $r, array $cards): array
    {
        $features = collect($cards)->map(fn ($c) => "- {$c['title']} (opens {$c['path']})")->implode("\n");
        $system = self::SYSTEM . ' This menu item is a HUB that groups several features; cover EACH listed '
            . 'feature with 2-3 real user questions specific to that feature. Return 6 to 15 objects total.';
        $user = "Module: {$r->module_name}\nHub (menu group): {$r->ribbon_name}\n"
            . "This hub groups these features:\n{$features}\n\n"
            . 'Write how-to Q&A JSON covering EACH feature above (2-3 questions each). '
            . 'Each question must be specific to its own feature, not the hub in general.';

        return $this->askAi($chain, $system, $user);
    }

    /** Run one completion and parse the Q&A JSON. */
    private function askAi(AIChatProviderChain $chain, string $system, string $user): array
    {
        $res = $chain->complete([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);

        $text = trim((string) ($res['text'] ?? ''));
        $text = preg_replace('/^```(json)?|```$/i', '', $text);
        $decoded = json_decode(trim((string) $text), true);

        if (! is_array($decoded)) {
            return [];
        }

        // Accept either a bare array or {items:[…]} / {questions:[…]} shapes.
        $items = isset($decoded[0]) ? $decoded : ($decoded['items'] ?? $decoded['questions'] ?? []);

        return collect($items)
            ->filter(fn ($x) => ! empty($x['question']) && ! empty($x['answer']))
            ->map(fn ($x) => ['question' => trim((string) $x['question']), 'answer' => trim((string) $x['answer'])])
            ->values()
            ->all();
    }

    /** Swap a ribbon's baseline/ai rows for freshly enriched ones (source='ai'). */
    private function replaceContent(int $moduleId, int $ribbonId, array $qas): void
    {
        DB::transaction(function () use ($moduleId, $ribbonId, $qas) {
            DB::table('module_how_tos')
                ->where('ribbon_id', $ribbonId)
                ->whereIn('source', ['baseline', 'ai'])
                ->delete();

            $now = now();
            DB::table('module_how_tos')->insert(array_map(fn ($qa) => [
                'module_id' => $moduleId,
                'ribbon_id' => $ribbonId,
                'question' => $qa['question'],
                'answer' => $qa['answer'],
                'active' => 1,
                'source' => 'ai',
                'created_at' => $now,
                'updated_at' => $now,
            ], $qas));
        });
    }
}
