<?php

namespace Tests\Unit\Social;

use App\Support\Social\SocialNetworkPublisher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The social publishing in this codebase had no tests at all, in either module that used it.
 *
 * These cover the parts that decide whether a post reaches a network: which platforms are
 * claimed, what happens to a missing token or Page, and that trimming a long advert never
 * removes the link an applicant is supposed to follow.
 */
class SocialNetworkPublisherTest extends TestCase
{
    private SocialNetworkPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new SocialNetworkPublisher;
    }

    public function test_it_claims_only_the_three_networks_it_implements(): void
    {
        $this->assertSame(['facebook', 'x', 'linkedin'], SocialNetworkPublisher::SUPPORTED);

        foreach (['facebook', 'x', 'linkedin'] as $supported) {
            $this->assertTrue($this->publisher->supports($supported));
        }

        foreach (['instagram', 'whatsapp', 'youtube', 'threads', 'indeed'] as $not) {
            $this->assertFalse($this->publisher->supports($not), "{$not} should not claim support");
        }
    }

    public function test_an_unsupported_network_says_so_rather_than_failing_silently(): void
    {
        Http::fake();

        $result = $this->publisher->post('instagram', ['access_token' => 'token'], null, 'Hello');

        $this->assertFalse($result['ok']);
        $this->assertSame('Instagram publishing is not supported.', $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_missing_token_is_reported_before_anything_is_sent(): void
    {
        Http::fake();

        $result = $this->publisher->post('x', [], null, 'Hello');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no access token', $result['error']);
        Http::assertNothingSent();
    }

    public function test_facebook_without_a_page_is_refused_because_a_feed_post_needs_one(): void
    {
        Http::fake();

        $result = $this->publisher->post('facebook', ['access_token' => 'token'], null, 'Hello');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No Facebook Page', $result['error']);
        Http::assertNothingSent();
    }

    public function test_linkedin_without_an_author_urn_is_refused(): void
    {
        Http::fake();

        $result = $this->publisher->post('linkedin', ['access_token' => 'token'], null, 'Hello');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('author URN', $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_successful_facebook_post_returns_the_url_of_the_post(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123_456'], 200)]);

        $result = $this->publisher->post(
            'facebook',
            ['access_token' => 'page-token'],
            '123',
            'We are hiring',
            'https://hub.example/apply/abc',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('https://facebook.com/123_456', $result['url']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/123/feed')
                && $request['message'] === 'We are hiring'
                && $request['link'] === 'https://hub.example/apply/abc'
                && $request['access_token'] === 'page-token';
        });
    }

    public function test_a_successful_x_post_returns_the_status_url(): void
    {
        Http::fake(['api.twitter.com/*' => Http::response(['data' => ['id' => '999']], 201)]);

        $result = $this->publisher->post('x', ['access_token' => 'token'], null, 'We are hiring');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://x.com/i/web/status/999', $result['url']);
    }

    public function test_a_rejection_carries_the_networks_own_reason(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['error' => ['message' => 'Error validating access token: Session has expired.']],
            401,
        )]);

        $result = $this->publisher->post('facebook', ['access_token' => 'stale'], '123', 'We are hiring');

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('Session has expired', $result['error']);
    }

    public function test_a_network_that_cannot_be_reached_is_an_outcome_not_an_exception(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Connection timed out'));

        $result = $this->publisher->post('x', ['access_token' => 'token'], null, 'We are hiring');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Connection timed out', $result['error']);
    }

    public function test_trimming_a_long_advert_keeps_the_link_and_the_hashtags(): void
    {
        $body = str_repeat('Senior Developer wanted in Johannesburg. ', 40);
        $link = 'https://hub.example/apply/abc123';

        foreach (SocialNetworkPublisher::LIMITS as $platform => $limit) {
            $composed = $this->publisher->compose($body, ['hiring', 'jobs'], $link, $limit);

            $this->assertLessThanOrEqual($limit, mb_strlen($composed), "{$platform} exceeded its limit");
            $this->assertStringContainsString($link, $composed, "{$platform} lost the apply link");
            $this->assertStringContainsString('#hiring', $composed, "{$platform} lost the hashtags");
        }
    }

    public function test_a_hashtag_is_not_double_prefixed(): void
    {
        $composed = $this->publisher->compose('Hiring', ['#hiring', 'jobs'], null, 2000);

        $this->assertStringContainsString('#hiring', $composed);
        $this->assertStringNotContainsString('##', $composed);
    }

    public function test_an_advert_with_no_link_or_hashtags_is_just_the_body(): void
    {
        $this->assertSame('We are hiring', $this->publisher->compose('We are hiring', [], null, 2000));
    }
}
