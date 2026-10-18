<?php

namespace App\Console\Commands\CRM;

use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Find the templates that say `{{company_name}}` and mean the wrong company.
 *
 * `{{company}}` is the recipient's company. `{{company_name}}` was offered for a long time as our
 * own, and the shared resolver reads "company name" as the recipient's — so a sentence written as
 * "I'm Ann from {{company_name}}" goes out telling the prospect that Ann works for them.
 *
 * It resolves, it reads naturally, and it is wrong, which is why nobody catches it: a test send
 * looks perfect to the person sending it, because they know which company is which. The tag is no
 * longer offered when authoring, but anything already written with it still carries it.
 *
 * Read-only. It finds them and says what to change; the wording is somebody's to rewrite, not a
 * script's — "from Mkhaya Hub" and "from {{sender_company}}" are not always the same sentence.
 */
class CheckMergeTags extends Command
{
    use RunsPerTenant;

    protected $signature   = 'crm:check-merge-tags {--company= : Check only this company}';
    protected $description = 'List CRM sequences and templates using {{company_name}}, which resolves to the recipient';

    public function handle(): int
    {
        $found = 0;

        $this->eachTenant(app(TenantContext::class), function ($companyId) use (&$found) {
            $sequences = DB::table('crm_sequences')->whereNull('deleted_at')
                ->whereRaw('steps::text ilike ?', ['%company_name%'])->get(['id', 'name']);

            $templates = DB::table('crm_email_templates')
                ->whereRaw("(coalesce(subject,'') || coalesce(body,'')) ilike ?", ['%company_name%'])
                ->get(['id', 'name']);

            if ($sequences->isEmpty() && $templates->isEmpty()) {
                return;
            }

            $this->newLine();
            $this->line("Company #{$companyId}");

            foreach ($sequences as $s) {
                $this->warn("  sequence #{$s->id}  {$s->name}");
                $found++;
            }
            foreach ($templates as $t) {
                $this->warn("  template #{$t->id}  {$t->name}");
                $found++;
            }
        });

        $this->newLine();

        if ($found === 0) {
            $this->info('Nothing uses {{company_name}}. Nothing to change.');

            return self::SUCCESS;
        }

        $this->info("{$found} item(s) use {{company_name}}.");
        $this->line('Replace it with {{sender_company}} where you meant your own company,');
        $this->line('or with {{company}} where you meant theirs. Both are offered when authoring.');

        return self::SUCCESS;
    }
}
