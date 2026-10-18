<?php

namespace App\Modules\HR\Recruitment\Contracts;

use App\Modules\HR\Recruitment\Models\SocialAccount;
use App\Modules\HR\Recruitment\Models\SocialPost;
use App\Modules\HR\Recruitment\Models\Vacancy;

interface SocialPublisher
{
    public function publish(Vacancy $vacancy, SocialAccount $account): SocialPost;
}
