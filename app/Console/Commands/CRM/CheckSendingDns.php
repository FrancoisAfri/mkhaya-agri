<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Services\CrmSendingGuard;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * Checks the DNS records mailbox providers use to decide whether to trust the
 * sending domain: SPF, DKIM and DMARC.
 *
 * These are DNS records, not application config, so this cannot create them —
 * it reports what is published and what is missing, which is the part that
 * usually gets forgotten until deliverability drops.
 */
class CheckSendingDns extends Command
{
    protected $signature = 'crm:check-dns
                            {--domain= : Check this domain instead of the configured sending domain}
                            {--selector=* : DKIM selector(s) to look for (default: common ones)}
                            {--company= : Check only this company}';

    protected $description = 'Check SPF, DKIM and DMARC records for the email sending domain';

    /** Selectors used by the common providers, tried when none is given. */
    private const DEFAULT_SELECTORS = ['default', 'mail', 'smtp', 's1', 's2', 'k1', 'google', 'selector1', 'selector2', 'mandrill', 'sendgrid'];

    public function handle(TenantContext $tenant, CrmSendingGuard $guard): int
    {
        if ($domain = $this->option('domain')) {
            return $this->checkDomain($domain);
        }

        $failed = false;

        $run = function () use ($guard, &$failed) {
            $domain = $guard->sendingDomain();

            if (! $domain) {
                $this->warn('No sending domain configured for this company — skipping.');
                return;
            }

            if ($this->checkDomain($domain) !== self::SUCCESS) {
                $failed = true;
            }
        };

        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, $run);
        } else {
            $tenant->eachCompany($run);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkDomain(string $domain): int
    {
        $this->line('');
        $this->info("Sending domain: {$domain}");

        $ok = true;

        // ── SPF ───────────────────────────────────────────────────────────────
        $spf = $this->txtRecords($domain, 'v=spf1');
        if (empty($spf)) {
            $this->error('  SPF     MISSING — receivers cannot confirm which servers may send as this domain.');
            $ok = false;
        } elseif (count($spf) > 1) {
            $this->error('  SPF     INVALID — ' . count($spf) . ' SPF records found; exactly one is allowed.');
            $ok = false;
        } else {
            $record = $spf[0];
            $this->line("  SPF     found: {$record}");
            if (str_contains($record, '+all')) {
                $this->error('          "+all" lets anyone send as you — replace with "-all" or "~all".');
                $ok = false;
            } elseif (! str_contains($record, '-all') && ! str_contains($record, '~all')) {
                $this->warn('          no "-all"/"~all" — unlisted senders are not rejected.');
            }
        }

        // ── DKIM ──────────────────────────────────────────────────────────────
        $selectors = $this->option('selector') ?: self::DEFAULT_SELECTORS;
        $found = [];

        foreach ($selectors as $selector) {
            if ($this->txtRecords("{$selector}._domainkey.{$domain}", 'v=DKIM1')) {
                $found[] = $selector;
            }
        }

        if ($found) {
            $this->line('  DKIM    found for selector(s): ' . implode(', ', $found));
        } else {
            $this->error('  DKIM    NOT FOUND for the selectors tried (' . implode(', ', array_slice($selectors, 0, 5)) . '…).');
            $this->line('          If your provider uses a different selector, re-run with --selector=yourselector.');
            $ok = false;
        }

        // ── DMARC ─────────────────────────────────────────────────────────────
        $dmarc = $this->txtRecords("_dmarc.{$domain}", 'v=DMARC1');
        if (empty($dmarc)) {
            $this->error('  DMARC   MISSING — required by Gmail/Yahoo for bulk senders. Start with "v=DMARC1; p=none; rua=mailto:you@' . $domain . '".');
            $ok = false;
        } else {
            $record = $dmarc[0];
            $this->line("  DMARC   found: {$record}");
            if (! str_contains($record, 'rua=')) {
                $this->warn('          no "rua=" address — you will get no aggregate reports, so failures stay invisible.');
            }
            if (preg_match('/p=\s*none/i', $record)) {
                $this->warn('          policy is "p=none" (monitor only). Move to quarantine/reject once reports look clean.');
            }
        }

        $this->line($ok ? '  → All three records present.' : '  → Action needed before sending at volume.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * TXT records for a host, filtered to those starting with the given prefix.
     * Long records are split into chunks by DNS, so entries are joined first.
     *
     * @return array<int, string>
     */
    private function txtRecords(string $host, string $prefix): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];
        $out = [];

        foreach ($records as $record) {
            $text = isset($record['entries']) && is_array($record['entries'])
                ? implode('', $record['entries'])
                : ($record['txt'] ?? '');

            if ($text !== '' && stripos($text, $prefix) === 0) {
                $out[] = $text;
            }
        }

        return $out;
    }
}
