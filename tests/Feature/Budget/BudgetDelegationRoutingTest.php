<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Controllers\BudgetDelegationController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The screens in this module only work if the routes they name exist.
 *
 * Three features here were built end to end and never once ran: approval delegation (controller,
 * model, the approval engine's handling of a delegate, and the dialog on the Approvals screen) and
 * re-parenting a budget for the roll-up. Every part existed except the route, so Ziggy threw in the
 * browser the moment somebody pressed Save — before a request was ever sent, which is why nothing
 * appeared in any log and `budget_approval_delegations` had no rows at all.
 *
 * Nothing caught it because every layer tested fine on its own. What was missing was the line that
 * joins them, and only a check that reads the screens can see that.
 */
class BudgetDelegationRoutingTest extends TestCase
{
    use DatabaseTransactions;

    /** Route names the Budget screens call. A name here that is not registered is a dead button. */
    private const REQUIRED = [
        'budgets.approvals.delegations.store',
        'budgets.approvals.delegations.destroy',
        'budgets.parent.set',
    ];

    public function test_every_route_the_budget_screens_name_is_registered(): void
    {
        foreach (self::REQUIRED as $name) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($name),
                "The Budget screens call route('{$name}') but no such route is registered — the control "
                . 'throws in the browser before anything reaches the server.',
            );
        }
    }

    public function test_delegation_is_reachable_from_end_to_end_not_just_present(): void
    {
        /*
         * Asserting the route exists is not enough on its own: a route pointing at a method that does
         * not exist is the same dead button. This ties the name to the class that has to answer it.
         */
        $store = Route::getRoutes()->getByName('budgets.approvals.delegations.store');
        $destroy = Route::getRoutes()->getByName('budgets.approvals.delegations.destroy');

        $this->assertSame(BudgetDelegationController::class . '@store', $store->getActionName());
        $this->assertSame(BudgetDelegationController::class . '@destroy', $destroy->getActionName());
        $this->assertTrue(method_exists(BudgetDelegationController::class, 'store'));
        $this->assertTrue(method_exists(BudgetDelegationController::class, 'destroy'));
    }

    public function test_the_delegation_routes_key_on_the_uuid_the_screen_is_given(): void
    {
        // The Approvals screen exposes each delegation's uuid as `id`, and the controller looks the
        // row up by uuid. A numeric-id route here would 404 every revoke.
        $destroy = Route::getRoutes()->getByName('budgets.approvals.delegations.destroy');

        $this->assertStringContainsString('{uuid}', $destroy->uri());
    }
}
