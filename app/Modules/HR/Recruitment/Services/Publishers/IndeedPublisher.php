<?php

namespace App\Modules\HR\Recruitment\Services\Publishers;

/**
 * Indeed is deliberately still unconfigured.
 *
 * It is not a social network with a post endpoint: adverts reach Indeed through an XML job
 * feed on a paid employer account, which is a different piece of work from signing in and
 * posting. Pretending otherwise by giving it a publish button that silently does nothing
 * is what the other two publishers used to do.
 */
class IndeedPublisher extends NotConfiguredPublisher
{
    protected function providerName(): string
    {
        return 'Indeed';
    }

    protected function reason(): string
    {
        return 'Indeed takes adverts through an XML job feed on an employer account, not a '
            .'social post. It has not been set up on this installation.';
    }
}
