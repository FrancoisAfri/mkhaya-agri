<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklist;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklistItem;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklistTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What should be on file, and what is missing.
 *
 * The repository can say what it holds. It cannot say what it is missing, and missing is the thing
 * people are actually asked about — "is the supplier pack complete", "what are we still waiting on
 * for Thandi". That gap is currently filled by a spreadsheet next to the repository, which is a
 * second record of the same facts and disagrees with the first within a week.
 *
 * So the tick has to happen by itself. An item is satisfied when a matching document is filed into
 * the folder, and matching means the category, because that is the only thing about a document that
 * is both recorded and meaningful across cases. A checklist somebody has to tick by hand is the
 * spreadsheet again, with extra steps.
 *
 * Items are copied from the template when the checklist is made rather than read through it. What
 * was required of somebody in March is part of the record of March, and a rule changed in November
 * must not rewrite it.
 */
class ChecklistService
{
    /**
     * Starts a pack from a template.
     *
     * @param array{name?: ?string, subject?: ?string, folder_id?: ?int, due_at?: ?string} $options
     */
    public function start(DocumentChecklistTemplate $template, array $options = []): DocumentChecklist
    {
        return DB::transaction(function () use ($template, $options) {
            $checklist = DocumentChecklist::create([
                'uuid' => (string) Str::uuid(),
                'template_id' => $template->id,
                'name' => $options['name'] ?? $template->name,
                'subject' => $options['subject'] ?? null,
                'folder_id' => $options['folder_id'] ?? null,
                'due_at' => filled($options['due_at'] ?? null) ? Carbon::parse($options['due_at']) : null,
                'created_by' => Auth::id(),
            ]);

            foreach ($template->items as $item) {
                DocumentChecklistItem::create([
                    'uuid' => (string) Str::uuid(),
                    'checklist_id' => $checklist->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'category_id' => $item->category_id,
                    'is_required' => $item->is_required,
                    'sort_order' => $item->sort_order,
                ]);
            }

            /*
             * Reconciled immediately. A pack started against a folder that already holds half of
             * what it asks for should open showing that, not showing everything as missing and
             * waiting for somebody to re-file documents that are already there.
             */
            $this->reconcile($checklist->fresh());

            return $checklist->fresh();
        });
    }

    /**
     * Ticks whatever the folder already satisfies, and unticks whatever it no longer does.
     *
     * Both directions matter. A document deleted after it satisfied an item leaves the pack looking
     * complete when it is not, and a checklist that is wrong in that direction is worse than no
     * checklist — somebody relies on it.
     *
     * @return int how many items changed
     */
    public function reconcile(DocumentChecklist $checklist): int
    {
        if ($checklist->folder_id === null) {
            return 0;
        }

        $changed = 0;

        // One query for the folder rather than one per item: a pack of twenty items against a
        // folder is otherwise twenty queries every time the page is opened.
        $documents = Document::query()
            ->where('folder_id', $checklist->folder_id)
            ->whereNull('archived_at')
            ->get(['id', 'category_id']);

        $taken = [];

        foreach ($checklist->items as $item) {
            $match = $this->matchFor($item, $documents, $taken);

            if ($match !== null && ! $item->isSatisfied()) {
                $item->forceFill([
                    'document_id' => $match,
                    'satisfied_at' => Carbon::now(),
                ])->save();
                $changed++;

                continue;
            }

            /*
             * The document that satisfied this is gone. Untick it rather than leave the tick
             * pointing at nothing — the pack is not complete any more, and the whole value of this
             * is that it says so.
             */
            if ($match === null && $item->isSatisfied() && $item->document_id !== null) {
                $stillThere = $documents->contains(fn ($document) => (int) $document->id === (int) $item->document_id);

                if (! $stillThere) {
                    $item->forceFill(['document_id' => null, 'satisfied_at' => null])->save();
                    $changed++;
                }
            }
        }

        $this->refreshCompletion($checklist);

        return $changed;
    }

    /**
     * Which document in the folder answers this item.
     *
     * Matched on category, and each document answers at most one item: two items both asking for a
     * certificate would otherwise both be ticked by the same file, and the pack would report itself
     * complete while one of the two was never provided.
     *
     * @param \Illuminate\Support\Collection<int, Document> $documents
     * @param array<int, bool> $taken documents already spoken for, by id
     */
    private function matchFor(DocumentChecklistItem $item, $documents, array &$taken): ?int
    {
        // A document chosen by hand keeps the item, whatever the categories say. Somebody deciding
        // "this is the one" outranks a rule about kinds of document.
        if ($item->document_id !== null && $documents->contains(fn ($d) => (int) $d->id === (int) $item->document_id)) {
            $taken[(int) $item->document_id] = true;

            return (int) $item->document_id;
        }

        if ($item->category_id === null) {
            return null;
        }

        foreach ($documents as $document) {
            if ((int) $document->category_id !== (int) $item->category_id) {
                continue;
            }

            if ($taken[(int) $document->id] ?? false) {
                continue;
            }

            $taken[(int) $document->id] = true;

            return (int) $document->id;
        }

        return null;
    }

    /** Points an item at a particular document, whatever its category. */
    public function satisfyWith(DocumentChecklistItem $item, Document $document): void
    {
        $item->forceFill([
            'document_id' => $document->id,
            'satisfied_at' => Carbon::now(),
        ])->save();

        $this->refreshCompletion($item->checklist);
    }

    /** Takes the tick off, for when the wrong document was attached. */
    public function clear(DocumentChecklistItem $item): void
    {
        $item->forceFill(['document_id' => null, 'satisfied_at' => null])->save();

        $this->refreshCompletion($item->checklist);
    }

    /**
     * @return array{total: int, required: int, satisfied: int, outstanding: int, percent: int}
     */
    public function progress(DocumentChecklist $checklist): array
    {
        $items = $checklist->items;

        $required = $items->where('is_required', true);
        $satisfied = $required->whereNotNull('satisfied_at');

        return [
            'total' => $items->count(),
            'required' => $required->count(),
            'satisfied' => $satisfied->count(),
            'outstanding' => $required->count() - $satisfied->count(),
            // Measured against what is required rather than against everything: a pack whose
            // optional items are untouched is finished, and a bar stuck at 80% says otherwise.
            'percent' => $required->count() === 0 ? 100 : (int) round($satisfied->count() / $required->count() * 100),
        ];
    }

    /** Everything still outstanding, for turning into requests in one act. */
    public function outstanding(DocumentChecklist $checklist)
    {
        return $checklist->items()->whereNull('satisfied_at')->where('is_required', true)->get();
    }

    private function refreshCompletion(?DocumentChecklist $checklist): void
    {
        if (! $checklist) {
            return;
        }

        $complete = $checklist->fresh()->isComplete();

        /*
         * The date is set once and kept. "When did this pack become complete" is a real question —
         * it is often the date somebody started, or the date a supplier was approved — and
         * restamping it every time the page is opened would lose it.
         */
        if ($complete && $checklist->completed_at === null) {
            $checklist->forceFill(['completed_at' => Carbon::now()])->save();
        }

        if (! $complete && $checklist->completed_at !== null) {
            $checklist->forceFill(['completed_at' => null])->save();
        }
    }
}
