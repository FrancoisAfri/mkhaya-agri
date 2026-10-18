<?php

namespace App\Console\Commands\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives a company a category list and, optionally, a filing structure to start from.
 *
 * Categories are installed for everyone, because a repository with no categories cannot classify
 * anything and every installation wants the same eighteen to begin with.
 *
 * The folder tree is opt-in. An organisation's filing structure is theirs, and the example below —
 * HR, Finance, Payroll, Procurement, Legal — is a reasonable starting point rather than a
 * requirement. Somebody who already knows how they file should not have to delete our idea of it
 * first.
 *
 *   php artisan documents:install --company=1
 *   php artisan documents:install --company=1 --folders
 *   php artisan documents:install --all
 */
class InstallDocumentStructure extends Command
{
    protected $signature = 'documents:install
        {--company= : Install for this company.}
        {--all : Install for every company.}
        {--folders : Also create the starter folder structure.}
        {--templates : Also create the starter template library.}';

    protected $description = 'Install the default document categories, and optionally a starter folder tree.';

    /**
     * A structure most organisations recognise.
     *
     * Departmental where the contents genuinely belong to one team — nobody outside HR should be
     * browsing Employee Files by default — and company-wide where they do not.
     */
    private const STARTER_FOLDERS = [
        ['name' => 'Human Resources', 'scope' => 'department', 'children' => [
            ['name' => 'Employee Files', 'confidential' => true],
            ['name' => 'Policies'],
            ['name' => 'Contracts'],
            ['name' => 'Disciplinary', 'confidential' => true],
        ]],
        ['name' => 'Finance', 'scope' => 'department', 'children' => [
            ['name' => 'Invoices'],
            ['name' => 'Statements'],
            ['name' => 'Tax'],
            ['name' => 'Reports'],
        ]],
        ['name' => 'Payroll', 'scope' => 'department', 'children' => [
            ['name' => 'EMP201'],
            ['name' => 'Tax Certificates', 'confidential' => true],
            ['name' => 'Payroll Reports'],
        ]],
        ['name' => 'Procurement', 'scope' => 'department', 'children' => [
            ['name' => 'Suppliers'],
            ['name' => 'Quotations'],
            ['name' => 'Purchase Orders'],
        ]],
        ['name' => 'Legal', 'scope' => 'department', 'children' => [
            ['name' => 'Contracts'],
            ['name' => 'Agreements'],
            ['name' => 'Policies'],
        ]],
    ];

    public function handle(): int
    {
        $companies = $this->option('all')
            ? DB::table('companies')->pluck('name', 'id')->all()
            : DB::table('companies')->where('id', (int) $this->option('company'))->pluck('name', 'id')->all();

        if ($companies === []) {
            $this->error('Give me --company=<id> or --all.');

            return self::FAILURE;
        }

        foreach ($companies as $companyId => $name) {
            // Each company is installed in its own tenant context, so the RLS default fills
            // company_id and nothing has to pass it around by hand.
            DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

            $this->line("  <fg=cyan>{$name}</>");
            $this->installCategories();

            if ($this->option('folders')) {
                $this->installFolders();
            }

            if ($this->option('templates')) {
                $this->installTemplates();
            }
        }

        DB::statement("SELECT set_config('app.company_id', '', false)");

        return self::SUCCESS;
    }

    private function installCategories(): void
    {
        $added = 0;

        foreach (DocumentCategory::DEFAULTS as $index => $definition) {
            // Matched on slug so re-running never duplicates, and a renamed category is left with
            // the name its administrator gave it.
            if (DocumentCategory::withTrashed()->where('slug', $definition['slug'])->exists()) {
                continue;
            }

            DocumentCategory::create($definition + [
                'is_system' => true,
                'is_active' => true,
                'sort_order' => $index,
            ]);

            $added++;
        }

        $this->line("    categories: {$added} added, " . (count(DocumentCategory::DEFAULTS) - $added) . ' already present');
    }

    private function installFolders(): void
    {
        $added = 0;

        foreach (self::STARTER_FOLDERS as $order => $top) {
            $parent = DocumentFolder::firstOrCreate(
                ['name' => $top['name'], 'parent_id' => null],
                [
                    'scope' => $top['scope'] ?? DocumentFolder::SCOPE_COMPANY,
                    'sort_order' => $order,
                    'is_confidential' => false,
                ],
            );

            $added += $parent->wasRecentlyCreated ? 1 : 0;

            foreach ($top['children'] as $childOrder => $child) {
                $created = DocumentFolder::firstOrCreate(
                    ['name' => $child['name'], 'parent_id' => $parent->id],
                    [
                        // A subfolder inherits the department it sits in; only confidentiality is
                        // set per folder, because that is genuinely a per-folder decision.
                        'scope' => $parent->scope,
                        'department_unit_id' => $parent->department_unit_id,
                        'is_confidential' => (bool) ($child['confidential'] ?? false),
                        'sort_order' => $childOrder,
                    ],
                );

                $added += $created->wasRecentlyCreated ? 1 : 0;
            }
        }

        $this->line("    folders: {$added} created");
    }

    /**
     * The starter template library.
     *
     * Deliberately skeletal — a heading, the merge fields, and a line saying to replace the wording.
     * A shipped employment contract that looked finished would be signed by somebody assuming a
     * lawyer had reviewed it, and none has. These show where the fields go.
     */
    private function installTemplates(): void
    {
        $added = 0;

        foreach (\App\Modules\Operations\DocumentManagement\Models\DocumentTemplate::STARTERS as $starter) {
            if (\App\Modules\Operations\DocumentManagement\Models\DocumentTemplate::where('name', $starter['name'])->exists()) {
                continue;
            }

            $fields = \App\Modules\Operations\DocumentManagement\Support\MergeFields::availableFor($starter['relation']);
            $rows = collect($fields)
                ->map(fn ($label, $key) => "<tr><th>{$label}</th><td>{{{$key}}}</td></tr>")
                ->implode('');

            \App\Modules\Operations\DocumentManagement\Models\DocumentTemplate::create([
                'name' => $starter['name'],
                'description' => 'Starter template — replace this wording with your own.',
                'subject_relation' => $starter['relation'],
                'category_id' => \App\Modules\Operations\DocumentManagement\Models\DocumentCategory::where('slug', $starter['category'])->value('id'),
                'filename_pattern' => $starter['relation']
                    ? $starter['name'] . ' — {{' . $starter['relation'] . '.name}}'
                    : $starter['name'],
                'body' => "<h1>{$starter['name']}</h1>"
                    . '<p>This is a starter template. Replace this wording with your own; the table '
                    . 'below shows the fields available and is safe to delete.</p>'
                    . "<table>{$rows}</table>",
                'is_active' => true,
            ]);

            $added++;
        }

        $this->line("    templates: {$added} created");
    }
}
