<?php

namespace App\Modules\Operations\Sheq\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\FileService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Evidence from a field device, a part at a time (SHEQ-OFF-7).
 *
 * `FileService` has had the whole resumable-upload machinery since the module was written —
 * `beginUpload()` announces a file, `putChunk()` accepts parts in any order and assembles them once
 * the last one lands — and the API had no file endpoint at all. So the only way to attach a photo
 * was the browser form, which sends the whole file in one request.
 *
 * That is the one thing a phone at a mine shaft cannot reliably do. A four-megabyte photo on a weak
 * signal fails at ninety per cent and starts again from nothing; the person gives up, and the
 * incident record carries a description of an injury with no picture of the guard that was missing.
 * Which is to say: the module's offline story stopped exactly where it mattered most.
 *
 * Three properties the design rests on, each the reason the service was built this way:
 *
 * - **The row exists before the bytes do.** `beginUpload` writes a PENDING file immediately, so
 *   the parent record reads "media pending" from the first moment rather than looking complete. An
 *   incident that looks finished and is missing its photographs is worse than one that says so.
 * - **A repeat is not a duplicate.** The device's own `client_id` is the idempotency key, so a
 *   retried announcement resumes the same upload and is told which parts already arrived. Without
 *   that, every dropped connection would leave another half file behind.
 * - **Partial never reads as stored.** A file with a gap in its parts stays PENDING. Assembling
 *   what arrived would produce a corrupt image presented as evidence.
 *
 * The tenant is never taken from the request: it comes from the token's user, and `sheq.tenant`
 * refuses anything that did not resolve.
 */
class FileApiController extends Controller
{
    /**
     * A part, before base64, of at most this many bytes.
     *
     * 1 MiB. Large enough that a photograph is a handful of parts rather than hundreds, small
     * enough to survive a bad link and to keep one request well inside any proxy's body limit
     * after base64 inflates it by a third.
     */
    private const MAX_CHUNK_BYTES = 1_048_576;

    public function __construct(
        private readonly FileService $files,
        private readonly CurrentCompany $current,
    ) {}

    /**
     * Announce an upload, or resume one.
     *
     * `POST /api/v1/sheq/files {"subject_type":"INCIDENT","subject_id":41,"chunks_expected":4,...}`
     *
     * The response carries the parts already received, so a device resuming after an outage sends
     * only what is missing rather than starting again.
     */
    public function begin(Request $request): JsonResponse
    {
        $companyId = (int) $this->current->id();

        $validated = $request->validate([
            'subject_type' => ['required', Rule::in(FileService::subjects())],
            'subject_id' => ['required', 'integer', 'min:1'],
            'chunks_expected' => ['required', 'integer', 'min:1', 'max:2000'],
            'original_name' => ['nullable', 'string', 'max:255'],
            'mime' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:40'],
            'caption' => ['nullable', 'string', 'max:255'],
            // The device's own id for this upload, and the idempotency key: announcing the same
            // one twice resumes rather than starting a second file.
            'client_id' => ['nullable', 'uuid'],
            'device_id' => ['nullable', 'string', 'max:120'],
            // Read on the device before any compression, because that is where it still exists.
            'captured_at' => ['nullable', 'date'],
            'captured_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'captured_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $result = $this->files->beginUpload(
            $companyId,
            $validated,
            (int) $validated['chunks_expected'],
            $request->user()?->id,
        );

        // 200 on a resume, 201 on a new one: a device that crashed mid-upload needs to be able to
        // tell "I am continuing" from "I have started again".
        return response()->json($result, $result['resumed'] ? 200 : 201);
    }

    /**
     * One part.
     *
     * `PUT /api/v1/sheq/files/{uuid}/chunks/{sequence} {"data":"<base64>"}`
     *
     * Out of order is fine and a repeat is fine — that is the whole point of sending parts. The
     * response says how many have arrived, and reports STORED on the part that completes the file.
     */
    public function chunk(Request $request, string $uuid, int $sequence): JsonResponse
    {
        $companyId = (int) $this->current->id();

        $validated = $request->validate([
            'data' => ['required', 'string'],
        ]);

        $binary = base64_decode($validated['data'], true);

        if ($binary === false) {
            // Said as a validation failure rather than stored: a chunk that is not base64 would
            // assemble into a corrupt file, and the device must be told to resend this part.
            return response()->json([
                'title' => 'That part is not valid base64.',
                'detail' => 'Re-encode and send this part again. Nothing was stored.',
            ], 422);
        }

        if (strlen($binary) > self::MAX_CHUNK_BYTES) {
            return response()->json([
                'title' => 'That part is too large.',
                'detail' => sprintf('Send parts of at most %d bytes before encoding.',
                    self::MAX_CHUNK_BYTES),
            ], 422);
        }

        return response()->json(
            $this->files->putChunk($companyId, $uuid, $sequence, $validated['data']),
        );
    }

    /**
     * Where an upload stands, and whether its parent is still waiting on media.
     *
     * `GET /api/v1/sheq/files/{uuid}`
     *
     * `hasPendingMedia()` answers the second question and was called by nothing, so a device had no
     * way to ask "is everything I attached to this incident actually here" — and nor did anything
     * else. An incident whose photographs never finished uploading looked identical to one that
     * never had any.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $companyId = (int) $this->current->id();

        $file = DB::table('sheq_files')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->first(['uuid', 'subject_type', 'subject_id', 'original_name', 'mime', 'size',
                'upload_status', 'chunks_expected', 'chunks_received', 'stored_at']);

        if ($file === null) {
            return response()->json([
                'title' => 'No such file.',
                'detail' => "This company has no file {$uuid}.",
            ], 404);
        }

        return response()->json((array) $file + [
            'received' => DB::table('sheq_file_chunks')
                ->where('file_id', DB::table('sheq_files')
                    ->where('company_id', $companyId)->where('uuid', $uuid)->value('id'))
                ->orderBy('sequence')->pluck('sequence')->all(),
            'subject_has_pending_media' => $this->files->hasPendingMedia(
                $companyId, $file->subject_type, (int) $file->subject_id,
            ),
        ]);
    }
}
