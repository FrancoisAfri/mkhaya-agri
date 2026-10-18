<?php

namespace App\Modules\Operations\Sheq\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Every AI output, held as a proposal until a person decides on it (SHEQ-AI-1).
 *
 * This is the join between "the model produced something" and "the organisation did something", and
 * it is deliberately the only join. An agent cannot write to an incident, an action, a risk rating
 * or a form; it can write here, and a human moves it across.
 *
 * The reason this matters more in SHEQ than in most products: the artefacts are legal instruments.
 * A root cause analysis is disclosed to an inspector after a fatality. A risk assessment is what a
 * company points to when asked why it believed a control was adequate. If those can be authored by
 * a model with nobody accountable for the words, the document is worth less than nothing — it is
 * evidence that the employer's safety system is generating paperwork rather than doing the thinking
 * the Act requires. So the record keeps three things: that the AI drafted it, that a named person
 * reviewed it, and what they changed.
 *
 * EDITED is a first-class outcome alongside ACCEPTED and REJECTED, and the edit is stored beside
 * the original rather than over it. The most valuable signal about whether these agents are any
 * good is what practitioners keep changing — and overwriting the draft would destroy exactly that
 * signal, which §7.4's evaluation set depends on.
 */
class AiSuggestionService
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_EDITED = 'EDITED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    public function __construct(private readonly AiGuardrails $guardrails) {}

    /**
     * Record what an agent produced, as a draft.
     *
     * `status` and `actor_type` are not parameters. A caller cannot create an accepted suggestion,
     * and cannot create one attributed to a human — the two shortcuts that would hollow the control
     * out while leaving every screen looking correct.
     *
     * @param  array{agent:string, title:string, content:array, subject_type?:?string,
     *               subject_id?:?int, site_id?:?int, assertions?:list<array>, confidence?:?float,
     *               call_log_id?:?int}  $data
     * @return array{id:int, uuid:string, status:string, has_unverified_assertions:bool}
     */
    public function draft(int $companyId, array $data): array
    {
        if (trim($data['agent'] ?? '') === '') {
            throw new InvalidArgumentException('A suggestion must name the agent that produced it.');
        }

        $assertions = $data['assertions'] ?? [];
        $unverified = (bool) array_filter($assertions, fn ($a) => empty($a['verified']));

        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_ai_suggestions')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'agent' => $data['agent'],
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'status' => self::STATUS_DRAFT,
            'actor_type' => 'AI_AGENT',
            'title' => $data['title'],
            'content' => json_encode($data['content']),
            'assertions' => json_encode($assertions),
            'has_unverified_assertions' => $unverified,
            'confidence' => $data['confidence'] ?? null,
            'call_log_id' => $data['call_log_id'] ?? null,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        return [
            'id' => (int) $id,
            'uuid' => $uuid,
            'status' => self::STATUS_DRAFT,
            'has_unverified_assertions' => $unverified,
        ];
    }

    /**
     * A person accepts the draft as written.
     *
     * `$userId` is required and is not defaulted. An accepted suggestion with no acceptor is the
     * exact artefact this control exists to make impossible — it would read, to anyone auditing it
     * later, as though a human had approved it when nobody had.
     */
    public function accept(int $companyId, string $uuid, int $userId, ?string $note = null): array
    {
        return $this->decide($companyId, $uuid, $userId, self::STATUS_ACCEPTED, $note);
    }

    /**
     * A person accepts it, but changed it first.
     *
     * The edit is stored separately so the pair — what the model said, what the practitioner made
     * of it — stays intact for evaluation.
     */
    public function acceptEdited(int $companyId, string $uuid, int $userId, array $edited, ?string $note = null): array
    {
        return $this->decide($companyId, $uuid, $userId, self::STATUS_EDITED, $note, $edited);
    }

    /**
     * A person rejects it.
     *
     * A note is required here and optional elsewhere. Rejections are the training signal that
     * matters most, and "rejected, no reason given" tells whoever tunes these agents nothing at all.
     */
    public function reject(int $companyId, string $uuid, int $userId, string $note): array
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException(
                'A rejection needs a reason. It is the clearest signal available about where the '
                .'agent is going wrong, and it is lost the moment it is not written down.'
            );
        }

        return $this->decide($companyId, $uuid, $userId, self::STATUS_REJECTED, $note);
    }

    /**
     * Apply a decision.
     *
     * The guardrail check here is the belt to the braces: even at this point, where a real user id
     * is present, an agent-driven path that reached this method with a synthesised id would be
     * caught by {@see AiGuardrails::assertPermitted()} on `accept_own_suggestion`.
     */
    private function decide(int $companyId, string $uuid, int $userId, string $status,
        ?string $note = null, ?array $edited = null): array
    {
        if ($userId <= 0) {
            $this->guardrails->assertPermitted('accept_own_suggestion');
        }

        return DB::transaction(function () use ($companyId, $uuid, $userId, $status, $note, $edited) {
            $row = DB::table('sheq_ai_suggestions')
                ->where('company_id', $companyId)->where('uuid', $uuid)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id', 'status', 'subject_type', 'subject_id', 'call_log_id']);

            if ($row === null) {
                throw new RuntimeException("No AI suggestion {$uuid} in this company.");
            }

            if ($row->status !== self::STATUS_DRAFT) {
                throw new RuntimeException(sprintf(
                    'Suggestion %s was already %s. A decision is made once — re-deciding would '
                    .'overwrite the record of who accepted what.', $uuid, strtolower($row->status),
                ));
            }

            DB::table('sheq_ai_suggestions')->where('id', $row->id)->update([
                'status' => $status,
                'decided_by' => $userId,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
                'edited_content' => $edited === null ? null : json_encode($edited),
                'updated_at' => Carbon::now(),
            ]);

            // Mirror the verdict onto the call log, so quality can be read off the telemetry
            // without joining every suggestion back — this is the table §7.4's metrics run over.
            if ($row->call_log_id !== null) {
                DB::table('sheq_ai_call_log')->where('id', $row->call_log_id)
                    ->update(['human_decision' => $status, 'updated_at' => Carbon::now()]);
            }

            return ['uuid' => $uuid, 'status' => $status, 'decided_by' => $userId];
        });
    }

    /**
     * Retire earlier drafts for the same subject when a fresh one is generated.
     *
     * Without this, re-running an agent leaves a practitioner with several drafts of the same thing
     * and no indication which is current — and the natural response to that is to stop trusting any
     * of them.
     */
    public function supersedePrior(int $companyId, string $agent, string $subjectType, int $subjectId, int $exceptId): int
    {
        return DB::table('sheq_ai_suggestions')
            ->where('company_id', $companyId)
            ->where('agent', $agent)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', self::STATUS_DRAFT)
            ->where('id', '!=', $exceptId)
            ->update(['status' => self::STATUS_SUPERSEDED, 'updated_at' => Carbon::now()]);
    }

    /** Drafts waiting on a person, newest first. */
    public function pending(int $companyId, ?string $agent = null, int $limit = 50): array
    {
        return DB::table('sheq_ai_suggestions')
            ->where('company_id', $companyId)
            ->where('status', self::STATUS_DRAFT)
            ->whereNull('deleted_at')
            ->when($agent !== null, fn ($q) => $q->where('agent', $agent))
            ->orderByDesc('created_at')->limit($limit)
            ->get(['uuid', 'agent', 'title', 'content', 'assertions', 'confidence',
                'has_unverified_assertions', 'subject_type', 'subject_id', 'created_at'])
            ->map(fn ($r) => [
                'uuid' => $r->uuid,
                'agent' => $r->agent,
                'title' => $r->title,
                'content' => json_decode((string) $r->content, true),
                'assertions' => json_decode((string) $r->assertions, true) ?: [],
                'confidence' => $r->confidence === null ? null : (float) $r->confidence,
                'has_unverified_assertions' => (bool) $r->has_unverified_assertions,
                'subject_type' => $r->subject_type,
                'subject_id' => $r->subject_id === null ? null : (int) $r->subject_id,
                'created_at' => $r->created_at,
            ])->values()->all();
    }
}
