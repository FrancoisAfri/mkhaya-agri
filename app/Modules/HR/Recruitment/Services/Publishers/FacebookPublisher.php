<?php

namespace App\Modules\HR\Recruitment\Services\Publishers;

class FacebookPublisher extends NetworkPublisher
{
    protected function platform(): string
    {
        return 'facebook';
    }

    protected function providerName(): string
    {
        return 'Facebook';
    }
}
