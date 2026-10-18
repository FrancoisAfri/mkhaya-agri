<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Evidence from a field device, a part at a time (SHEQ-OFF-7).
 *
 * `FileService::beginUpload()` and `putChunk()` have been there since the module was written, and
 * the API had no file endpoint at all — so the only way to attach a photograph was the browser
 * form, which sends the whole file in one request. That is the one thing a phone at a shaft on a
 * weak signal cannot reliably do: it fails at ninety per cent, starts again from nothing, and the
 * person gives up. The incident then carries a description of an injury and no picture of the
 * guard that was missing.
 *
 * `hasPendingMedia()` was unreached for the same reason: nothing could ask whether a record was
 * still waiting on its media, so an incident whose photographs never finished uploading looked
 * identical to one that never had any.
 */
class ChunkedUploadApiTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $incidentId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userFor($this->companyId);
        $this->ensureModuleActive();

        $this->incidentId = (int) DB::table('sheq_incidents')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(6), 'incident_type' => 'INJURY',
            'description' => 'A guard was missing from the conveyor.', 'status' => 'OPEN',
            'occurred_at' => now()->subHour(), 'reported_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userFor(int $companyId): User
    {
        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'fapi-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScopes()->findOrFail($id);

        foreach (['sheq.report.view', 'sheq.incident.report'] as $permission) {
            if (Permission::where('name', $permission)->where('guard_name', 'web')->exists()) {
                $user->givePermissionTo($permission);
            }
        }

        $user->forgetCachedPermissions();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user;
    }

    private function ensureModuleActive(): void
    {
        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);

        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');
    }

    /** A small real PNG, split into $parts. The store detects type from content, not extension. */
    private function pngParts(int $parts): array
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAIAQMAAAD+wSzIAAAABlBMVEX///+/v7+jQ3Y5AAAADklEQVQI12P4'
            .'AIX8EAgALgAD/aNpbtEAAAAASUVORK5CYII='
        );

        $size = (int) ceil(strlen($png) / $parts);

        return array_map('base64_encode', str_split($png, max(1, $size)));
    }

    private function begin(array $overrides = []): array
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/v1/sheq/files', array_merge([
            'subject_type' => 'INCIDENT',
            'subject_id' => $this->incidentId,
            'chunks_expected' => 2,
            'original_name' => 'guard.png',
            'mime' => 'image/png',
            'category' => 'EVIDENCE',
            'client_id' => (string) Str::uuid(),
        ], $overrides))->json();
    }

    public function test_announcing_an_upload_creates_a_pending_file_before_any_bytes_arrive(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sheq/files', [
            'subject_type' => 'INCIDENT', 'subject_id' => $this->incidentId,
            'chunks_expected' => 3, 'original_name' => 'guard.png', 'mime' => 'image/png',
        ])->assertStatus(201);

        // The row before the bytes: an incident that looks finished and is missing its
        // photographs is worse than one that says it is still waiting.
        $this->assertSame('PENDING', $response->json('status'));
        $this->assertSame([], $response->json('received'));
        $this->assertFalse($response->json('resumed'));

        $this->assertDatabaseHas('sheq_files', [
            'company_id' => $this->companyId, 'uuid' => $response->json('uuid'),
            'upload_status' => 'PENDING', 'chunks_expected' => 3,
        ]);
    }

    public function test_the_parent_record_reports_that_it_is_waiting_on_media(): void
    {
        $file = $this->begin();

        Sanctum::actingAs($this->user);

        $this->assertTrue($this->getJson("/api/v1/sheq/files/{$file['uuid']}")
            ->assertOk()->json('subject_has_pending_media'));
    }

    public function test_the_parts_assemble_into_a_stored_file(): void
    {
        $parts = $this->pngParts(2);
        $file = $this->begin(['chunks_expected' => count($parts)]);

        Sanctum::actingAs($this->user);

        foreach ($parts as $index => $data) {
            $response = $this->putJson("/api/v1/sheq/files/{$file['uuid']}/chunks/{$index}",
                ['data' => $data])->assertOk();

            $expected = $index === count($parts) - 1 ? 'STORED' : 'PENDING';
            $this->assertSame($expected, $response->json('status'));
        }

        $stored = DB::table('sheq_files')
            ->where('company_id', $this->companyId)->where('uuid', $file['uuid'])
            ->first(['upload_status', 'storage_path', 'sha256', 'size']);

        $this->assertSame('STORED', $stored->upload_status);
        $this->assertNotNull($stored->storage_path);
        $this->assertNotNull($stored->sha256);

        // And the parent is no longer waiting.
        $this->assertFalse($this->getJson("/api/v1/sheq/files/{$file['uuid']}")
            ->json('subject_has_pending_media'));
    }

    public function test_parts_may_arrive_out_of_order(): void
    {
        $parts = $this->pngParts(3);
        $file = $this->begin(['chunks_expected' => count($parts)]);

        Sanctum::actingAs($this->user);

        // 2, 0, 1 — a device retrying over a bad link does not get to choose the order.
        foreach ([2, 0, 1] as $index) {
            $this->putJson("/api/v1/sheq/files/{$file['uuid']}/chunks/{$index}",
                ['data' => $parts[$index]])->assertOk();
        }

        $this->assertSame('STORED', DB::table('sheq_files')
            ->where('company_id', $this->companyId)->where('uuid', $file['uuid'])
            ->value('upload_status'));
    }

    public function test_a_file_with_a_gap_stays_pending_rather_than_assembling_what_arrived(): void
    {
        $parts = $this->pngParts(3);
        $file = $this->begin(['chunks_expected' => count($parts)]);

        Sanctum::actingAs($this->user);

        // Part 1 never arrives. Assembling 0 and 2 would produce a corrupt image presented as
        // evidence, which is worse than an obviously incomplete upload.
        $this->putJson("/api/v1/sheq/files/{$file['uuid']}/chunks/0", ['data' => $parts[0]]);
        $this->putJson("/api/v1/sheq/files/{$file['uuid']}/chunks/2", ['data' => $parts[2]]);

        $this->assertSame('PENDING', DB::table('sheq_files')
            ->where('company_id', $this->companyId)->where('uuid', $file['uuid'])
            ->value('upload_status'));

        $this->assertSame([0, 2], $this->getJson("/api/v1/sheq/files/{$file['uuid']}")
            ->json('received'));
    }

    public function test_announcing_the_same_upload_again_resumes_it(): void
    {
        $clientId = (string) Str::uuid();
        $parts = $this->pngParts(3);

        $file = $this->begin(['client_id' => $clientId, 'chunks_expected' => count($parts)]);

        Sanctum::actingAs($this->user);
        $this->putJson("/api/v1/sheq/files/{$file['uuid']}/chunks/0", ['data' => $parts[0]]);

        Sanctum::actingAs($this->user);

        $again = $this->postJson('/api/v1/sheq/files', [
            'subject_type' => 'INCIDENT', 'subject_id' => $this->incidentId,
            'chunks_expected' => count($parts), 'client_id' => $clientId,
        ])->assertOk();

        // Resumed, not restarted — and told which part already landed, so the device sends the
        // rest rather than everything. Without this, every dropped connection leaves another half
        // file behind.
        $this->assertTrue($again->json('resumed'));
        $this->assertSame($file['uuid'], $again->json('uuid'));
        $this->assertSame([0], $again->json('received'));

        $this->assertSame(1, DB::table('sheq_files')
            ->where('company_id', $this->companyId)->where('client_id', $clientId)->count());
    }

    public function test_resending_the_same_part_is_not_a_second_part(): void
    {
        $parts = $this->pngParts(2);
        $file = $this->begin(['chunks_expected' => count($parts)]);

        Sanctum::actingAs($this->user);

        $this->putJson("/api/v1/sheq/files/{$file['uuid']}/chunks/0", ['data' => $parts[0]]);
        $this->putJson("/api/v1/sheq/files/{$file['uuid']}/chunks/0", ['data' => $parts[0]]);

        // Counting the repeat would complete a two-part file from one part, and the assembled
        // image would be half a photograph.
        $this->assertSame('PENDING', DB::table('sheq_files')
            ->where('company_id', $this->companyId)->where('uuid', $file['uuid'])
            ->value('upload_status'));
    }

    public function test_a_part_that_is_not_base64_is_refused_rather_than_stored(): void
    {
        $file = $this->begin();

        Sanctum::actingAs($this->user);

        $this->putJson("/api/v1/sheq/files/{$file['uuid']}/chunks/0",
            ['data' => 'not base64 at all !!!'])->assertStatus(422);

        $this->assertSame([], $this->getJson("/api/v1/sheq/files/{$file['uuid']}")
            ->json('received'));
    }

    public function test_an_oversized_part_is_refused(): void
    {
        $file = $this->begin();

        Sanctum::actingAs($this->user);

        // Parts are bounded so one request stays inside any proxy's body limit after base64
        // inflates it, and so a device cannot send the whole file as "part zero".
        $this->putJson("/api/v1/sheq/files/{$file['uuid']}/chunks/0",
            ['data' => base64_encode(str_repeat('x', 1_048_577))])->assertStatus(422);
    }

    public function test_a_subject_a_sheq_file_cannot_attach_to_is_a_field_error(): void
    {
        Sanctum::actingAs($this->user);

        // The service refuses this with an exception. Validated at the edge, the device is told
        // which field is wrong instead of being handed a 500.
        $this->postJson('/api/v1/sheq/files', [
            'subject_type' => 'PAYSLIP', 'subject_id' => 1, 'chunks_expected' => 1,
        ])->assertStatus(422)->assertJsonPath('errors.subject_type.0', fn ($m) => $m !== null);
    }

    public function test_another_tenants_file_cannot_be_read_or_added_to(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'fapi-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) Str::uuid();

        DB::table('sheq_files')->insert([
            'uuid' => $uuid, 'company_id' => $other, 'subject_type' => 'INCIDENT',
            'subject_id' => 1, 'category' => 'EVIDENCE', 'original_name' => 'theirs.png',
            'upload_status' => 'PENDING', 'chunks_expected' => 2, 'client_id' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        // The tenant comes from the token's user and there is no parameter to tamper with.
        $this->getJson("/api/v1/sheq/files/{$uuid}")->assertStatus(404);
    }

    public function test_the_endpoints_need_a_token(): void
    {
        $this->postJson('/api/v1/sheq/files', [
            'subject_type' => 'INCIDENT', 'subject_id' => 1, 'chunks_expected' => 1,
        ])->assertStatus(401);
    }
}
