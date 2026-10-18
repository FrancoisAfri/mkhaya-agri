<?php

namespace App\Modules\Assets\Jobs;

use App\Modules\Assets\Models\Assets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunImpairmentReviewRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function handle(): void
    {
        $today = now()->toDateString();

        Assets::query()
            ->whereNotNull('next_impairment_review_at')
            ->whereDate('next_impairment_review_at', '<=', $today)
            ->whereNotIn('asset_status', ['disposed', 'sold'])
            ->chunkById(100, function ($assets) {
                foreach ($assets as $asset) {
                    Log::info('Impairment review due (IAS 36)', [
                        'asset_id'    => $asset->id,
                        'asset_name'  => $asset->name,
                        'review_date' => $asset->next_impairment_review_at?->toDateString(),
                    ]);
                }
            });
    }
}
