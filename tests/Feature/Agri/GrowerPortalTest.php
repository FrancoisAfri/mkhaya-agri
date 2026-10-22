<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\Grower;
use App\Modules\Agri\Models\GrowerAccount;
use App\Modules\Agri\Models\GrowerAdvance;
use App\Modules\Agri\Models\GrowerCollection;
use App\Modules\Agri\Models\GrowerContract;
use App\Modules\Agri\Models\GrowerPortalEvent;
use App\Modules\Agri\Models\GrowerReport;
use App\Modules\Agri\Models\GrowerSettlement;
use App\Modules\Agri\Models\GrowerVisit;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Notifications\GrowerPortalMessage;
use App\Modules\Agri\Services\GrowerPortal;
use App\Modules\Agri\Services\Outgrowers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * The grower portal: the company invites a contracted grower, who signs in with a one-time code
 * (or a password) on a guard of their own and sees only their own contracts, deliveries, advances,
 * statements, visits and messages — another grower's ids are a 404, a withdrawn grower is out at
 * once, no staff route accepts them — and the same scoped figures answer their WhatsApp / SMS
 * commands, only from a number they proved by signing in.
 */
class GrowerPortalTest extends AgriTestCase
{
    private Farm $farmA;

    /** @var array<string, array{grower: Grower, contract: GrowerContract, settlement: GrowerSettlement}> */
    private array $g = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        config(['agri.messaging.secret' => 'test-secret']);
        $this->farmA = $this->farm('RIV');
        $this->member($this->manager, $this->farmA);
        $this->actingAs($this->manager);
        foreach (['G-001' => ['Sipho Dlamini', 1250.0], 'G-002' => ['Naledi Mokoena', 990.0]] as $code => [$name, $price]) {
            $this->g[$code] = $this->asCompany($this->company, function () use ($code, $name, $price) {
                $grower = app(Outgrowers::class)->register($this->farmA, ['name' => $name, 'code' => $code, 'hectares' => 8], $this->manager);
                $c = GrowerContract::create(['company_id' => $this->company->id, 'grower_id' => $grower->id, 'reference' => "GC-{$code}", 'product' => 'Lemons', 'hectares' => 8,
                    'expected_quantity' => 400, 'unit' => 'bins', 'price' => $price, 'grade_prices' => ['Class 1' => $price + 100, 'Class 2' => $price - 200], 'advance_limit' => 50000,
                    'status' => 'active', 'created_by' => $this->manager->id, 'approved_by' => $this->admin->id]);
                $s = GrowerSettlement::create(['company_id' => $this->company->id, 'grower_id' => $grower->id, 'reference' => "GS-{$code}", 'gross' => 40 * $price,
                    'advances_recovered' => 5000, 'net' => 40 * $price - 5000, 'status' => 'approved']);
                $lot = ProduceLot::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id, 'code' => "LOT-{$code}", 'kind' => 'collected',
                    'product' => 'Lemons', 'quantity' => 40, 'remaining' => 40, 'unit' => 'bins', 'status' => 'available', 'produced_on' => today()->subDays(3)]);
                GrowerCollection::create(['company_id' => $this->company->id, 'grower_id' => $grower->id, 'contract_id' => $c->id, 'collected_on' => today()->subDays(3),
                    'quantity' => 42, 'rejected' => 2, 'grade' => 'Class 1', 'price' => $price, 'value' => 40 * $price, 'lot_id' => $lot->id, 'settlement_id' => $s->id]);
                GrowerAdvance::create(['company_id' => $this->company->id, 'grower_id' => $grower->id, 'contract_id' => $c->id, 'kind' => 'cash', 'issued_on' => today()->subDays(30),
                    'value' => 8000, 'recovered' => 5000]);

                return ['grower' => $grower, 'contract' => $c, 'settlement' => $s];
            });
        }
    }

    private function invite(string $code, array $body): GrowerAccount
    {
        $this->actingAs($this->manager)->post(route('agri.outgrowers.portal.invite', $this->g[$code]['grower']->uuid), $body)->assertSessionHasNoErrors();

        return GrowerAccount::withoutGlobalScopes()->where('grower_id', $this->g[$code]['grower']->id)->sole();
    }

    /** The real sign-in: ask for a code, read it from the message sent, type it in. No staff session in play. */
    private function signIn(GrowerAccount $a, string $identifier, array $extra = []): void
    {
        Auth::forgetGuards();
        Notification::fake();
        $this->post(route('grower.portal.code'), ['identifier' => $identifier] + $extra)->assertSessionHasNoErrors();
        $code = null;
        Notification::assertSentTo($a, GrowerPortalMessage::class, function (GrowerPortalMessage $n) use (&$code) {
            $code = $n->code;

            return $n->code !== null;
        });
        $this->post(route('grower.portal.verify'), ['identifier' => $identifier, 'code' => $code])->assertRedirect(route('grower.portal.dashboard'));
    }

    private function message(string $from, string $text)
    {
        $body = json_encode(['from' => $from, 'text' => $text]);

        return $this->call('POST', route('api.agri.messages', 'whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AGRI_SIGNATURE' => hash_hmac('sha256', $body, 'test-secret')], $body);
    }

    public function test_an_invited_grower_signs_in_with_a_one_time_code_and_the_sign_ins_are_audited(): void
    {
        Notification::fake();
        $this->actingAs($this->worker)->post(route('agri.outgrowers.portal.invite', $this->g['G-001']['grower']->uuid), ['mobile' => '0821112222'])->assertForbidden();
        $a = $this->invite('G-001', ['mobile' => '082 111 2222', 'email' => 'sipho@growers.test', 'locale' => 'en']);
        $this->assertSame(['+27821112222', 'sipho@growers.test', GrowerAccount::INVITED], [$a->mobile, $a->email, $a->status]);
        Notification::assertSentTo($a, GrowerPortalMessage::class, fn ($n) => $n->channel === 'email' && str_contains((string) $n->actionUrl, '/grower/login'));
        // The same number cannot sign in as a second grower.
        $this->actingAs($this->manager)->post(route('agri.outgrowers.portal.invite', $this->g['G-002']['grower']->uuid), ['mobile' => '+27821112222'])->assertSessionHasErrors('mobile');

        // A wrong code is refused and audited; the right one signs in and proves the number.
        Auth::forgetGuards();
        Notification::fake();
        $this->post(route('grower.portal.code'), ['identifier' => '0821112222', 'via' => 'sms'])->assertSessionHasNoErrors();
        $this->post(route('grower.portal.verify'), ['identifier' => '0821112222', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->get(route('grower.portal.dashboard'))->assertRedirect(route('grower.portal.login'));
        $this->signIn($a, '0821112222', ['via' => 'sms']);
        $a->refresh();
        $this->assertSame(GrowerAccount::ACTIVE, $a->status);
        $this->assertNotNull($a->mobile_verified_at);
        $this->assertNull($a->otp_hash, 'A code is used once.');
        $this->get(route('grower.portal.dashboard'))->assertOk();
        $events = GrowerPortalEvent::withoutGlobalScopes()->where('account_id', $a->id)->pluck('event')->all();
        foreach (['invited', 'code_sent', 'sign_in_failed', 'signed_in'] as $e) {
            $this->assertContains($e, $events);
        }
        $this->assertNotNull(GrowerPortalEvent::withoutGlobalScopes()->where('event', 'signed_in')->value('ip'));

        // A password, once set inside, also signs in; an unknown address gets the same answer as a known one.
        $this->post(route('grower.portal.password'), ['password' => 'lemons-and-limes', 'password_confirmation' => 'lemons-and-limes'])->assertSessionHasNoErrors();
        $this->post(route('grower.portal.logout'));
        $this->get(route('grower.portal.dashboard'))->assertRedirect(route('grower.portal.login'));
        $this->post(route('grower.portal.password.login'), ['identifier' => 'sipho@growers.test', 'password' => 'wrong-password'])->assertSessionHasErrors('password');
        $this->post(route('grower.portal.password.login'), ['identifier' => 'sipho@growers.test', 'password' => 'lemons-and-limes'])->assertRedirect(route('grower.portal.dashboard'));
        $this->post(route('grower.portal.logout'));
        $this->post(route('grower.portal.code'), ['identifier' => 'nobody@growers.test'])->assertSessionHasNoErrors();

        // The staff screen shows who has access and what they did.
        $props = $this->actingAs($this->manager)->get(route('agri.outgrowers.index', ['farm' => $this->farmA->uuid]))->assertOk()->viewData('page')['props']['portal'];
        $this->assertSame('active', collect($props['accounts'])->firstWhere('grower', 'G-001')['status']);
        $this->assertContains('signed_in', collect($props['activity'])->pluck('event')->all());
    }

    public function test_a_grower_sees_only_their_own_contracts_deliveries_advances_and_statements(): void
    {
        $a = $this->invite('G-001', ['email' => 'sipho@growers.test']);
        $this->asCompany($this->company, fn () => GrowerVisit::create(['company_id' => $this->company->id, 'grower_id' => $this->g['G-002']['grower']->id,
            'planned_on' => today()->addDays(2), 'purpose' => 'inspection', 'status' => 'planned']));
        $this->actingAs($this->manager)->post(route('agri.outgrowers.visits.store', $this->g['G-001']['contract']->uuid), ['planned_on' => today()->addDays(5)->toDateString(),
            'purpose' => 'field_visit', 'notes' => 'Bring the spray record'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.outgrowers.notices.store'), ['farm_id' => $this->farmA->uuid, 'title' => 'Intake hours', 'body' => '07:00 to 15:00'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.outgrowers.notices.store'), ['farm_id' => $this->farmA->uuid, 'grower_id' => $this->g['G-002']['grower']->uuid,
            'title' => 'For Naledi only', 'body' => 'Private'])->assertSessionHasNoErrors();
        $this->signIn($a, 'sipho@growers.test');

        $props = fn (string $route, array $p = []) => $this->get(route($route, $p))->assertOk()->viewData('page')['props'];
        $this->assertSame(['GC-G-001'], collect($props('grower.portal.contracts')['contracts'])->pluck('reference')->all());
        $this->assertSame([['grade' => 'Class 1', 'price' => 1350.0], ['grade' => 'Class 2', 'price' => 1050.0]], $props('grower.portal.contracts')['contracts'][0]['grades']);
        $deliveries = $props('grower.portal.deliveries')['deliveries'];
        $this->assertSame(['LOT-G-001'], collect($deliveries)->pluck('lot')->all());
        $this->assertEqualsWithDelta(2.0, $deliveries[0]['rejected'], 0.001);
        $this->assertEqualsWithDelta(3000.0, $props('grower.portal.advances')['advances']['balance'], 0.01);
        $this->assertSame(['GS-G-001'], collect($props('grower.portal.statements')['settlements'])->pluck('reference')->all());
        $visits = $props('grower.portal.visits');
        $this->assertSame(['field_visit'], collect($visits['upcoming'])->pluck('purpose')->all(), 'Another grower\'s visit is not shown.');
        $this->assertSame(['Intake hours'], collect($props('grower.portal.messages')['notices'])->pluck('title')->all());
        $this->assertSame('G-001', $props('grower.portal.dashboard')['me']['code']);

        // Another grower's records by id: not found.
        $this->get(route('grower.portal.contracts.show', $this->g['G-002']['contract']->uuid))->assertNotFound();
        $this->get(route('grower.portal.statements.pdf', $this->g['G-002']['settlement']->uuid))->assertNotFound();
        $this->get(route('grower.portal.contracts.show', $this->g['G-001']['contract']->uuid))->assertOk();

        // The statement PDF: the whole account, and one settlement.
        $pdf = $this->get(route('grower.portal.statements.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringStartsWith('%PDF', $this->get(route('grower.portal.statements.pdf', $this->g['G-001']['settlement']->uuid))->assertOk()->getContent());
        $html = $this->asCompany($this->company, fn () => app(GrowerPortal::class)->statementHtml($this->g['G-001']['grower']));
        $this->assertStringContainsString('G-001', $html);
        $this->assertStringContainsString('LOT-G-001', $html);
        $this->assertStringNotContainsString('LOT-G-002', $html);
        $this->assertStringContainsString($this->company->name, $html, 'The statement is from the company.');
        $this->assertTrue(GrowerPortalEvent::withoutGlobalScopes()->where('event', 'statement')->exists(), 'Downloads are audited.');

        // A planting report with a photo, kept in the database, lands with the outgrower officer.
        $this->post(route('grower.portal.reports.store'), ['kind' => 'planting', 'contract_id' => $this->g['G-002']['contract']->uuid, 'details' => 'x'])->assertNotFound();
        $this->post(route('grower.portal.reports.store'), ['kind' => 'planting', 'contract_id' => $this->g['G-001']['contract']->uuid, 'planted_on' => today()->toDateString(),
            'hectares' => 2.5, 'details' => 'Replanted the east block', 'photo' => UploadedFile::fake()->image('block.jpg', 40, 40)])->assertSessionHasNoErrors();
        $r = GrowerReport::withoutGlobalScopes()->sole();
        $this->assertSame($this->g['G-001']['grower']->id, $r->grower_id);
        $this->assertNotEmpty($r->getRawOriginal('photo_data'));
        $this->actingAs($this->manager)->get(route('agri.outgrowers.reports.photo', $r->uuid))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->manager)->post(route('agri.outgrowers.reports.answer', $r->uuid), ['response' => 'Noted, thank you'])->assertSessionHasNoErrors();
        $this->assertSame('answered', $r->fresh()->status);
    }

    public function test_a_withdrawn_grower_is_out_at_once_and_no_staff_route_accepts_a_grower(): void
    {
        $a = $this->invite('G-001', ['email' => 'sipho@growers.test']);
        $this->signIn($a, 'sipho@growers.test');
        $this->get(route('grower.portal.dashboard'))->assertOk();

        // A grower's sign-in is not a staff sign-in: staff pages send them to the staff login.
        foreach ([route('agri.outgrowers.index'), route('agri.dashboard'), route('dashboard')] as $url) {
            $r = $this->get($url);
            $this->assertTrue($r->isRedirect() || in_array($r->status(), [401, 403], true), "{$url} answered {$r->status()} to a grower.");
            $this->assertFalse($r->isRedirect(route('grower.portal.dashboard')));
        }
        $this->assertNull(Auth::guard('web')->user());

        $this->actingAs($this->manager)->post(route('agri.outgrowers.portal.revoke', $a->uuid), ['reason' => 'Contract ended'])->assertSessionHasNoErrors();
        Auth::guard('web')->logout();
        $this->get(route('grower.portal.dashboard'))->assertRedirect(route('grower.portal.login'));
        $this->post(route('grower.portal.code'), ['identifier' => 'sipho@growers.test'])->assertSessionHasErrors('identifier');
        $this->assertTrue(GrowerPortalEvent::withoutGlobalScopes()->where('event', 'revoked')->exists());

        $this->actingAs($this->manager)->post(route('agri.outgrowers.portal.restore', $a->uuid))->assertSessionHasNoErrors();
        $this->signIn($a->fresh(), 'sipho@growers.test');
        $this->get(route('grower.portal.dashboard'))->assertOk();
    }

    public function test_whatsapp_commands_answer_only_for_a_proved_number_with_that_growers_own_figures(): void
    {
        $a = $this->invite('G-001', ['mobile' => '+27821112222', 'locale' => 'en']);
        $b = $this->invite('G-002', ['mobile' => '+27823334444', 'locale' => 'af']);
        Auth::forgetGuards();

        // Invited but not yet proved by a code sign-in: nothing but how to link.
        $this->assertStringContainsString('not linked', $this->message('+27821112222', 'balance')->json('reply'));
        $this->assertStringContainsString('not linked', $this->message('+27829999999', 'balance')->json('reply'));

        $this->signIn($a, '+27821112222', ['via' => 'whatsapp']);
        $this->post(route('grower.portal.logout'));
        $reply = $this->message('+27821112222', 'balance')->json('reply');
        $this->assertStringContainsString('G-001', $reply);
        $this->assertStringContainsString('3 000.00', $reply, 'Advances outstanding: R8 000 less R5 000 recovered.');
        $this->assertStringNotContainsString('G-002', $reply);
        $this->assertStringContainsString('LOT-G-001', $this->message('+27821112222', 'deliveries')->json('reply'));
        $this->assertStringContainsString('/grower/statements', $this->message('+27821112222', 'statement')->json('reply'));
        $this->assertStringContainsString('No visits', $this->message('+27821112222', 'next visit')->json('reply'));
        $this->actingAs($this->manager)->post(route('agri.outgrowers.visits.store', $this->g['G-001']['contract']->uuid), ['planned_on' => today()->addDays(4)->toDateString(),
            'purpose' => 'inspection'])->assertSessionHasNoErrors();
        Auth::forgetGuards();
        $this->assertStringContainsString('GC-G-001', $this->message('+27821112222', 'next visit')->json('reply'));
        $this->assertStringContainsString('"balance"', $this->message('+27821112222', 'hello')->json('reply'));
        $this->assertSame(6, GrowerPortalEvent::withoutGlobalScopes()->where('account_id', $a->id)->where('event', 'command')->count());

        // The second grower, in Afrikaans, sees their own.
        $this->signIn($b, '+27823334444', ['via' => 'sms']);
        $this->post(route('grower.portal.logout'));
        $af = $this->message('+27823334444', 'saldo')->json('reply');
        $this->assertStringContainsString('G-002', $af);
        $this->assertStringContainsString('voorskotte', $af);

        // Withdrawn: the number is no longer answered.
        $this->actingAs($this->manager)->post(route('agri.outgrowers.portal.revoke', $a->uuid))->assertSessionHasNoErrors();
        Auth::forgetGuards();
        $this->assertStringContainsString('not linked', $this->message('+27821112222', 'balance')->json('reply'));
    }
}
