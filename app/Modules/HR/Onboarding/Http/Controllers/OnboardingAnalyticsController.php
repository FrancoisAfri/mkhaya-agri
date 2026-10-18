<?php

namespace App\Modules\HR\Onboarding\Http\Controllers;

use App\Modules\HR\Onboarding\Services\OnboardingAnalyticsService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Onboarding analytics and the export behind them.
 *
 * Offboarding has had both since it was built and onboarding had neither, so the only way to ask
 * "is anyone actually being onboarded?" was to open plans one at a time.
 */
class OnboardingAnalyticsController
{
    public function __construct(private readonly OnboardingAnalyticsService $analytics)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Modules/Onboarding/Analytics', [
            'analytics' => $this->analytics->summary(),
        ]);
    }

    /**
     * One row per plan, as CSV.
     *
     * Streamed rather than built in memory: an export is exactly the request most likely to be run
     * against every plan a large tenant has ever created.
     */
    public function export(): StreamedResponse
    {
        [$headers, $rows] = $this->analytics->planExport();

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // Excel reads a UTF-8 CSV as Latin-1 without this, which mangles every name with an
            // accent or a click consonant in it.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, 'onboarding-plans.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
