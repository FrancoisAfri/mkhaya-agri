<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Models\FinBankFeed;
use App\Modules\Finance\Accounting\Services\BankFeedService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pull every connected bank feed, for every tenant.
 *
 * Without this a "live feed" is a button: the transactions arrive when somebody remembers to ask
 * for them, which is the morning they are reconciling and not before. The point of an aggregator is
 * that the statement is already there.
 *
 * Per tenant, because feeds, credentials and the statement lines they write are all tenant-scoped —
 * a single global pass would either see nothing or write into the wrong company's books.
 */
class RefreshBankFeeds extends Command
{
    use RunsPerTenant;

    protected $signature = 'finance:refresh-bank-feeds
        {--company= : Only this company}
        {--feed= : Only this feed id}';

    protected $description = 'Pull new transactions for every connected bank feed';

    public function handle(TenantContext $tenant, BankFeedService $feeds): int
    {
        $totalFeeds = 0;
        $totalLines = 0;
        $failed = 0;

        $run = function (?int $companyId) use ($feeds, &$totalFeeds, &$totalLines, &$failed) {
            $due = FinBankFeed::query()
                ->where('status', 'connected')
                // `login` feeds refresh when their owner opens the screen; only `daily` belongs on
                // a schedule, and `manual` means the user asked to be left alone.
                ->where('refresh_mode', 'daily')
                ->when($this->option('feed'), fn ($q, $id) => $q->where('id', $id))
                ->get();

            foreach ($due as $feed) {
                $totalFeeds++;

                /*
                 * One failing feed must not end the run.
                 *
                 * A bank that has revoked consent, or an aggregator having an afternoon, is an
                 * ordinary condition — and letting it abort the pass would mean every tenant after
                 * this one silently goes unreconciled.
                 */
                try {
                    $totalLines += $feeds->refresh($feed);

                    if ($feed->fresh()->status === 'error') {
                        $failed++;
                        $this->warn("  feed {$feed->id}: " . $feed->fresh()->last_error);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Bank feed refresh failed', [
                        'feed' => $feed->id, 'company_id' => $companyId, 'error' => $e->getMessage(),
                    ]);
                    $feed->update(['status' => 'error', 'last_error' => \Illuminate\Support\Str::limit($e->getMessage(), 240)]);
                }
            }
        };

        if ($companyId = $this->option('company')) {
            $tenant->run((int) $companyId, fn () => $run((int) $companyId));
        } else {
            $this->eachTenant($tenant, fn (?int $id) => $run($id));
        }

        $this->info(sprintf(
            'Bank feeds refreshed: %d feed(s), %d new transaction(s), %d failure(s).',
            $totalFeeds, $totalLines, $failed,
        ));

        // A failing feed is reported, not an exit code: the run did what it could, and a non-zero
        // exit would put a scheduler failure in the log every morning until somebody re-consented.
        return self::SUCCESS;
    }
}
