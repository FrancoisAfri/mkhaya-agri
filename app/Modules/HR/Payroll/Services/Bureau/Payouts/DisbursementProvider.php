<?php

namespace App\Modules\HR\Payroll\Services\Bureau\Payouts;

use App\Modules\HR\Payroll\Models\Bureau\BureauPaymentIntegration;

/** Contract for a real-time salary payout provider adapter. */
interface DisbursementProvider
{
    public function provider(): string;

    /** @return array{success:bool,message:string} */
    public function testConnection(BureauPaymentIntegration $integration): array;

    /**
     * Submit a payout batch.
     * @param array<int,array{name:string,branch:string,account:string,amount:float,reference:string}> $instructions
     * @return array{reference:?string,status:string,raw:array}
     */
    public function createBatch(BureauPaymentIntegration $integration, array $instructions): array;

    /** Current status of a previously-submitted batch. @return array{status:string,raw:array} */
    public function batchStatus(BureauPaymentIntegration $integration, string $reference): array;
}
