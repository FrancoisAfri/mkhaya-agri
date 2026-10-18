<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REQUIRED_DOCUMENT — the subject must have documents of the configured categories attached.
 *
 * parameters:
 *   category_codes    list<string>  which categories satisfy the rule (empty = any document)
 *   min_count         int           how many, default 1 — per category when categories are named
 *   must_be_current   bool          an expired document does not count, default true
 *   must_be_approved  bool          a document still awaiting approval does not count, default false
 *   link_role         string|null   only count documents linked in this role
 *
 * "Missing" and "expired" are reported separately. Telling someone a tax clearance certificate is
 * missing when it is attached but three weeks out of date sends them looking in the wrong place.
 */
class RequiredDocumentHandler implements RuleHandler
{
    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        $categories = array_values(array_filter((array) ($parameters['category_codes'] ?? [])));
        $minCount = max(1, (int) ($parameters['min_count'] ?? 1));
        $mustBeCurrent = (bool) ($parameters['must_be_current'] ?? true);
        $mustBeApproved = (bool) ($parameters['must_be_approved'] ?? false);
        $linkRole = $parameters['link_role'] ?? null;

        $rows = $this->attachedDocuments($subject, $linkRole);

        // Nothing named: the rule is simply "some evidence must be attached".
        if ($categories === []) {
            $usable = $rows->filter(fn ($d) => $this->isUsable($d, $mustBeCurrent, $mustBeApproved));

            if ($usable->count() >= $minCount) {
                return RuleOutcome::pass();
            }

            return RuleOutcome::fail(
                $minCount === 1
                    ? 'No supporting document is attached.'
                    : "At least {$minCount} supporting documents are required; {$usable->count()} attached.",
                ['attached' => $rows->count(), 'usable' => $usable->count(), 'required' => $minCount],
            );
        }

        $missing = [];
        $expired = [];
        $unapproved = [];

        foreach ($categories as $code) {
            $forCategory = $rows->where('category_code', $code);

            if ($forCategory->isEmpty()) {
                $missing[] = $code;

                continue;
            }

            $current = $forCategory->filter(fn ($d) => $this->isUsable($d, $mustBeCurrent, $mustBeApproved));

            if ($current->count() >= $minCount) {
                continue;
            }

            if ($mustBeCurrent && $forCategory->contains(fn ($d) => $this->isExpired($d))) {
                $expired[] = $code;
            } elseif ($mustBeApproved) {
                $unapproved[] = $code;
            } else {
                $missing[] = $code;
            }
        }

        if ($missing === [] && $expired === [] && $unapproved === []) {
            return RuleOutcome::pass();
        }

        $parts = [];
        if ($missing !== []) {
            $parts[] = 'missing: '.implode(', ', $missing);
        }
        if ($expired !== []) {
            $parts[] = 'expired: '.implode(', ', $expired);
        }
        if ($unapproved !== []) {
            $parts[] = 'awaiting approval: '.implode(', ', $unapproved);
        }

        return RuleOutcome::fail(
            'Required documents not satisfied — '.implode('; ', $parts).'.',
            ['missing' => $missing, 'expired' => $expired, 'unapproved' => $unapproved],
        );
    }

    /**
     * Documents linked directly to the subject, plus — for a subject that belongs to a 360° record
     * — those linked to the record itself. That is what makes §8's "never upload the same document
     * twice" true in practice: a tax clearance attached to the procurement satisfies a deviation
     * raised against it, without anyone re-attaching it.
     */
    private function attachedDocuments(RuleSubject $subject, ?string $linkRole)
    {
        $query = DB::table('procgov_document_links as l')
            ->join('procgov_documents as d', 'd.id', '=', 'l.document_id')
            ->leftJoin('procgov_document_categories as c', 'c.id', '=', 'd.category_id')
            ->where('l.company_id', $subject->companyId)
            ->whereNull('d.deleted_at')
            ->where(function ($q) use ($subject) {
                $q->where(function ($inner) use ($subject) {
                    $inner->where('l.entity_type', $subject->type)->where('l.entity_id', $subject->id);
                });

                if ($subject->recordId) {
                    $q->orWhere(function ($inner) use ($subject) {
                        $inner->where('l.entity_type', 'procgov_records')
                            ->where('l.entity_id', $subject->recordId);
                    });
                }
            });

        if ($linkRole) {
            $query->where('l.link_role', $linkRole);
        }

        return $query->get([
            'd.id', 'd.title', 'd.approval_status', 'd.expires_at', 'c.code as category_code',
        ]);
    }

    private function isUsable(object $document, bool $mustBeCurrent, bool $mustBeApproved): bool
    {
        if ($mustBeCurrent && $this->isExpired($document)) {
            return false;
        }

        if ($mustBeApproved && $document->approval_status !== 'APPROVED') {
            return false;
        }

        return ! in_array($document->approval_status, ['REJECTED', 'ARCHIVED'], true);
    }

    /** A document with no expiry date never expires — that is what leaving it blank means. */
    private function isExpired(object $document): bool
    {
        return $document->expires_at !== null
            && Carbon::parse($document->expires_at)->endOfDay()->isPast();
    }
}
