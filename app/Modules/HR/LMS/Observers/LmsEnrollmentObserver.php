<?php

namespace App\Modules\HR\LMS\Observers;

use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Services\Qcto\LmsBbeeService;

class LmsEnrollmentObserver
{
    public function __construct(private LmsBbeeService $bbeeService) {}

    public function updated(LmsEnrollment $enrollment): void
    {
        if ($enrollment->wasChanged('status') && $enrollment->status === 'completed') {
            $this->bbeeService->recordSpend($enrollment);
        }
    }
}
