<?php

namespace Tests\Feature\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinPostingLimit;
use App\Modules\Finance\Accounting\Services\PostingAuthorizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PostingLimitTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => PostingAuthorizer::PERMISSION, 'guard_name' => 'web']);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function user(): User
    {
        return User::query()->first() ?? User::factory()->create();
    }

    public function test_user_without_permission_cannot_post(): void
    {
        $user = $this->user();
        $user->revokePermissionTo(PostingAuthorizer::PERMISSION);

        $this->expectException(PostingException::class);
        app(PostingAuthorizer::class)->assertCanPost($user, 100);
    }

    public function test_permission_without_limit_can_post_any_amount(): void
    {
        $user = $this->user();
        $user->givePermissionTo(PostingAuthorizer::PERMISSION);

        app(PostingAuthorizer::class)->assertCanPost($user->fresh(), 999999);
        $this->assertTrue(true); // no exception
    }

    public function test_amount_over_limit_is_rejected(): void
    {
        $user = $this->user();
        $user->givePermissionTo(PostingAuthorizer::PERMISSION);
        FinPostingLimit::updateOrCreate(
            ['user_id' => $user->id, 'company_id' => null],
            ['max_amount' => 5000, 'can_post' => true],
        );

        $auth = app(PostingAuthorizer::class);
        $auth->assertCanPost($user->fresh(), 5000);   // at the limit — ok

        $this->expectException(PostingException::class);
        $auth->assertCanPost($user->fresh(), 5000.01); // over — rejected
    }

    public function test_can_post_flag_off_blocks_even_within_amount(): void
    {
        $user = $this->user();
        $user->givePermissionTo(PostingAuthorizer::PERMISSION);
        FinPostingLimit::updateOrCreate(
            ['user_id' => $user->id, 'company_id' => null],
            ['max_amount' => null, 'can_post' => false],
        );

        $this->expectException(PostingException::class);
        app(PostingAuthorizer::class)->assertCanPost($user->fresh(), 1);
    }
}
