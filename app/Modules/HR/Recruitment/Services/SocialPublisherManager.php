<?php

namespace App\Modules\HR\Recruitment\Services;

use App\Modules\HR\Recruitment\Contracts\SocialPublisher;
use App\Modules\HR\Recruitment\Enums\SocialProvider;
use App\Modules\HR\Recruitment\Services\Publishers\FacebookPublisher;
use App\Modules\HR\Recruitment\Services\Publishers\IndeedPublisher;
use App\Modules\HR\Recruitment\Services\Publishers\LinkedinPublisher;
use InvalidArgumentException;

class SocialPublisherManager
{
    public function for(SocialProvider $provider): SocialPublisher
    {
        return match ($provider) {
            SocialProvider::Linkedin => app(LinkedinPublisher::class),
            SocialProvider::Facebook => app(FacebookPublisher::class),
            SocialProvider::Indeed   => app(IndeedPublisher::class),
            default                  => throw new InvalidArgumentException("Unsupported provider: {$provider->value}"),
        };
    }
}
