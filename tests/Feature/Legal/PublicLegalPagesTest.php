<?php

namespace Tests\Feature\Legal;

use App\Core\Legal\LegalDocuments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The privacy policy and data deletion pages, and the one property that matters about them.
 *
 * Facebook will not let an app leave development mode without a privacy policy URL it can fetch, and
 * requires a data deletion URL as well; LinkedIn requires the privacy policy URL on the app itself.
 * Both are fetched by a crawler with no session, so a page that happens to sit inside the auth
 * middleware is, to them, a page that does not exist — and the failure arrives as a rejected app
 * review days later rather than as anything visible here.
 */
class PublicLegalPagesTest extends TestCase
{
    use DatabaseTransactions;

    public static function pages(): array
    {
        return [['/privacy'], ['/data-deletion']];
    }

    #[DataProvider('pages')]
    public function test_a_visitor_with_no_session_can_read_it(string $url): void
    {
        $this->get($url)->assertOk();
    }

    #[DataProvider('pages')]
    public function test_it_is_not_pushed_through_a_login_redirect(string $url): void
    {
        // An assertOk alone would pass on a page that 200s a login screen.
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('Pages/Legal/Document'));
    }

    public function test_the_policy_names_who_is_answerable_and_how_to_reach_them(): void
    {
        // A reviewer looks for exactly this, and a policy naming no contact fails its own purpose.
        $operator = app(LegalDocuments::class)->operator();

        $this->assertNotEmpty($operator['name']);
        $this->assertNotEmpty($operator['privacy_email']);
        $this->get('/privacy')->assertSee($operator['privacy_email'], false);
    }

    public function test_the_policy_states_the_permissions_it_asks_for_and_what_they_are_for(): void
    {
        /*
         * This is the specific thing an app reviewer checks: that the permissions requested match a
         * stated purpose. Generic policy text is the usual reason a review is refused.
         */
        $response = $this->get('/privacy');

        foreach (['pages_manage_posts', 'pages_show_list', 'pages_read_engagement', 'w_member_social'] as $scope) {
            $response->assertSee($scope, false);
        }
    }

    public function test_the_policy_says_what_is_never_done_with_a_connected_account(): void
    {
        // The promises that make the permissions defensible. Dropping them while editing the wording
        // would quietly turn this into boilerplate.
        $this->get('/privacy')
            ->assertSee('do not read your personal feed', false)
            ->assertSee('do not sell personal information', false);
    }

    public function test_the_deletion_page_tells_somebody_what_to_press(): void
    {
        // Facebook accepts instructions in place of a callback, but they have to be followable.
        $this->get('/data-deletion')
            ->assertSee('Disconnect', false)
            ->assertSee('Apps and Websites', false);
    }

    public function test_an_unconfigured_detail_is_left_out_rather_than_printed_as_a_placeholder(): void
    {
        /*
         * A policy carrying "[Your Company Address]" reads as unfinished to the person whose job is
         * deciding whether we are a real operator.
         */
        config(['legal.address' => null, 'legal.registration_number' => null, 'legal.information_officer' => null]);

        $documents = app(LegalDocuments::class);
        $operator = $documents->operator();

        $this->assertNull($operator['address']);
        $this->assertNull($operator['registration_number']);
        $this->assertNull($operator['information_officer']);
        // A contact always survives, because an unreachable operator is the one thing the page may
        // not be. It falls back to the support address rather than to nothing.
        $this->assertNotEmpty($operator['privacy_email']);

        // Asserted against the document's own words, not the rendered HTML: the page also carries a
        // route manifest full of brackets, which would make a search of the markup meaningless.
        $prose = collect(array_merge($documents->privacy(), $documents->dataDeletion()))
            ->flatMap(fn ($section) => array_merge([$section['heading']], $section['body'] ?? [], $section['list'] ?? []))
            ->implode(' ');

        $this->assertDoesNotMatchRegularExpression('/\[[^\]]{2,}\]|\{\{|YOUR [A-Z]/', $prose);
        $this->get('/privacy')->assertOk();
    }

    #[DataProvider('pages')]
    public function test_the_page_name_resolves_to_a_file_that_exists(string $url): void
    {
        /*
         * The browser resolves a page by globbing resources/js and looking up `./{name}.vue`, so the
         * component name has to carry the directory — a file at resources/js/Pages/Legal/Document.vue
         * is "Pages/Legal/Document", not "Legal/Document". Get that wrong and the response is a
         * perfectly good 200 whose Vue throws "Page not found" in the browser.
         *
         * Nothing else catches it: asserting the component name only compares the string the server
         * sent against the string the test expected, which are wrong together.
         */
        $component = $this->get($url)->viewData('page')['component'];

        $this->assertFileExists(resource_path('js/' . $component . '.vue'),
            "Inertia renders '{$component}' but there is no such .vue file — the page will 200 and then "
            . 'fail to render.');
    }

    public function test_the_effective_date_is_shown_because_a_policy_without_one_cannot_be_relied_on(): void
    {
        $this->get('/privacy')->assertInertia(
            fn ($page) => $page->whereNot('effectiveDate', '')->where('kind', 'privacy'),
        );
    }
}
