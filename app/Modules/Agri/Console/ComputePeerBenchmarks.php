<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Services\PeerBenchmarks;
use Illuminate\Console\Command;

/**
 * Weekly: peer benchmarks from the organisations that opted in. Deliberately not per tenant — see
 * PeerBenchmarks, the one service that reads across organisations, and writes only k-anonymous
 * aggregates.
 */
class ComputePeerBenchmarks extends Command
{
    protected $signature = 'agri:benchmarks';

    protected $description = 'Compute anonymised Mkhaya Agri peer benchmarks (yield and cost bands) from opted-in organisations';

    public function handle(PeerBenchmarks $benchmarks): int
    {
        $r = $benchmarks->compute();
        $this->line("{$r['organisations']} organisation(s) opted in, {$r['contributions']} crop(s) compared in {$r['groups']} group(s); {$r['written']} band(s) written.");

        return self::SUCCESS;
    }
}
