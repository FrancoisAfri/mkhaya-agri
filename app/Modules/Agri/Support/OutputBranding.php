<?php

namespace App\Modules\Agri\Support;

use App\Modules\Tenancy\Services\CompanyBranding;

/**
 * The brand on what leaves the farm system — the public trace page, label sheets, export
 * documents, the certification auditor pack, the carbon registry summary, grower statements.
 *
 * White label on: the company's logo and name head the output and its footer names the company,
 * so nothing a customer, grower or auditor reads says "Mkhaya". Off: the output is as it always
 * was, and its footer names the product.
 */
final class OutputBranding
{
    public static function for(?int $companyId): array
    {
        return app(CompanyBranding::class)->for($companyId);
    }

    /** The name an output's footer credits: the company's when white-labelled, else the product's. */
    public static function credit(array $b): string
    {
        return (string) $b['name'];
    }

    /** A heading strip with the company's logo and name for a PDF — nothing when not white-labelled. */
    public static function pdfHeader(array $b): string
    {
        if (! $b['white_label']) {
            return '';
        }
        $logo = $b['logo_data_uri'] ? '<img src="'.e($b['logo_data_uri']).'" alt="" style="height:34px;max-width:160px;vertical-align:middle;margin-right:8px">' : '';

        return '<div class="brand" style="margin:0 0 8px;padding:0 0 6px;border-bottom:1px solid #ccc">'.$logo
            .'<span style="font-weight:bold;font-size:12px;vertical-align:middle">'.e($b['name']).'</span></div>';
    }
}
