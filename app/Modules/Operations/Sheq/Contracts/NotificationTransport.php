<?php

namespace App\Modules\Operations\Sheq\Contracts;

/**
 * How a routed notification actually leaves the building.
 *
 * A seam rather than a direct call to the platform's mailer and SMS service, for two reasons. The
 * first is testability: asserting that a fatality produced a voice call must not depend on a
 * provider being reachable. The second is that channels arrive at different times — voice needs a
 * telephony provider this platform does not have yet — and a transport that reports `false` for an
 * unsupported channel leaves the dispatch row PENDING and visible, which is the honest outcome. A
 * hard-coded sender would have had to either lie or throw.
 */
interface NotificationTransport
{
    /** Whether this transport can carry the channel at all for this tenant. */
    public function supports(string $channel): bool;

    /**
     * @param  array{
     *     channel:string, address:?string, user_id:?int, label:?string, severity:string,
     *     subject:string, body:string, link:?string, reference:?string
     * }  $message
     * @return bool true when accepted for delivery; false leaves the dispatch pending for retry
     */
    public function send(array $message): bool;
}
