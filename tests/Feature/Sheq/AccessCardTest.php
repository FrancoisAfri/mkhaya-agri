<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Http\Middleware\SetLocale;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\FileService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The printable access card (CON-11, CON-13).
 *
 * The QR on its own was never the card. A guard at a boom matches the face in front of them to the
 * register, and a code they have to scan to learn anything is no help when the queue is twenty deep
 * and the scanner is flat. So the card prints the photograph, the name, the trade, the site and the
 * project, and the code stays the live check for when the answer matters.
 *
 * Two properties these tests exist to hold:
 *
 * **It prints what a guard needs, and only that.** No ID number, no medical fact, no certificate.
 * A card is photographed and forwarded; whatever is on it is published.
 *
 * **It prints without the network.** The photograph and the QR are inlined as data URIs, because
 * the card is printed from whatever laptop is in the site office and a broken image on an access
 * card reads as a card that has been tampered with.
 */
class AccessCardTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.contractor.manage']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'C'.Str::random(5), 'name' => 'Secunda Refinery', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING', 'site_id' => $this->siteId,
        ], $this->user->id);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'card-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }

    /** @param array<string,mixed> $overrides */
    private function worker(array $overrides = []): object
    {
        $id = DB::table('sheq_contractor_workers')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => 'Thabo Mokoena',
            'trade' => 'SCAFFOLDING',
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return DB::table('sheq_contractor_workers')->where('id', $id)->first();
    }

    private function cardFor(string $uuid): string
    {
        return $this->actingAs($this->user)
            ->get("/sheq/pass/workers/{$uuid}/card")
            ->assertOk()
            ->getContent();
    }

    // ── What it prints ────────────────────────────────────────────────────────────────────────

    public function test_the_card_prints_the_name_trade_site_and_project(): void
    {
        /*
         * The whole request, in one assertion each. A card missing any one of these sends a guard
         * back to scanning every person in the queue, which is the thing the printed detail exists
         * to avoid.
         */
        $projectId = $this->project('Tank Farm Shutdown');

        $worker = $this->worker(['site_id' => $this->siteId, 'project_id' => $projectId]);

        $html = $this->cardFor($worker->uuid);

        $this->assertStringContainsString('Thabo Mokoena', $html);
        $this->assertStringContainsString('Scaffolding', $html, 'The trade is printed as a label, not as a code.');
        $this->assertStringContainsString('Secunda Refinery', $html);
        $this->assertStringContainsString('Acme Scaffolding', $html, 'The employer is printed.');

        if ($projectId === null) {
            // Only reachable where Project Management is not installed. Everything above still
            // held, and the project line is the one thing that cannot be asserted there.
            $this->assertStringContainsString(__('sheq-pass.card.not_set'), $html);
        } else {
            $this->assertStringContainsString('Tank Farm Shutdown', $html);
        }
    }

    public function test_a_worker_with_no_site_of_their_own_prints_the_contractor_site(): void
    {
        // Setting a site on every one of a crew of forty is how a field goes unfilled. The
        // contractor's site is nearly always the truth, so it is what the card falls back to.
        $html = $this->cardFor($this->worker(['site_id' => null])->uuid);

        $this->assertStringContainsString('Secunda Refinery', $html);
    }

    public function test_the_card_carries_the_qr_and_the_photograph_inline(): void
    {
        /*
         * Both inlined, because the card is printed from whatever machine is in the site office
         * and a card with a broken image reads as one that has been tampered with. A linked image
         * would also be behind the session, so it would never print for anybody else at all.
         */
        $worker = $this->worker();

        app(FileService::class)->store($this->companyId,
            UploadedFile::fake()->image('face.jpg', 200, 260), [
                'subject_type' => 'CONTRACTOR_WORKER', 'subject_id' => $worker->id,
                'category' => 'PHOTO',
            ], $this->user->id);

        $html = $this->cardFor($worker->uuid);

        $this->assertStringContainsString('data:image/', $html,
            'The photograph has to be inlined, not linked.');
        $this->assertStringContainsString('<svg', $html, 'The QR has to be inlined, not linked.');
        $this->assertStringNotContainsString('/qr.svg', $html,
            'The card linked the QR image route, which needs a session and will not print.');
    }

    public function test_a_missing_photograph_says_so_rather_than_leaving_a_hole(): void
    {
        // A blank rectangle on an access card reads as a printing fault, and the card is still
        // useful without a face — the code is the live check either way.
        $html = $this->cardFor($this->worker()->uuid);

        $this->assertStringContainsString(__('sheq-pass.card.no_photo'), $html);
        $this->assertStringContainsString('<svg', $html, 'No photograph must not cost the code.');
    }

    public function test_the_card_is_cr80_so_it_fits_a_lanyard(): void
    {
        // The size is the point of a printable card: a holder, a lanyard and a slot punch are all
        // bought to this dimension, and a card at A4 scale is a card nobody carries.
        $html = $this->cardFor($this->worker()->uuid);

        $this->assertStringContainsString('85.6mm', $html);
        $this->assertStringContainsString('54mm', $html);
    }

    // ── What it must never print ──────────────────────────────────────────────────────────────

    public function test_the_card_carries_no_identity_number_or_medical_detail(): void
    {
        /*
         * The property the whole design rests on. A card is photographed and forwarded on WhatsApp,
         * so whatever is on it is published — and an ID number on an access card is an ID number
         * on a photocopier. The live answer stays behind the code.
         */
        $worker = $this->worker(['site_id' => $this->siteId]);

        if (Schema::hasColumn('sheq_contractor_workers', 'id_number')) {
            DB::table('sheq_contractor_workers')->where('id', $worker->id)
                ->update(['id_number' => '8001015800089']);
        }

        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'MEDICAL_CERTIFICATE',
                'reference' => 'MED-SECRET-4471',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ], $this->user->id);

        $html = $this->cardFor($worker->uuid);

        $this->assertStringNotContainsString('8001015800089', $html);
        $this->assertStringNotContainsString('MED-SECRET-4471', $html);
        $this->assertStringNotContainsString('MEDICAL_CERTIFICATE', $html);
    }

    public function test_the_card_does_not_claim_the_holder_may_work(): void
    {
        // A printed card cannot know: compliance lapses mid-shift and the card keeps saying
        // whatever it said when it was printed. So it says what it is — identification — and
        // points at the code for the answer.
        $html = $this->cardFor($this->worker()->uuid);

        $this->assertStringContainsString(__('sheq-pass.card.back_title'), $html,
            'The card has to say in so many words that it is not permission to work.');
    }

    // ── Who may print one ─────────────────────────────────────────────────────────────────────

    public function test_printing_a_card_needs_the_contractor_permission(): void
    {
        $worker = $this->worker();

        $this->actingAs($this->userWith([]))
            ->get("/sheq/pass/workers/{$worker->uuid}/card")
            ->assertForbidden();
    }

    public function test_another_tenant_worker_cannot_be_printed(): void
    {
        /*
         * The card is keyed by uuid, and a uuid is not a secret — it travels in URLs and in
         * exports. Without the company clause on the lookup, guessing one would print another
         * tenant's employee onto this tenant's card stock.
         */
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sheq-card-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignContractor = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'name' => 'Someone Else’s Contractor', 'access_status' => 'BLOCKED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignUuid = (string) Str::uuid();

        DB::table('sheq_contractor_workers')->insert([
            'uuid' => $foreignUuid, 'company_id' => $otherCompany,
            'contractor_id' => $foreignContractor, 'name' => 'Not Our Employee',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get("/sheq/pass/workers/{$foreignUuid}/card");

        $this->assertNotSame(200, $response->getStatusCode(),
            'A worker belonging to another company was printed onto this company’s card.');
        $this->assertStringNotContainsString('Not Our Employee', $response->getContent());
    }

    // ── Where the placement comes from ────────────────────────────────────────────────────────

    public function test_a_worker_can_be_placed_on_a_site_and_a_project_when_registered(): void
    {
        $projectId = $this->project('Conveyor Replacement');

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/workers", array_filter([
                'name' => 'Nomsa Dlamini', 'trade' => 'ELECTRICAL',
                'site_id' => $this->siteId, 'project_id' => $projectId,
            ], fn ($v) => $v !== null))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = DB::table('sheq_contractor_workers')
            ->where('company_id', $this->companyId)->where('name', 'Nomsa Dlamini')
            ->first(['site_id', 'project_id']);

        $this->assertNotNull($row);
        $this->assertSame($this->siteId, (int) $row->site_id);

        if ($projectId !== null) {
            $this->assertSame($projectId, (int) $row->project_id);
        }
    }

    public function test_a_site_from_another_company_cannot_be_stamped_on_a_worker(): void
    {
        // The picker is scoped, but the request is what decides — and a foreign site id would be
        // printed on the card as this company's site.
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sheq-card-site-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignSite = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'code' => 'X'.Str::random(5), 'name' => 'Not Our Site', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/workers", [
                'name' => 'Sipho Ndlovu', 'site_id' => $foreignSite,
            ])
            ->assertSessionHasErrors('site_id');
    }

    public function test_the_contractor_screen_offers_sites_and_projects_to_place_a_worker_on(): void
    {
        // An engine with no door: the columns and the card are useless if nothing on the screen
        // ever fills them.
        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayHasKey('sites', $props);
        $this->assertContains($this->siteId,
            collect($props['sites'])->pluck('value')->map(fn ($v) => (int) $v)->all());

        // Present even where Project Management is not installed, because the page reads its
        // length to decide whether to show the field at all.
        $this->assertArrayHasKey('projects', $props);
    }

    public function test_a_finished_project_is_not_offered_for_a_new_placement(): void
    {
        if (! Schema::hasTable('pm_projects')) {
            $this->markTestSkipped('Project Management is not installed on this instance.');
        }

        $open = $this->project('Live Shutdown');
        $done = $this->project('Finished Last Year', 'completed');

        $offered = collect(
            $this->actingAs($this->user)
                ->get("/sheq/contractors/{$this->contractor['uuid']}")
                ->assertOk()
                ->viewData('page')['props']['projects']
        )->pluck('value')->map(fn ($v) => (int) $v)->all();

        $this->assertContains($open, $offered);
        $this->assertNotContains($done, $offered,
            'A finished project was offered, and a card printed against it names work that is over.');
    }

    // ── The contractor's own card ─────────────────────────────────────────────────────────────

    public function test_a_contractor_card_carries_the_company_not_a_missing_face(): void
    {
        /*
         * A contractor is a company. Printing an empty photograph frame on its card has a guard
         * looking for a person who does not exist, and the registration number is what they can
         * actually match against a delivery note or an order.
         */
        DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->update(['registration_number' => '2014/112233/07']);

        $html = $this->actingAs($this->user)
            ->get("/sheq/pass/contractors/{$this->contractor['uuid']}/card")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Acme Scaffolding', $html);
        $this->assertStringContainsString('2014/112233/07', $html);
        $this->assertStringNotContainsString(__('sheq-pass.card.no_photo'), $html,
            'A company card printed an empty photograph frame.');
        $this->assertStringNotContainsString(__('sheq-pass.card.project'), $html,
            'A contractor is not placed on a project; only their workers are.');
    }

    // ── The photograph, end to end ────────────────────────────────────────────────────────────

    public function test_a_photograph_added_on_the_form_reaches_the_card(): void
    {
        /*
         * The whole path in one test, because every piece of it existed and the middle was broken:
         * the form had a photograph field, the crew list had an avatar and the card had a face,
         * but `CONTRACTOR_WORKER` was not an allowed file subject — so the upload threw a 500 and
         * no worker photograph had ever been stored.
         */
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/workers", [
                'name' => 'Lerato Khumalo',
                'photo' => UploadedFile::fake()->image('lerato.jpg', 200, 260),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $uuid = DB::table('sheq_contractor_workers')
            ->where('company_id', $this->companyId)->where('name', 'Lerato Khumalo')
            ->value('uuid');

        $this->assertNotNull($uuid, 'Adding a worker with a photograph failed outright.');

        $this->assertStringContainsString('data:image/', $this->cardFor($uuid),
            'The photograph was accepted by the form but never reached the card.');
    }

    public function test_the_crew_list_shows_the_photograph_it_stored(): void
    {
        // The other reader of the same file. A photograph that prints on the card but leaves the
        // avatar blank means two different lookups, and one of them is wrong.
        $worker = $this->worker();

        app(FileService::class)->store($this->companyId,
            UploadedFile::fake()->image('face.jpg', 200, 260), [
                'subject_type' => 'CONTRACTOR_WORKER', 'subject_id' => $worker->id,
                'category' => 'PHOTO',
            ], $this->user->id);

        $workers = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}")
            ->assertOk()
            ->viewData('page')['props']['workers'];

        $row = collect($workers)->firstWhere('uuid', $worker->uuid);

        $this->assertNotNull($row);
        $this->assertNotNull($row['photo_uuid'] ?? null,
            'The crew list shows no photograph for a worker who has one.');
    }

    // ── Twelve languages ──────────────────────────────────────────────────────────────────────

    public function test_every_label_the_card_prints_exists_in_every_language(): void
    {
        /*
         * Read out of the template rather than listed here, because a list is the thing that goes
         * stale. A key added to the card and to English only prints the dotted key itself on a
         * card somebody carries for a year — `__()` has no failure mode, so nothing else would
         * ever say so.
         */
        $template = File::get(resource_path('views/sheq/access-card.blade.php'));

        preg_match_all("/__\('(sheq-pass\.card\.[a-z_]+)'/", $template, $matches);

        $keys = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($keys, 'No translated labels were found in the card template.');

        foreach (SetLocale::supported() as $locale) {
            $file = require base_path("lang/{$locale}/sheq-pass.php");

            foreach ($keys as $key) {
                $leaf = substr($key, strlen('sheq-pass.card.'));

                $this->assertArrayHasKey($leaf, $file['card'] ?? [],
                    "The card prints '{$key}' and '{$locale}' has no translation for it, so that "
                    .'card prints the key itself.');
                $this->assertNotSame('', trim((string) $file['card'][$leaf]),
                    "'{$key}' is empty in '{$locale}'.");
            }
        }
    }

    public function test_the_dates_on_the_card_are_not_dropped_by_a_translation(): void
    {
        /*
         * `:date` is the only placeholder on the card and it is in two strings. A translation that
         * drops it still passes every key-parity sweep there is and prints "Issued" with no date
         * on it, which is exactly the thing an expiry on a card exists to say.
         */
        foreach (SetLocale::supported() as $locale) {
            $card = (require base_path("lang/{$locale}/sheq-pass.php"))['card'];

            foreach (['issued', 'valid_until'] as $key) {
                $this->assertStringContainsString(':date', $card[$key],
                    "'{$key}' in '{$locale}' lost its :date placeholder, so the card prints no "
                    .'date at all.');

                // Rendered, not matched: a placeholder spelled `: date` or `:Date` passes a regex
                // and still prints itself.
                $this->assertStringContainsString('2026-03-04',
                    trans('sheq-pass.card.'.$key, ['date' => '2026-03-04'], $locale),
                    "'{$key}' in '{$locale}' does not render the date it was given.");
            }
        }
    }

    /**
     * A project in Project Management, or null where that module is not installed.
     *
     * Null rather than a skip: most of what these tests assert is true with or without projects,
     * and skipping the whole card because one optional module is absent would leave the card
     * untested on exactly the installations that have it hardest.
     */
    private function project(string $name, string $status = 'active'): ?int
    {
        if (! Schema::hasTable('pm_projects')) {
            return null;
        }

        return (int) DB::table('pm_projects')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'PRJ-'.Str::upper(Str::random(6)), 'name' => $name,
            'status' => $status, 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            'calendar_id' => $this->calendarId(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A working calendar for the project to hang off, created if the tenant has none.
     *
     * Project Management's own NOT NULL foreign key, which SHEQ never touches. Borrowed first and
     * created second rather than skipped: an empty `pm_calendars` would otherwise make every
     * project in this file null, and the assertions guarded on a project would quietly stop
     * asserting anything while the suite still reported green.
     */
    private function calendarId(): int
    {
        $existing = DB::table('pm_calendars')->where('company_id', $this->companyId)->min('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('pm_calendars')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Standard week', 'timezone' => 'Africa/Johannesburg',
            'work_week' => json_encode(['mon', 'tue', 'wed', 'thu', 'fri']),
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
