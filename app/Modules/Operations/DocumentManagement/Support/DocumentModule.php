<?php

namespace App\Modules\Operations\DocumentManagement\Support;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Whether the repository is available to the rest of the platform, and to this person.
 *
 * Every module that shows a documents panel has to ask this first. Document Management is an
 * add-on: a company can be running Payroll and Fleet without it, and an employee page that renders
 * a documents panel regardless would offer an upload button leading to a 403 — which reads as a
 * broken page rather than as a module nobody bought.
 *
 * Two questions, both of which have to be yes:
 *
 *   Is the module switched on and on this company's subscription — the same pair the route
 *   middleware checks, so a panel never appears where the routes behind it would refuse.
 *
 *   May this person see documents at all. Somebody without document.view gets no panel rather than
 *   an empty one, because an empty panel says "there is nothing here" and the truth is "you cannot
 *   be told".
 */
class DocumentModule
{
    public const CODE = 'document_management';

    /**
     * Whether the module is active and on the company's plan.
     *
     * Cached for the same five minutes the middleware uses, and keyed the same way, so the panel
     * and the route it links to cannot disagree for the length of a cache window.
     */
    public static function isActive(): bool
    {
        $installed = Cache::remember(
            'module_active_' . self::CODE,
            300,
            fn () => DB::table('security_modules')
                ->where('code_name', self::CODE)
                ->where('active', 1)
                ->exists(),
        );

        if (! $installed) {
            return false;
        }

        return app(CompanyEntitlements::class)->currentAllowsModule(self::CODE);
    }

    /** Whether this person should be shown documents on another module's page. */
    public static function isAvailableTo(?User $user): bool
    {
        return $user !== null
            && $user->can('document.view')
            && self::isActive();
    }

    /**
     * Everything a host page needs to render the panel, or nulls when it should not.
     *
     * Returned as one array so a module's controller adds a single line rather than repeating the
     * checks — and so that when the rules change, they change here rather than in nine modules
     * that each remembered them slightly differently.
     *
     * @return array{available: bool, relation: ?string, record: string|int|null, can_upload: bool, count: int, documents: array<int, array<string, mixed>>}
     */
    public static function panelFor(object $record, ?User $user): array
    {
        $unavailable = [
            'available' => false,
            'relation' => null,
            'record' => null,
            'can_upload' => false,
            'count' => 0,
            'documents' => [],
        ];

        if (! self::isAvailableTo($user)) {
            return $unavailable;
        }

        $relation = DocumentRelations::forModel($record);

        if ($relation === null) {
            // A model nobody taught the repository about. Better an absent panel than one that
            // cannot say what it is showing documents for.
            return $unavailable;
        }

        if (! method_exists($record, 'libraryDocuments')) {
            return $unavailable;
        }

        $documents = $record->libraryDocuments()
            // Through the same visibility scope as the library. A record's page must not become a
            // way of seeing a confidential document somebody was refused everywhere else.
            ->visibleTo($user)
            ->with('category')
            ->limit(50)
            ->get();

        return [
            'available' => true,
            'relation' => $relation,
            // The record's public identifier where it has one, so an internal id never reaches a
            // URL. RecordDocumentController resolves either.
            'record' => filled($record->uuid ?? null) ? $record->uuid : $record->getKey(),
            'can_upload' => $user->can('document.upload'),
            /*
             * The count is what §30 actually asks to be shown — "Documents (14)" on an employee, a
             * supplier, a contract. Taken from what this person can see, so the number on the tab
             * and the list behind it agree; a count of everything filed would tell somebody how
             * much they are not being shown.
             */
            'count' => $documents->count(),
            'documents' => $documents
                ->map(fn ($document) => [
                    'uuid' => $document->uuid,
                    'title' => $document->title,
                    'category' => $document->category?->name,
                    'expiry_date' => $document->expiry_date?->toFormattedDateString(),
                    'is_expired' => $document->isExpired(),
                    'uploaded_at' => $document->created_at?->toFormattedDateString(),
                ])
                ->all(),
        ];
    }
}
