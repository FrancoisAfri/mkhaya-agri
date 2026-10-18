<?php

/**
 * Content Marketing limits. These bound what an audit or a send is allowed to do to somebody
 * else's server, so they belong in config rather than scattered through the services.
 */
return [
    'audit' => [
        // A site audit that reads one page is not a site audit; one that reads ten thousand is a
        // denial-of-service attempt against a client. Both bounds are stated rather than implied.
        'max_pages'       => (int) env('CM_AUDIT_MAX_PAGES', 25),
        'max_link_checks' => (int) env('CM_AUDIT_MAX_LINK_CHECKS', 60),
        // PageSpeed is slow and quota-limited, so vitals sample rather than cover. A homepage is
        // the least representative page on most sites; the article templates are the slow ones.
        'vitals_pages'    => (int) env('CM_AUDIT_VITALS_PAGES', 3),
    ],

    'outreach' => [
        // Sending an entire list in one burst is the fastest route to a blocked sending domain.
        'per_minute'  => (int) env('CM_OUTREACH_PER_MINUTE', 20),
        'batch_size'  => (int) env('CM_OUTREACH_BATCH', 50),

        /*
         * The mailbox replies come back to. Plus addressing tags each message with the recipient
         * token, so an inbound reply identifies exactly who sent it — which is what lets a reply
         * stop a sequence without connecting an inbox.
         */
        'reply_to'    => env('CM_OUTREACH_REPLY_TO'),
    ],

    'images' => [
        // WebP with a JPEG fallback, and the widths a responsive srcset needs.
        'format'  => env('CM_IMAGE_FORMAT', 'webp'),
        'quality' => (int) env('CM_IMAGE_QUALITY', 82),
        // AVIF at the largest width only: slow to encode, and that is the width that decides LCP.
        'avif'    => env('CM_IMAGE_AVIF', true),
        'widths'  => [480, 960, 1600],
    ],
];
