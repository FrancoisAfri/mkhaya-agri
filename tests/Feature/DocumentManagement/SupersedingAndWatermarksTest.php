<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\WatermarkService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Two things a repository needs once documents start leaving it by other means.
 *
 * A watermark naming whoever is looking. Read-only stops the download and does nothing about a
 * photograph of the screen, which is how sensitive documents actually leave — the only defence is
 * making the copy identify the person who was looking at it.
 *
 * And superseding. Next year's policy is not version 2 of this year's: both exist, both are cited,
 * and people hold links to the old one. Without this the only options were leaving it looking
 * current or archiving it, and archiving hides the document somebody was sent a link to.
 */
class SupersedingAndWatermarksTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();
    }

    private function user(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'sup+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        $user = $user->fresh();

        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => ModuleHubs::SUPERUSER],
        );

        return $user;
    }

    private function editor(): User
    {
        return $this->user('document.view', 'document.update', 'document.download');
    }

    private function pdf(string $body = 'policy'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n{$body}\n%%EOF");

        return new UploadedFile($path, 'policy.pdf', 'application/pdf', null, true);
    }

    /**
     * A PDF the stamper can actually import.
     *
     * The toy PDF above is enough for the type guard and nothing else — FPDI cannot parse it, so a
     * watermark test built on it would exercise the fallback path and pass while proving the
     * opposite of what it claims.
     */
    private function realPdf(): UploadedFile
    {
        if (! class_exists(\Mpdf\Mpdf::class)) {
            $this->markTestSkipped('mpdf is not installed, so nothing can be stamped here.');
        }

        $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
        $mpdf->WriteHTML('<p>Leave policy</p>');

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $mpdf->Output('', 'S'));

        return new UploadedFile($path, 'policy.pdf', 'application/pdf', null, true);
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A policy ' . Str::random(4),
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ], $attributes));
    }

    private function settings(array $values): void
    {
        DocumentSetting::current()->forceFill($values)->save();

        // The settings are cached per request; the next read has to see what was just written.
        Cache::flush();
    }

    // ── §19 Superseding ──────────────────────────────────────────────────────

    public function test_a_superseded_document_is_still_readable(): void
    {
        /*
         * The whole reason this is not archiving. People hold links to the old policy, and a link
         * that leads nowhere teaches them the repository is unreliable rather than that the policy
         * has moved on.
         */
        $editor = $this->editor();
        $old = $this->document();
        $new = $this->document();

        app(DocumentService::class)->supersede($old, $new);

        $this->assertTrue($editor->can('view', $old->fresh()));
    }

    public function test_a_superseded_document_is_not_in_force(): void
    {
        $old = $this->document();
        $new = $this->document();

        app(DocumentService::class)->supersede($old, $new);

        $this->assertFalse($old->fresh()->isInForce());
        $this->assertTrue($new->fresh()->isInForce());
    }

    public function test_it_points_at_what_replaced_it(): void
    {
        // Saying "this is out of date" without saying where the current one is leaves somebody
        // searching, and searching is how they end up reading the wrong one again.
        $old = $this->document();
        $new = $this->document(['title' => 'Leave policy 2027']);

        app(DocumentService::class)->supersede($old, $new);

        $this->assertSame($new->id, (int) $old->fresh()->superseded_by_id);
        $this->assertSame('Leave policy 2027', $old->fresh()->supersededBy->title);
    }

    public function test_both_documents_record_it(): void
    {
        // The old one so its readers can see when it stopped applying; the new one so "what did
        // this replace" has an answer.
        $editor = $this->editor();
        $this->actingAs($editor);

        $old = $this->document();
        $new = $this->document();

        app(DocumentService::class)->supersede($old, $new);

        $this->assertTrue(DocumentEvent::where('document_id', $old->id)->where('action', DocumentEvent::SUPERSEDED)->exists());
        $this->assertTrue(DocumentEvent::where('document_id', $new->id)->where('action', DocumentEvent::SUPERSEDES)->exists());
    }

    public function test_a_document_cannot_replace_itself(): void
    {
        $document = $this->document();

        $result = app(DocumentService::class)->supersede($document, $document);

        $this->assertFalse($result['ok']);
        $this->assertFalse($document->fresh()->isSuperseded());
    }

    public function test_it_refuses_to_point_at_a_document_that_is_itself_replaced(): void
    {
        /*
         * Otherwise a chain forms — A replaced by B replaced by C — and the banner on A sends the
         * reader to B, which sends them to C. The reader has to land on the one in force.
         */
        $a = $this->document();
        $b = $this->document();
        $c = $this->document();

        app(DocumentService::class)->supersede($b, $c);

        $result = app(DocumentService::class)->supersede($a, $b->fresh());

        $this->assertFalse($result['ok']);
        $this->assertFalse($a->fresh()->isSuperseded());
    }

    public function test_pointing_at_the_wrong_document_can_be_undone(): void
    {
        $old = $this->document();
        $new = $this->document();

        $service = app(DocumentService::class);
        $service->supersede($old, $new);
        $service->unsupersede($old->fresh());

        $old = $old->fresh();

        $this->assertFalse($old->isSuperseded());
        $this->assertNull($old->superseded_by_id);
        $this->assertTrue($old->isInForce());
    }

    public function test_marking_it_needs_the_right_to_change_the_document(): void
    {
        $old = $this->document();
        $new = $this->document();

        $this->actingAs($this->user('document.view'))
            ->post(route('document-management.documents.supersede', $old->uuid), [
                'replacement' => $new->uuid,
            ])
            ->assertForbidden();
    }

    public function test_it_cannot_point_at_something_the_person_cannot_open(): void
    {
        /*
         * Pointing at a document they cannot see would confirm it exists, and would send every
         * reader of this one to a refusal.
         */
        $editor = $this->editor();
        $old = $this->document();
        $hidden = $this->document(['is_confidential' => true, 'created_by' => 0]);

        $this->actingAs($editor)
            ->post(route('document-management.documents.supersede', $old->uuid), [
                'replacement' => $hidden->uuid,
            ])
            ->assertSessionHas('error');

        $this->assertFalse($old->fresh()->isSuperseded());
    }

    // ── §17 The watermark on a preview ───────────────────────────────────────

    public function test_the_viewer_stamp_names_the_reader_and_the_minute(): void
    {
        /*
         * A different question from the share watermark. That one makes a forwarded file traceable
         * to whoever it was sent to; this one makes a photograph of a screen traceable to whoever
         * was sitting in front of it, so it has to carry a name and a time rather than only a date.
         */
        $text = app(WatermarkService::class)->textForViewer('Thandi Nkosi', 'Acme');

        $this->assertStringContainsString('Thandi Nkosi', $text);
        $this->assertStringContainsString('Acme', $text);
        $this->assertMatchesRegularExpression('/\d{2}:\d{2}/', $text, 'The time, not only the date.');
    }

    public function test_an_unidentified_reader_still_produces_a_stamp(): void
    {
        // A blank where the name should be reads as a rendering fault, and a stamp that says
        // nothing is worse than one saying the reader could not be identified.
        $text = app(WatermarkService::class)->textForViewer(null, 'Acme');

        $this->assertStringContainsString(__('document_management.watermark_unknown_viewer'), $text);
    }

    public function test_a_confidential_preview_is_stamped_without_anybody_switching_it_on(): void
    {
        /*
         * The confidential ones are exactly the documents worth stamping, and a company that never
         * opens the settings page should still get that.
         */
        $editor = $this->editor();
        $this->settings(['watermark_previews' => false, 'watermark_confidential_previews' => true]);

        $this->actingAs($editor);
        $document = app(DocumentService::class)->store($this->realPdf(), [
            'is_confidential' => true,
            'created_by' => $editor->id,
        ])['document'];

        $response = $this->get(route('document-management.documents.preview', $document->uuid));

        $response->assertOk();
        $this->assertNotSame(
            strlen((string) app(DocumentService::class)->contents($document->fresh()->currentVersion)),
            (int) $response->headers->get('Content-Length'),
            'A stamped preview is not the original bytes.',
        );
    }

    public function test_an_ordinary_preview_is_left_alone_by_default(): void
    {
        /*
         * Stamping is real work on every view, and most documents in most companies do not need it.
         * Switching it on for everything would make the repository feel slow for the sake of
         * documents nobody would photograph.
         */
        $editor = $this->editor();
        $this->settings(['watermark_previews' => false, 'watermark_confidential_previews' => true]);

        $this->actingAs($editor);
        $document = app(DocumentService::class)->store($this->realPdf(), [])['document'];

        $response = $this->get(route('document-management.documents.preview', $document->uuid));

        $original = (string) app(DocumentService::class)->contents($document->fresh()->currentVersion);

        $response->assertOk();
        $this->assertSame(strlen($original), (int) $response->headers->get('Content-Length'));
    }

    public function test_the_download_is_never_stamped(): void
    {
        /*
         * Somebody who may download has the real thing. Corrupting their legitimate copy with a
         * stamp would be a different feature and a worse one — the file they got would no longer be
         * the file that was signed.
         */
        $editor = $this->editor();
        $this->settings(['watermark_previews' => true]);

        $this->actingAs($editor);
        $document = app(DocumentService::class)->store($this->realPdf(), [])['document'];

        $response = $this->get(route('document-management.documents.download', $document->uuid));

        $original = (string) app(DocumentService::class)->contents($document->fresh()->currentVersion);

        $response->assertOk();
        $this->assertSame(strlen($original), (int) $response->headers->get('Content-Length'));
    }

    public function test_a_document_that_cannot_be_stamped_is_still_readable(): void
    {
        /*
         * Refusing instead would mean a document becomes unopenable because of the shape of its
         * file, and the person refused would have no idea why. The failure is logged rather than
         * shown.
         */
        $editor = $this->editor();
        $this->settings(['watermark_previews' => true]);

        $this->actingAs($editor);

        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'Plain text, which the stamper does not handle.');

        $document = app(DocumentService::class)->store(
            new UploadedFile($path, 'notes.txt', 'text/plain', null, true),
            [],
        )['document'];

        $this->get(route('document-management.documents.preview', $document->uuid))->assertOk();
    }
}
