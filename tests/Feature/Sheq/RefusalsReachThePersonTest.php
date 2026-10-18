<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use App\Modules\Operations\Sheq\Exceptions\RefusedRule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A refused business rule is shown to the person, not thrown at them.
 *
 * Every service in this module signals a refusal with a message already written for whoever is
 * reading it. The API has turned those into problem documents since it was written. On a screen the
 * identical refusal rendered Laravel's error page — a red "Internal Server Error", a class name, a
 * file path and a stack trace — for a sentence that already said what to do about it. A visitor
 * could not be signed in because no approval workflow was configured, and the person was shown
 * `RuntimeException at ApprovalService.php:58`.
 *
 * These tests pin both halves: what must be shown, and what must still be a 500. The second half is
 * the one that matters most — a renderer that catches too much turns a leaked file path into a
 * message on a screen.
 */
class RefusalsReachThePersonTest extends TestCase
{
    use DatabaseTransactions;

    /** A request the way a browser makes it, bound so `back()` and `withInput()` can read it. */
    private function screen(string $uri, array $input = []): Request
    {
        $request = Request::create($uri, 'POST', $input);
        $request->headers->set('Accept', 'text/html');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        return $request;
    }

    /** The exact production refusal: a visitor signed in where no approval workflow exists. */
    private function productionRefusal(): \Throwable
    {
        try {
            app(ApprovalService::class)->open(999_999, ['subject_type' => 'visitor']);
        } catch (\Throwable $e) {
            return $e;
        }

        $this->fail('Opening an approval with no configured workflow should have been refused.');
    }

    public function test_the_refusal_that_reached_production_now_goes_back_to_the_screen(): void
    {
        $exception = $this->productionRefusal();

        $this->assertStringContainsString(
            'app/Modules/Operations/Sheq/',
            str_replace('\\', '/', $exception->getFile()),
            'the fixture no longer throws from this module, so this test proves nothing',
        );

        $request = $this->screen('/sheq/visitors', ['visitor_name' => 'Thabo M', 'company' => 'Acme']);
        $response = app(ExceptionHandler::class)->render($request, $exception);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        // The sentence reaches the snackbar the layout already shows everywhere.
        $this->assertStringContainsString(
            'No approval workflow is configured',
            (string) $response->getSession()?->get('error'),
        );

        // And an errors bag, so a form can show it inline rather than only in passing.
        $this->assertStringContainsString(
            'No approval workflow is configured',
            (string) $response->getSession()?->get('errors')?->getBag('default')->first('sheq'),
        );

        // What they typed survives. Retyping a visitor's details is how somebody learns not to
        // use the system.
        $this->assertSame(
            ['visitor_name' => 'Thabo M', 'company' => 'Acme'],
            $response->getSession()?->getOldInput(),
        );
    }

    public function test_a_refusal_reached_from_another_modules_screen_is_shown_too(): void
    {
        // Fleet and Assets raise incidents and actions through SHEQ's bridges, so the same
        // refusal is reachable from their screens.
        $request = $this->screen('/fleet/bookings');
        $response = app(ExceptionHandler::class)->render($request, $this->productionRefusal());

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function test_the_api_keeps_its_own_problem_document(): void
    {
        $request = Request::create('/api/v1/sheq/visitors', 'POST');
        $request->headers->set('Accept', 'application/json');
        app()->instance('request', $request);

        $response = app(ExceptionHandler::class)->render($request, $this->productionRefusal());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('problem+json', (string) $response->headers->get('Content-Type'));
    }

    #[DataProvider('notRefusals')]
    public function test_what_must_still_be_an_error(\Throwable $exception, string $why): void
    {
        $request = $this->screen('/sheq/visitors');
        $response = app(ExceptionHandler::class)->render($request, $exception);

        $this->assertNotInstanceOf(RedirectResponse::class, $response, $why);
    }

    public static function notRefusals(): array
    {
        return [
            'an internal failure is not a refusal' => [
                new \TypeError('Argument #1 must be of type int, string given'),
                'A TypeError is a bug, not something to tell a user about.',
            ],
            'a refusal we did not write is not shown' => [
                new \RuntimeException('/var/www/vendor/secret/path'),
                'A RuntimeException from outside this module may carry a path, a query or a '
                .'credential, and was not written for anybody to read.',
            ],
        ];
    }

    public function test_the_renderer_declines_anything_that_is_not_its_business(): void
    {
        $request = $this->screen('/sheq/visitors');

        // Thrown outside the module.
        $this->assertNull(RefusedRule::render(new \RuntimeException('elsewhere'), $request));

        // A refusal with nothing to say is not worth showing.
        $this->assertNull(RefusedRule::render(new \TypeError(''), $request));
    }
}
