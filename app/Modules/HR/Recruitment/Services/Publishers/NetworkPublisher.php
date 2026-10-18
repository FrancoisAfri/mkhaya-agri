<?php

namespace App\Modules\HR\Recruitment\Services\Publishers;

use App\Modules\HR\Recruitment\Contracts\SocialPublisher;
use App\Modules\HR\Recruitment\Models\SocialAccount;
use App\Modules\HR\Recruitment\Models\SocialPost;
use App\Modules\HR\Recruitment\Models\Vacancy;
use App\Support\Social\SocialNetworkPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Publishes a vacancy to a connected social account, for real.
 *
 * The three publishers in this folder used to extend NotConfiguredPublisher, which wrote a
 * failed post row saying the provider was "not yet configured". So the module offered
 * social publishing, accepted the account details, and could never reach a network.
 *
 * The networks themselves are spoken to by App\Support\Social\SocialNetworkPublisher,
 * which Content Marketing already used in anger — writing a second set of integrations
 * here is how the two copies drift apart. This class is the recruitment-shaped part: it
 * turns a vacancy into a post, and records what the network said.
 */
abstract class NetworkPublisher implements SocialPublisher
{
    public function __construct(protected readonly SocialNetworkPublisher $networks) {}

    /** The platform key the shared publisher knows this network by. */
    abstract protected function platform(): string;

    abstract protected function providerName(): string;

    public function publish(Vacancy $vacancy, SocialAccount $account): SocialPost
    {
        if (! $account->is_active) {
            return $this->record($vacancy, $account, false, null, null,
                "That {$this->providerName()} account is switched off — turn it back on before publishing.");
        }

        $token = (string) ($account->access_token ?? '');

        if ($token === '') {
            return $this->record($vacancy, $account, false, null, null,
                "That {$this->providerName()} account is not connected yet — use Connect to sign in.");
        }

        $platform = $this->platform();
        $meta = (array) ($account->meta ?? []);

        $result = $this->networks->post(
            platform: $platform,
            credentials: array_filter([
                'access_token' => $token,
                'page_id'      => $meta['page_id'] ?? null,
            ]),
            externalId: $meta['external_id'] ?? ($meta['page_id'] ?? null),
            text: $this->compose($vacancy, $platform),
            link: $this->applyUrl($vacancy),
        );

        return $this->record(
            $vacancy,
            $account,
            $result['ok'],
            $result['url'],
            $result['url'],
            $result['ok'] ? null : ($result['error'] ?: "{$this->providerName()} rejected the post (HTTP {$result['status']})."),
        );
    }

    /**
     * The advert itself.
     *
     * A vacancy's job description is written for a careers page, not a feed, so what goes
     * out is the headline facts and a link — the full text lives on the application page.
     */
    protected function compose(Vacancy $vacancy, string $platform): string
    {
        $facts = array_filter([
            $vacancy->department ? "Department: {$vacancy->department}" : null,
            $vacancy->job_type ? 'Type: '.Str::headline((string) $vacancy->job_type) : null,
            $vacancy->expires_at ? 'Closes: '.Carbon::parse($vacancy->expires_at)->format('d M Y') : null,
        ]);

        $body = collect([
            "We're hiring: {$vacancy->title}",
            $facts ? implode(' · ', $facts) : null,
            Str::limit(strip_tags((string) $vacancy->job_description), 400, '…'),
        ])->filter()->implode("\n\n");

        return $this->networks->compose(
            body: $body,
            hashtags: $this->hashtags($vacancy),
            link: $this->applyUrl($vacancy),
            limit: SocialNetworkPublisher::LIMITS[$platform] ?? 2000,
        );
    }

    /** @return array<int,string> */
    protected function hashtags(Vacancy $vacancy): array
    {
        return collect(['hiring', 'jobs', $vacancy->department])
            ->filter()
            ->map(fn ($tag) => Str::studly(Str::lower((string) $tag)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Where an applicant lands. Without a token there is no public page to send them to,
     * so the post goes out without a link rather than with a broken one.
     */
    protected function applyUrl(Vacancy $vacancy): ?string
    {
        return $vacancy->application_token
            ? route('recruitment.public.apply', ['token' => $vacancy->application_token])
            : null;
    }

    protected function record(
        Vacancy $vacancy,
        SocialAccount $account,
        bool $ok,
        ?string $externalId,
        ?string $externalUrl,
        ?string $error,
    ): SocialPost {
        return SocialPost::create([
            'vacancy_id'        => $vacancy->id,
            'social_account_id' => $account->id,
            'status'            => $ok ? 'published' : 'failed',
            'external_post_id'  => $externalId,
            'external_url'      => $externalUrl,
            'error_message'     => $error ? Str::limit($error, 500) : null,
            'posted_at'         => $ok ? now() : null,
        ]);
    }
}
