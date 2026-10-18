<?php

namespace App\Modules\HR\Recruitment\Services\Publishers;

class LinkedinPublisher extends NetworkPublisher
{
    protected function platform(): string
    {
        return 'linkedin';
    }

    protected function providerName(): string
    {
        return 'LinkedIn';
    }
}
