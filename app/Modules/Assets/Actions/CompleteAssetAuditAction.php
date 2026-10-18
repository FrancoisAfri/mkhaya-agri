<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetAudit;
use App\Modules\Assets\Models\AssetAuditLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finalises an audit: any line still `expected` (never scanned) becomes
 * `missing`, final counts are stamped and the audit is closed as `completed`.
 * Idempotent — a completed audit is left untouched.
 */
class CompleteAssetAuditAction
{
    public function execute(AssetAudit $audit): AssetAudit
    {
        if ($audit->status === AssetAudit::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'audit' => 'This audit has already been completed.',
            ]);
        }

        return DB::transaction(function () use ($audit) {
            AssetAuditLine::where('audit_id', $audit->id)
                ->where('status', AssetAuditLine::STATUS_EXPECTED)
                ->update(['status' => AssetAuditLine::STATUS_MISSING]);

            $found      = AssetAuditLine::where('audit_id', $audit->id)->where('status', AssetAuditLine::STATUS_FOUND)->count();
            $missing    = AssetAuditLine::where('audit_id', $audit->id)->where('status', AssetAuditLine::STATUS_MISSING)->count();
            $unexpected = AssetAuditLine::where('audit_id', $audit->id)->where('status', AssetAuditLine::STATUS_UNEXPECTED)->count();

            $audit->update([
                'status'           => AssetAudit::STATUS_COMPLETED,
                'completed_at'     => now(),
                'found_count'      => $found,
                'missing_count'    => $missing,
                'unexpected_count' => $unexpected,
            ]);

            return $audit->fresh();
        });
    }
}
