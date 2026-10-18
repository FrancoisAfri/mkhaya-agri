<?php

namespace App\Support\Social;

use Illuminate\Support\Facades\Http;

/**
 * The actual calls to Facebook, X and LinkedIn.
 *
 * These three were written and proven inside Content Marketing. Recruitment had its own
 * set of publisher classes for the same three networks, and all of them were stubs that
 * recorded a failed post saying "not yet configured" — so the module offered social
 * publishing that could never reach a network.
 *
 * The obvious fix was to write the integrations a second time in Recruitment. That is how
 * you end up with two copies which drift, one of them quietly broken, which is the shape
 * of the bug being fixed here in the first place. So the HTTP calls live here once and
 * both modules hand them a token and some text.
 *
 * Nothing in here knows about vacancies or articles, and nothing throws: each method
 * returns whether the network accepted the post, so the caller records the outcome
 * against whatever it calls a post.
 *
 * @phpstan-type Outcome array{ok: bool, url: ?string, status: int, error: ?string}
 */
class SocialNetworkPublisher
{
    /** Networks with a real integration. Anything else is honestly unsupported. */
    public const SUPPORTED = ['facebook', 'x', 'linkedin'];

    /** Characters each network will take, used to trim before posting rather than be rejected. */
    public const LIMITS = ['facebook' => 2000, 'x' => 260, 'linkedin' => 2000];

    public function supports(string $platform): bool
    {
        return in_array($platform, self::SUPPORTED, true);
    }

    /**
     * Post text (and optionally a link) to one connected account.
     *
     * @param  array<string,mixed>  $credentials  as stored when the account was connected
     * @param  string|null  $externalId  Page id for Facebook, author URN for LinkedIn
     * @return array{ok: bool, url: ?string, status: int, error: ?string}
     */
    /**
     * @param  ?string  $imageUrl  a PUBLICLY reachable image. Our own asset routes sit behind auth,
     *                             so this is the copy on the customer's own site, which the
     *                             connector uploaded there on publish.
     * @param  ?string  $title     used for the LinkedIn link card, which has its own headline
     */
    public function post(
        string $platform,
        array $credentials,
        ?string $externalId,
        string $text,
        ?string $link = null,
        ?string $imageUrl = null,
        ?string $title = null,
    ): array {
        if (! $this->supports($platform)) {
            return $this->outcome(false, null, 0, ucfirst($platform).' publishing is not supported.');
        }

        $token = (string) ($credentials['access_token'] ?? '');

        if ($token === '') {
            return $this->outcome(false, null, 0, 'That '.ucfirst($platform).' account has no access token — reconnect it.');
        }

        try {
            return match ($platform) {
                'facebook' => $this->facebook($token, $externalId ?: ($credentials['page_id'] ?? null), $text, $link, $imageUrl),
                'x'        => $this->x($token, $text),
                'linkedin' => $this->linkedin($token, $externalId, $text, $link, $imageUrl, $title),
            };
        } catch (\Throwable $e) {
            return $this->outcome(false, null, 0, $e->getMessage());
        }
    }

    /**
     * Fit the body, hashtags and link inside the network's limit.
     *
     * The suffix is reserved first, so trimming never cuts the link off the end — a post
     * that loses its link is worse than a post that loses a sentence.
     *
     * @param  array<int,string>  $hashtags
     */
    public function compose(string $body, array $hashtags = [], ?string $link = null, int $limit = 2000): string
    {
        $tags = collect($hashtags)
            ->filter()
            ->map(fn ($tag) => '#'.ltrim((string) $tag, '#'))
            ->implode(' ');

        $suffix = trim(($tags !== '' ? "\n\n{$tags}" : '').($link ? "\n{$link}" : ''));
        $room = max(40, $limit - mb_strlen($suffix) - 2);

        return trim(\Illuminate\Support\Str::limit(trim($body), $room, '…').($suffix !== '' ? "\n".$suffix : ''));
    }

    private function facebook(string $token, ?string $pageId, string $message, ?string $link, ?string $imageUrl = null): array
    {
        if (! $pageId) {
            return $this->outcome(false, null, 0, 'No Facebook Page is linked to that account — reconnect and grant Page access.');
        }

        /*
         * With a picture, post it as a photo rather than as a link.
         *
         * A link post shows an image only if the destination page carries Open Graph tags, which
         * depends on the customer's site having an SEO plugin — so the picture would appear for
         * some customers and not others, with nothing here to explain the difference. Handing
         * Facebook the image directly always shows it, and the link still travels in the caption.
         */
        if ($imageUrl) {
            $res = Http::timeout(30)->asForm()->post("https://graph.facebook.com/v19.0/{$pageId}/photos", [
                'url'          => $imageUrl,
                'caption'      => $message,
                'access_token' => $token,
            ]);

            if ($res->successful()) {
                $id = $res->json('post_id') ?: $res->json('id');

                return $this->outcome(true, $id ? "https://facebook.com/{$id}" : null, $res->status(), null);
            }

            // An image Facebook could not fetch is not a reason to lose the post. Fall through to
            // the link form, which at least publishes.
        }

        $res = Http::timeout(20)->asForm()->post("https://graph.facebook.com/v19.0/{$pageId}/feed", array_filter([
            'message'      => $message,
            'link'         => $link,
            'access_token' => $token,
        ]));

        $id = $res->json('id');

        return $this->outcome(
            $res->successful(),
            $id ? "https://facebook.com/{$id}" : null,
            $res->status(),
            $res->successful() ? null : $res->json('error.message'),
        );
    }

    private function x(string $token, string $text): array
    {
        $res = Http::timeout(20)->withToken($token)->post('https://api.twitter.com/2/tweets', ['text' => $text]);
        $id = $res->json('data.id');

        return $this->outcome(
            $res->successful(),
            $id ? "https://x.com/i/web/status/{$id}" : null,
            $res->status(),
            $res->successful() ? null : ($res->json('detail') ?? $res->json('title')),
        );
    }

    private function linkedin(
        string $token,
        ?string $author,
        string $text,
        ?string $link = null,
        ?string $imageUrl = null,
        ?string $title = null,
    ): array {
        if (! $author) {
            return $this->outcome(false, null, 0, 'That LinkedIn account has no author URN — reconnect it.');
        }

        /*
         * A link becomes an ARTICLE share, not a bare URL in the text.
         *
         * This used to send shareMediaCategory NONE with the link pasted into the commentary, which
         * LinkedIn renders as plain text: no card, no headline, no picture — just a URL somebody has
         * to notice. ARTICLE with originalUrl is what produces the preview card, and thumbnail puts
         * our own image on it rather than leaving LinkedIn to find one.
         */
        $content = ['shareCommentary' => ['text' => $text], 'shareMediaCategory' => 'NONE'];

        if ($link) {
            $media = ['status' => 'READY', 'originalUrl' => $link];

            if ($title) {
                $media['title'] = ['text' => \Illuminate\Support\Str::limit($title, 200, '')];
            }
            if ($imageUrl) {
                $media['thumbnails'] = [['url' => $imageUrl]];
            }

            $content['shareMediaCategory'] = 'ARTICLE';
            $content['media'] = [$media];
        }

        $res = Http::timeout(20)->withToken($token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post('https://api.linkedin.com/v2/ugcPosts', [
                'author'          => $author,
                'lifecycleState'  => 'PUBLISHED',
                'specificContent' => ['com.linkedin.ugc.ShareContent' => $content],
                'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
            ]);

        $id = $res->header('x-restli-id') ?: $res->json('id');

        return $this->outcome(
            $res->successful(),
            $id ? "https://www.linkedin.com/feed/update/{$id}" : null,
            $res->status(),
            $res->successful() ? null : $res->json('message'),
        );
    }

    /** @return array{ok: bool, url: ?string, status: int, error: ?string} */
    private function outcome(bool $ok, ?string $url, int $status, ?string $error): array
    {
        return ['ok' => $ok, 'url' => $url, 'status' => $status, 'error' => $error];
    }
}
