<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentLink;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finding a document.
 *
 * Two things happen here. Structured filters — category, department, expiry, who it is about — are
 * applied as conditions, and free text goes through Postgres full-text search against the index
 * that already exists on title, description and notes.
 *
 * Full text rather than LIKE because they answer different questions. `%insur%` matches "insurance"
 * and also matches nothing when somebody types "insurances", while a tsquery stems both to the same
 * root. The index is a GIN over the same expression, so the search uses it rather than reading
 * every row.
 *
 * Everything is applied on top of Document::visibleTo, so a filter can never widen what somebody is
 * allowed to see — the worst a bad filter can do is show them less.
 */
class DocumentSearch
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(?User $user, array $filters = []): Builder
    {
        $query = Document::query()->visibleTo($user)->with(['category', 'folder', 'currentVersion']);

        $this->applyText($query, (string) ($filters['q'] ?? ''));
        $this->applyStructured($query, $filters);
        $this->applyDates($query, $filters);
        $this->applyLinks($query, $filters);
        $this->applyOrder($query, (string) ($filters['sort'] ?? ''));

        return $query;
    }

    /**
     * Free text, through the full-text index.
     *
     * websearch_to_tsquery is the parser that understands what people actually type — quoted
     * phrases, OR, and a leading minus to exclude — and, unlike plainto_tsquery, does not throw on
     * a stray quote. Somebody searching for O'Brien's contract should get results, not an error.
     */
    private function applyText(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $expression = "to_tsvector('simple', coalesce(title,'') || ' ' || coalesce(description,'') || ' ' || coalesce(notes,''))";

        $query->where(function ($q) use ($expression, $term) {
            $q->whereRaw("{$expression} @@ websearch_to_tsquery('simple', ?)", [$term]);

            /*
             * A document number is not language and does not stem, so an exact-prefix match on it
             * sits alongside the text search. Somebody with "DOC-2026-00042" written on a printout
             * is searching for that string, not for words.
             */
            $q->orWhere('document_number', 'ilike', $term . '%');

            /*
             * And the words inside the file.
             *
             * This is what makes the repository searchable rather than merely listable: an
             * indemnity clause is findable by the phrase in it, not only by whether somebody
             * thought to put "indemnity" in the title.
             *
             * Restricted to the current version. An old version's text answering a search would
             * surface a document for a phrase it no longer contains, which is worse than not
             * finding it — the person opens it and cannot see why it matched.
             *
             * Same 'simple' configuration as the expression above: two configurations stem the same
             * word differently, so a phrase found in a title would go missing in the body.
             */
            $q->orWhereExists(function ($sub) use ($term) {
                $sub->selectRaw('1')
                    ->from('document_extractions')
                    ->whereColumn('document_extractions.version_id', 'documents.current_version_id')
                    ->whereRaw("to_tsvector('simple', coalesce(document_extractions.text, '')) @@ websearch_to_tsquery('simple', ?)", [$term]);
            });
        });
    }

    /** @param array<string, mixed> $filters */
    private function applyStructured(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, function ($q, $status) {
                if ($status !== 'all') {
                    $q->where('status', $status);
                }
            })
            ->when($filters['category'] ?? null, fn ($q, $uuid) => $q->whereIn(
                'category_id', DocumentCategory::where('uuid', $uuid)->select('id'),
            ))
            ->when($filters['document_type'] ?? null, fn ($q, $type) => $q->where('document_type', 'ilike', '%' . $type . '%'))
            ->when($filters['department_unit_id'] ?? null, fn ($q, $id) => $q->where('department_unit_id', $id))
            ->when($filters['owner_id'] ?? null, fn ($q, $id) => $q->where('owner_id', $id))
            ->when($filters['uploaded_by'] ?? null, fn ($q, $id) => $q->where('created_by', $id))
            ->when($filters['confidentiality'] ?? null, fn ($q, $level) => $q->where('confidentiality', $level))
            // Queues: given as a list, because "awaiting approval" means submitted or part-way
            // through, not one exact status.
            ->when($filters['approval_status'] ?? null, fn ($q, $states) => $q->whereIn('approval_status', (array) $states))
            ->when($filters['signature_status'] ?? null, fn ($q, $states) => $q->whereIn('signature_status', (array) $states));

        // A folder means the folder and everything under it. Searching "Human Resources" and being
        // told there is nothing in it, because the documents are all in its subfolders, is the kind
        // of answer that makes people stop using search.
        if (! blank($filters['folder'] ?? null)) {
            $folder = DocumentFolder::where('uuid', $filters['folder'])->first();

            if ($folder) {
                $ids = $folder->subtreeIds();

                /*
                 * Documents filed here, plus the ones shortcut into here. A shortcut that did not
                 * show up when its folder was opened would be a bookmark nobody can see, which is
                 * the same as not having made it.
                 */
                $query->where(function ($inner) use ($ids) {
                    $inner->whereIn('folder_id', $ids)
                        ->orWhereIn('id', DB::table('document_shortcuts')->select('document_id')->whereIn('folder_id', $ids));
                });
            }
        }

        // Tags are stored as JSON; every named tag must be present rather than any of them, because
        // adding a tag to a search should narrow it.
        foreach ((array) ($filters['tags'] ?? []) as $tag) {
            if (filled($tag)) {
                $query->whereJsonContains('tags', $tag);
            }
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyDates(Builder $query, array $filters): void
    {
        foreach (['document_date', 'effective_date', 'expiry_date', 'created_at'] as $field) {
            $query
                ->when($filters[$field . '_from'] ?? null, fn ($q, $date) => $q->whereDate($field, '>=', $date))
                ->when($filters[$field . '_to'] ?? null, fn ($q, $date) => $q->whereDate($field, '<=', $date));
        }

        // The two people actually ask for, as their own filters rather than a date range they have
        // to work out: "expiring within 30 days" and "already expired".
        $query
            /*
             * Confidential covers both ways a document can be one: its own flag, and the folder it
             * sits in. Reading only the flag would leave a confidential folder's contents out of
             * the very listing meant to show them.
             */
            ->when($filters['is_confidential'] ?? null, function ($q) {
                $confidentialFolders = \App\Modules\Operations\DocumentManagement\Models\DocumentFolder::confidentialIds();

                $q->where(function ($inner) use ($confidentialFolders) {
                    $inner->where('is_confidential', true)
                        ->orWhereIn('confidentiality', [
                            Document::CONFIDENTIALITY_CONFIDENTIAL,
                            Document::CONFIDENTIALITY_RESTRICTED,
                        ]);

                    if ($confidentialFolders !== []) {
                        $inner->orWhereIn('folder_id', $confidentialFolders);
                    }
                });
            })
            ->when($filters['legally_sensitive'] ?? null, fn ($q) => $q->where('is_legally_sensitive', true))
            /*
             * Documents whose contents nobody can search.
             *
             * A scan with no text layer looks identical, in a listing, to a document that has been
             * read and simply says nothing — and the difference is the whole point: one is a file
             * somebody can do something about, the other is not. Filtered on the current version,
             * so replacing a scan with a readable copy takes it off this list.
             *
             * A document with no extraction row at all is included: it has never been read, which
             * on an installation that has not run the backfill is most of them, and silently
             * omitting those would make the list look reassuringly short.
             */
            /*
             * The personal slices the menu offers. Each is a different answer to "where is that
             * document" — I put it there, somebody sent it to me, I had it open on Tuesday, I
             * starred it — and none of them is a search anybody would think to construct.
             */
            ->when($filters['mine'] ?? null, fn ($q, $userId) => $q->where('created_by', $userId))
            ->when($filters['favourites'] ?? null, fn ($q, $userId) => $q->whereIn(
                'id',
                \App\Modules\Operations\DocumentManagement\Models\DocumentFavourite::query()
                    ->where('user_id', $userId)
                    ->select('document_id'),
            ))
            ->when($filters['shared_with_me'] ?? null, function ($q, $user) {
                // Documents somebody deliberately gave this person, by name, role, department or
                // company-wide — not everything they happen to be able to see.
                $granted = app(\App\Modules\Operations\DocumentManagement\Services\InternalShareService::class)
                    ->grantedDocumentIds($user);

                $granted ? $q->whereIn('id', $granted) : $q->whereRaw('1 = 0');
            })
            ->when($filters['recent'] ?? null, function ($q, $userId) {
                /*
                 * What this person opened, most recently first. Taken from the audit trail rather
                 * than a second table: every view is already recorded there with a timestamp, and a
                 * separate store of the same fact would drift from it within a week.
                 */
                $q->whereIn(
                    'id',
                    \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::query()
                        ->where('user_id', $userId)
                        ->where('action', \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::VIEWED)
                        ->whereNotNull('document_id')
                        ->select('document_id'),
                );
            })
            ->when($filters['unreadable'] ?? null, function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('document_extractions')
                            ->whereColumn('document_extractions.version_id', 'documents.current_version_id');
                    })->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('document_extractions')
                            ->whereColumn('document_extractions.version_id', 'documents.current_version_id')
                            ->whereIn('document_extractions.status', [
                                \App\Modules\Operations\DocumentManagement\Services\TextExtractor::NO_TEXT_LAYER,
                                \App\Modules\Operations\DocumentManagement\Services\TextExtractor::OCR_UNAVAILABLE,
                                \App\Modules\Operations\DocumentManagement\Services\TextExtractor::FAILED,
                            ]);
                    });
                })
                // Only where somebody expected to search inside it. A video on this list is noise
                // that teaches people to ignore the list.
                ->whereNotNull('current_version_id');
            })
            ->when($filters['expiring_within'] ?? null, fn ($q, $days) => $q->expiringWithin((int) $days))
            ->when($filters['expired'] ?? null, fn ($q) => $q->expired());
    }

    /**
     * Documents about a particular record, or about anything of a kind.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyLinks(Builder $query, array $filters): void
    {
        $relation = $filters['relation'] ?? null;
        $recordIds = array_filter((array) ($filters['relation_ids'] ?? []));

        if (blank($relation) && $recordIds === []) {
            return;
        }

        $links = DocumentLink::query()->select('document_id');

        if (filled($relation)) {
            $links->where('relation', $relation);

            // Constrained to the declared model as well as the relation name, so a stale link left
            // by a removed module cannot widen the result.
            $definition = DocumentRelations::definition($relation);
            if ($definition) {
                $links->where('linkable_type', $definition['model']);
            }
        }

        if ($recordIds !== []) {
            $links->whereIn('linkable_id', $recordIds);
        }

        $query->whereIn('id', $links);
    }

    private function applyOrder(Builder $query, string $sort): void
    {
        match ($sort) {
            // Soonest first, and never the ones with no date at all — a list of renewals is
            // useless if the documents that never expire sort to the top.
            'expiry' => $query->whereNotNull('expiry_date')->orderBy('expiry_date'),
            'title' => $query->orderBy('title'),
            'oldest' => $query->oldest('created_at'),
            default => $query->latest('updated_at'),
        };
    }

    /**
     * Turns a typed phrase into filters.
     *
     * The requirement asks for "find all supplier insurance certificates expiring within 30 days"
     * to work. This reads the parts of that sentence a person can rely on — a time window, a
     * category name, a relation — and hands the rest to full-text search. Deterministic on purpose:
     * a parser that quietly guesses wrong is worse than one whose limits are obvious, and a search
     * that costs an AI call per keystroke is a search nobody leaves switched on.
     *
     * @return array<string, mixed> filters, including the leftover text as `q`
     */
    public function parse(string $phrase): array
    {
        $filters = [];
        $remaining = ' ' . trim($phrase) . ' ';

        /*
         * Matches a pattern, records what it meant, and removes it from the phrase.
         *
         * Written as a method taking the phrase by reference rather than a closure capturing the
         * filters: an arrow function captures by value, so assigning into `$filters` inside one
         * would quietly write to a copy and the parser would find nothing while looking like it
         * worked.
         */
        $take = function (string $pattern) use (&$remaining): ?array {
            if (! preg_match($pattern, $remaining, $matches)) {
                return null;
            }

            $remaining = preg_replace($pattern, ' ', $remaining, 1);

            return $matches;
        };

        // "expiring within 30 days", "expiring in 2 weeks"
        if ($m = $take('/\bexpir\w*\s+(?:with)?in\s+(\d+)\s*(day|week|month)s?\b/i')) {
            $multiplier = ['day' => 1, 'week' => 7, 'month' => 30][strtolower($m[2])];
            $filters['expiring_within'] = (int) $m[1] * $multiplier;
        }

        if ($take('/\bexpiring\s+this\s+month\b/i')) {
            $filters['expiring_within'] = 30;
        }

        if ($take('/\b(?:already\s+)?expired\b/i')) {
            $filters['expired'] = true;
        }

        // "for supplier", "for employees", "on contract" — the kind of record it is about.
        foreach (DocumentRelations::available() as $key => $definition) {
            $word = preg_quote(strtolower($definition['label']), '/');

            if ($take("/\\b(?:for|about|on)\\s+{$word}s?\\b/i") || $take("/\\b{$word}s?\\b/i")) {
                $filters['relation'] = $key;
                break;
            }
        }

        // A category by name, which is how people describe what they are looking for.
        foreach (DocumentCategory::usable()->get() as $category) {
            $word = preg_quote(strtolower($category->name), '/');

            if ($take("/\\b{$word}s?\\b/i")) {
                $filters['category'] = $category->uuid;
                break;
            }
        }

        // Whatever is left is what they were actually typing — "insurance", a supplier's name.
        $leftover = trim(preg_replace('/\b(find|all|show|me|documents?|the)\b/i', ' ', $remaining));
        $filters['q'] = trim(preg_replace('/\s+/', ' ', $leftover));

        return $filters;
    }
}
