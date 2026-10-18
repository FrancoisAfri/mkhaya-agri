<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writing down what happened to a folder.
 *
 * Its own small service rather than a method on FolderService, because the permission controller
 * needs it too and the one thing worse than an unaudited change is an audit that depends on which
 * class happened to make it.
 */
class FolderAudit
{
    /** @param array<string, mixed> $detail */
    public function record(DocumentFolder $folder, string $action, array $detail = []): void
    {
        DocumentEvent::create([
            'company_id' => $folder->company_id,
            'folder_id' => $folder->id,
            'document_id' => null,
            'action' => $action,
            'user_id' => Auth::id(),
            'ip' => request()?->ip(),
            'user_agent' => Str::limit((string) request()?->userAgent(), 250, ''),
            'detail' => $detail ?: null,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Records a permission change as a difference rather than a state.
     *
     * "Sarah gave the Payroll role access to Employee Files" is the sentence somebody needs a year
     * later. A snapshot of the resulting permissions answers a different question — what they are
     * now — and leaves the reader to work out what changed by comparing two rows.
     *
     * @param array<int, array<string, mixed>> $before
     * @param array<int, array<string, mixed>> $after
     */
    public function recordPermissionChange(DocumentFolder $folder, array $before, array $after): void
    {
        $key = fn (array $row) => ($row['subject_type'] ?? '') . ':' . ($row['subject_id'] ?? '') . ':' . ($row['subject_name'] ?? '');

        $wasThere = collect($before)->keyBy($key);
        $isThere = collect($after)->keyBy($key);

        $granted = $isThere->keys()->diff($wasThere->keys())->values();
        $revoked = $wasThere->keys()->diff($isThere->keys())->values();

        // Somebody whose abilities changed without being added or removed — view-only to editor.
        $changed = $isThere->keys()->intersect($wasThere->keys())
            ->filter(fn ($k) => $this->abilities($isThere[$k]) !== $this->abilities($wasThere[$k]))
            ->values();

        if ($granted->isEmpty() && $revoked->isEmpty() && $changed->isEmpty()) {
            // Saving a permissions form without altering anything is not an event. A history full
            // of those is one nobody reads closely enough to spot the change that mattered.
            return;
        }

        $name = fn ($k) => ($isThere[$k]['subject_name'] ?? $wasThere[$k]['subject_name'] ?? null);

        $this->record($folder, DocumentEvent::FOLDER_PERMISSIONS_CHANGED, [
            'granted' => $granted->map($name)->filter()->values()->all(),
            'revoked' => $revoked->map($name)->filter()->values()->all(),
            'changed' => $changed->map($name)->filter()->values()->all(),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function abilities(array $row): array
    {
        return [
            'view' => (bool) ($row['can_view'] ?? false),
            'download' => (bool) ($row['can_download'] ?? false),
            'upload' => (bool) ($row['can_upload'] ?? false),
            'edit' => (bool) ($row['can_edit'] ?? false),
            'delete' => (bool) ($row['can_delete'] ?? false),
            'manage' => (bool) ($row['can_manage'] ?? false),
        ];
    }
}
