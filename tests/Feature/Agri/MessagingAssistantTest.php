<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\MessagingContact;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §16C: the assistant over WhatsApp, SMS and voice — a number acts only once verified, as
 * its person and with their permissions, in their language; a resent message is answered once; an
 * unsigned call is refused.
 */
class MessagingAssistantTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        config(['agri.messaging.secret' => 'test-secret', 'agri.assistant_narration' => false]);
    }

    private function message(string $from, string $text, ?string $id = null, string $channel = 'whatsapp')
    {
        $body = json_encode(['from' => $from, 'text' => $text, 'id' => $id]);

        return $this->call('POST', route('api.agri.messages', $channel), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AGRI_SIGNATURE' => hash_hmac('sha256', $body, 'test-secret')], $body);
    }

    public function test_a_verified_number_uses_the_assistant_as_its_person_in_their_language(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->worker, $farm, 'worker');

        // Unsigned: refused. Unknown number: told how to link, nothing else.
        $this->postJson(route('api.agri.messages', 'whatsapp'), ['from' => '+27820000001', 'text' => 'help'])->assertUnauthorized();
        $this->assertStringContainsString('not linked', $this->message('+27820000001', 'help')->json('reply'));

        // The manager links a number in Afrikaans and proves it with the code.
        $code = $this->actingAs($this->manager)->postJson(route('agri.messaging.link'), ['channel' => 'whatsapp', 'address' => '+27820000001', 'language' => 'af'])->assertOk()->json('code');
        $this->assertStringContainsString('verkeerd', $this->message('+27820000001', '000000')->json('reply'));
        $this->assertStringContainsString('gekoppel', $this->message('+27820000001', $code)->json('reply'));
        $this->assertNotNull(MessagingContact::withoutGlobalScopes()->sole()->verified_at);

        $this->assertStringContainsString('skof', $this->message('+27820000001', 'hulp')->json('reply'));
        // A question is the same assistant, with the same permissions and farm scope.
        $reply = $this->message('+27820000001', 'Which machines are due for a service?', 'wamid.1')->json('reply');
        $this->assertNotEmpty($reply);
        $this->assertStringNotContainsString('not linked', $reply);
        // The same message delivered twice is answered once.
        $this->message('+27820000001', 'Which machines are due for a service?', 'wamid.1');
        $q = IntegrationEvent::withoutGlobalScopes()->where('integration', 'messaging')->where('idempotency_key', 'whatsapp:wamid.1');
        $this->assertSame(1, (clone $q)->count());
        $this->assertSame(1, (clone $q)->value('replays'));

        // A hazard by message goes into SHEQ; an observation needs the right permission.
        $this->assertStringContainsString('Gerapporteer', $this->message('+27820000001', 'gevaar Broken fence at the bull camp', 'wamid.2')->json('reply'));
        $this->assertSame(1, DB::table('sheq_hazards')->count());
        $this->message('+27820000001', 'obs Aphids on the east pivot', 'wamid.3');
        $this->assertSame(1, CropObservation::withoutGlobalScopes()->count());

        // The worker's number: their permissions, in English.
        $code = $this->actingAs($this->worker)->postJson(route('agri.messaging.link'), ['channel' => 'sms', 'address' => '+27820000002'])->assertOk()->json('code');
        $this->message('+27820000002', $code, null, 'sms');
        $this->assertStringContainsString('permissions', $this->message('+27820000002', 'What needs my attention on the farms today?', 's1', 'sms')->json('reply'));
        $this->assertStringContainsString('no shifts', $this->message('+27820000002', 'shift', 's2', 'sms')->json('reply'));
        $this->assertStringContainsString('alerts will be sent', $this->message('+27820000002', 'alerts on', 's3', 'sms')->json('reply'));
        $this->assertTrue((bool) MessagingContact::withoutGlobalScopes()->where('address', '+27820000002')->value('alerts'));
    }
}
