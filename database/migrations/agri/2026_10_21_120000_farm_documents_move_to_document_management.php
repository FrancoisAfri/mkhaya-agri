<?php

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Services\FarmDocuments;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Farm records move into Document Management.
 *
 * Every farm document becomes a Document Management document in the "Farm records" category, its
 * versions kept in order (each version's bytes on Document Management's database disk, with its
 * checksum), linked to its farm and to what it is about, with its expiry, reference and approval
 * carried over. The farm_documents rows are left as they were; a document already moved (its
 * custom field farm_document_id) is skipped, so running this again changes nothing.
 */
return new class extends Migration
{
    private const SUBJECT_MODELS = [
        'field' => [DocumentRelations::FARM_FIELD, \App\Modules\Agri\Models\FarmField::class],
        'crop_plan' => [DocumentRelations::CROP_PLAN, \App\Modules\Agri\Models\CropPlan::class],
        'livestock_group' => [DocumentRelations::LIVESTOCK_GROUP, \App\Modules\Agri\Models\LivestockGroup::class],
        'animal' => [DocumentRelations::ANIMAL, \App\Modules\Agri\Models\Animal::class],
        'machine' => [DocumentRelations::FARM_MACHINE, \App\Modules\Agri\Models\FarmMachine::class],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('farm_documents') || ! Schema::hasTable('documents')) {
            return;
        }
        $heads = DB::table('farm_documents')->whereNull('previous_id')->orderBy('id')->get();
        foreach ($heads as $head) {
            if (DB::table('documents')->whereRaw("custom_fields->>'farm_document_id' = ?", [(string) $head->id])->exists()) {
                continue;
            }
            // The chain, oldest first.
            $chain = [$head];
            while ($next = DB::table('farm_documents')->where('previous_id', end($chain)->id)->first()) {
                $chain[] = $next;
            }
            $latest = end($chain);
            DB::select("SELECT set_config('app.company_id', ?, false)", [(string) $head->company_id]);
            try {
                DB::transaction(fn () => $this->move((int) $head->company_id, $chain, $latest));
            } finally {
                DB::select("SELECT set_config('app.company_id', '', false)");
            }
        }
    }

    private function move(int $companyId, array $chain, object $latest): void
    {
        $category = FarmDocuments::category($companyId);
        $docUuid = (string) Str::uuid();
        $approved = $latest->approval === 'approved';
        $docId = DB::table('documents')->insertGetId([
            'uuid' => $docUuid, 'company_id' => $companyId, 'title' => $latest->title ?: ($latest->file_name ?: 'Farm record'),
            'description' => 'Farm record', 'status' => 'active', 'category_id' => $category->id, 'document_type' => $latest->document_type,
            'document_number' => $latest->reference, 'owner_id' => $latest->uploaded_by, 'document_date' => $latest->issued_on, 'expiry_date' => $latest->expires_on,
            'approval_status' => $approved ? 'approved' : 'draft', 'version_count' => count($chain), 'custom_fields' => json_encode(['farm_document_id' => $chain[0]->id]),
            'disposal_due_at' => $latest->retain_until, 'created_by' => $chain[0]->uploaded_by, 'updated_by' => $latest->uploaded_by,
            'created_at' => $chain[0]->created_at, 'updated_at' => $latest->updated_at,
        ]);
        $current = null;
        foreach ($chain as $n => $v) {
            $bytes = $v->data ? base64_decode($v->data, true) : null;
            if ($bytes === null || $bytes === false) {
                continue;
            }
            $ext = strtolower(pathinfo((string) $v->file_name, PATHINFO_EXTENSION)) ?: 'bin';
            $stored = Str::uuid().'.'.$ext;
            $path = "documents/{$docUuid}/{$stored}";
            Storage::disk('documents')->put($path, $bytes);
            $current = DB::table('document_versions')->insertGetId([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'document_id' => $docId, 'version' => $n + 1, 'major' => $n + 1, 'minor' => 0,
                'original_name' => $v->file_name ?: "farm-record-{$v->id}.{$ext}", 'stored_name' => $stored, 'disk' => 'documents', 'path' => $path,
                'extension' => $ext, 'mime' => $v->mime_type ?: 'application/octet-stream', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes),
                'change_note' => $n === 0 ? 'Moved from the farm records' : 'Earlier farm version', 'uploaded_by' => $v->uploaded_by,
                'created_at' => $v->created_at, 'updated_at' => $v->updated_at,
            ]);
        }
        DB::table('documents')->where('id', $docId)->update(['current_version_id' => $current]);

        $links = [[DocumentRelations::FARM, Farm::class, $latest->farm_id]];
        if ($latest->subject_type && isset(self::SUBJECT_MODELS[$latest->subject_type]) && $latest->subject_id) {
            $links[] = [self::SUBJECT_MODELS[$latest->subject_type][0], self::SUBJECT_MODELS[$latest->subject_type][1], $latest->subject_id];
        }
        foreach ($links as [$relation, $type, $id]) {
            DB::table('document_links')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'document_id' => $docId, 'relation' => $relation,
                'linkable_type' => $type, 'linkable_id' => $id, 'created_by' => $latest->uploaded_by, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The moved documents stay in Document Management; the farm rows were never changed.
    }
};
