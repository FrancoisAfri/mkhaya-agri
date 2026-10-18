<?php

namespace App\Modules\Operations\Sheq\Ai;

use RuntimeException;

/**
 * The actions the AI may never take on its own (SHEQ-AI-7).
 *
 * The requirement lists them plainly: the AI must not close records, approve permits, sign off
 * actions, alter risk ratings or submit statutory forms. Every one of those is a decision that
 * carries personal legal liability for a named person — a 16.2 appointee signs a permit and owns
 * the consequences of that signature. Software that can produce the signature without the person
 * has not automated the decision, it has erased the accountability the law attaches to it.
 *
 * So this is a hard stop and not a system-prompt instruction. A prompt saying "never approve a
 * permit" is a request to a text generator, and it is defeated by any incident description that
 * happens to read as an instruction. This class is asked before a write, and it throws.
 *
 * The guarded list is deliberately expressed as **capabilities**, not table names. New tables arrive
 * constantly; the set of decisions a human must own does not.
 */
class AiGuardrails
{
    /**
     * Decisions reserved to people, with the reason each one is reserved.
     *
     * The reasons are here rather than in a design document because the exception message quotes
     * them: a developer who hits this needs to understand why the wall is there in the same moment
     * they hit it, or they will look for a way around it.
     */
    public const FORBIDDEN = [
        'close_incident' => 'Closing an incident asserts the investigation is complete and the '
            .'causes addressed. A person carries that assertion, not a model.',
        'close_action' => 'Verifying an action closed means somebody checked the control is '
            .'actually in place. Only the person who checked can say so.',
        'approve_permit' => 'A permit to work authorises people into a hazardous task under a '
            .'named signatory who is legally answerable for their safety.',
        'issue_permit' => 'Issuing carries the same personal liability as approving it.',
        'sign_off' => 'A signature is an individual accepting responsibility. It cannot be '
            .'generated on their behalf.',
        'alter_risk_rating' => 'A risk rating drives what controls are legally required. Moving '
            .'it moves the obligation, so a competent person must own the change.',
        'submit_statutory_form' => 'A submission to the Department of Employment and Labour is a '
            .'legal declaration by the employer, criminal if knowingly false.',
        'classify_incident' => 'Statutory classification determines reporting duties and their '
            .'deadlines. The AI may propose one; a person decides it.',
        'close_investigation' => 'Closing an investigation asserts the root causes are understood.',
        'block_contractor' => 'Removing a contractor from site has commercial and contractual '
            .'consequences that need a human decision-maker.',
        'delete_evidence' => 'Evidence is append-only by design. Nothing may destroy it, least of '
            .'all something acting without a person.',
        'accept_own_suggestion' => 'The whole control is that a human reviews AI output. An agent '
            .'accepting its own draft would close the loop it exists to keep open.',
    ];

    /**
     * Assert that an AI-initiated code path is not about to do something reserved.
     *
     * @throws RuntimeException always, when the capability is guarded — this is the point
     */
    public function assertPermitted(string $capability): void
    {
        if (! isset(self::FORBIDDEN[$capability])) {
            return;
        }

        throw new RuntimeException(sprintf(
            'SHEQ-AI-7: an AI agent may not %s. %s The agent may raise this as a DRAFT suggestion '
            .'for a person to accept, which is what AiSuggestionService is for.',
            str_replace('_', ' ', $capability),
            self::FORBIDDEN[$capability],
        ));
    }

    public function isForbidden(string $capability): bool
    {
        return isset(self::FORBIDDEN[$capability]);
    }

    /** @return array<string,string> capability => why a person must own it */
    public function forbidden(): array
    {
        return self::FORBIDDEN;
    }

    /**
     * Whether an actor is the AI.
     *
     * Guarded services call this to decide whether to apply the stop. Anything that is not
     * explicitly a human user is treated as the AI: an unattributed write during an AI request is
     * far likelier to be an agent than a person, and defaulting the other way would let exactly
     * the calls this class exists to catch through unchecked.
     */
    public function isAiActor(?string $actorType): bool
    {
        return $actorType !== null && strtoupper($actorType) !== 'USER';
    }
}
