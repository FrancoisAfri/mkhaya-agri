<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmComment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmCommentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::for('api', fn () => \Illuminate\Cache\RateLimiting\Limit::none());
    }

    private function users(): array
    {
        $users = User::query()->take(2)->get();
        if ($users->count() < 2) $this->markTestSkipped('Need two users.');
        return [$users[0], $users[1]];
    }

    public function test_posting_a_comment_notifies_mentioned_users(): void
    {
        [$author, $mentioned] = $this->users();

        $this->actingAs($author)->post(route('crm.discussions.store'), [
            'entity_type' => 'deal', 'entity_id' => 99, 'body' => 'Look at this',
            'mentioned' => [$mentioned->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('crm_comments', ['entity_type' => 'deal', 'entity_id' => 99, 'author_id' => $author->id]);
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $mentioned->id, 'type' => 'mention']);
    }

    public function test_reply_is_threaded_and_notifies_parent_author(): void
    {
        [$author, $replier] = $this->users();

        $parent = CrmComment::create([
            'uuid' => Str::uuid(), 'entity_type' => 'deal', 'entity_id' => 5, 'body' => 'Parent', 'author_id' => $author->id,
        ]);

        $this->actingAs($replier)->post(route('crm.discussions.store'), [
            'entity_type' => 'deal', 'entity_id' => 5, 'parent_id' => $parent->id, 'body' => 'A reply',
        ])->assertRedirect();

        $reply = CrmComment::where('parent_id', $parent->id)->first();
        $this->assertNotNull($reply);
        $this->assertSame('A reply', $reply->body);
        // Parent author notified of the reply.
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $author->id, 'type' => 'mention']);
    }

    public function test_cannot_delete_others_comment(): void
    {
        [$author, $other] = $this->users();
        $comment = CrmComment::create([
            'uuid' => Str::uuid(), 'entity_type' => 'deal', 'entity_id' => 1, 'body' => 'Mine', 'author_id' => $author->id,
        ]);

        $this->actingAs($other)
            ->delete(route('crm.discussions.destroy', $comment->id))
            ->assertForbidden();

        $this->assertDatabaseHas('crm_comments', ['id' => $comment->id, 'deleted_at' => null]);
    }
}
