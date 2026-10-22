<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A peer band: P25, median and P75 of one measure for a crop, season and region across opted-in
 * organisations. Aggregates only — the table has no company, farm or field column — so it is not
 * tenant-scoped; who may see it is decided by the reader's own opt-in (PeerBenchmarks::forCompany).
 */
class PeerBenchmark extends Model
{
    protected $table = 'agri_benchmarks';

    protected $guarded = ['id'];

    protected $casts = ['p25' => 'float', 'median' => 'float', 'p75' => 'float', 'farms' => 'integer', 'organisations' => 'integer', 'seeded' => 'boolean', 'computed_at' => 'datetime'];
}
