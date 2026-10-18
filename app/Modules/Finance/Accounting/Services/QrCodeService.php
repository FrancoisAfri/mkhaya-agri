<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinSetting;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Generates a scannable payment QR for an invoice — a SnapScan link when a
 * merchant is configured, otherwise a generic EFT payload with the banking
 * details. Rendered as an SVG data URI for the PDF.
 */
class QrCodeService
{
    /** @return array{uri: string, caption: string}|null */
    public function paymentQr(FinInvoice $invoice, array $company): ?array
    {
        $settings = FinSetting::current();
        if (! $settings->show_payment_qr || $invoice->direction !== 'receivable' || $invoice->outstanding() <= 0) {
            return null;
        }

        $amount = $invoice->outstanding();
        [$payload, $caption] = $this->payload($settings, $amount, $invoice->number, $company);

        return ['uri' => $this->svgDataUri($payload), 'caption' => $caption];
    }

    private function payload(FinSetting $s, float $amount, string $ref, array $company): array
    {
        if ($s->snapscan_merchant) {
            $cents = (int) round($amount * 100);
            $url = 'https://pos.snapscan.io/qr/' . rawurlencode($s->snapscan_merchant)
                . '?amount=' . $cents . '&id=' . rawurlencode($ref) . '&strict=true';

            return [$url, 'Scan to pay with SnapScan'];
        }

        // Generic EFT payload — human/app readable banking details.
        $bank = $company['bank'] ?? [];
        $lines = array_filter([
            'Pay: ' . ($company['name'] ?? ''),
            ($bank['Bank'] ?? null) ? 'Bank: ' . $bank['Bank'] : null,
            ($bank['Account no.'] ?? null) ? 'Acc: ' . $bank['Account no.'] : null,
            ($bank['Branch'] ?? null) ? 'Branch: ' . $bank['Branch'] : null,
            'Amount: ' . number_format($amount, 2),
            'Ref: ' . $ref,
        ]);

        return [implode("\n", $lines), 'Scan for EFT payment details'];
    }

    private function svgDataUri(string $payload): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($payload));
    }
}
