<?php

namespace App\Modules\Operations\Sheq\Compliance;

/**
 * The reasons this system will refuse somebody, named once (COM-2).
 *
 * Every refusal in the module already carried a sentence a guard could read. That is the half
 * that matters at a boom, and it is not the half a computer can act on. A sentence cannot be
 * counted, cannot be charted, cannot be routed to the person who fixes that kind of problem, and
 * cannot be translated — so the isiZulu screen showed English reasons, and the "top refusal
 * reasons" chart grouped on the leading clause of a string and split "expired on 3 March" from
 * "expired on 9 April" until somebody noticed and trimmed it back to the first few words.
 *
 * So a decision now carries both: the code for the system, the sentence for the person.
 *
 * The codes are a closed list on purpose. A free-form code is a string with extra steps — the
 * first typo creates a reason nothing routes, nothing counts and nothing translates, and it looks
 * exactly like a working one. `ComplianceDecisionTest` walks this class and the engine together
 * and fails if either names a code the other does not.
 *
 * Stability matters more than tidiness here. These end up in access-event history, in exported
 * audit trails and in whatever a host's gate hardware logs, so a code is never renamed once
 * shipped — a better name is a new code and a translation of the old one.
 */
final class ReasonCode
{
    // ── The contractor ────────────────────────────────────────────────────────────────────────
    public const CONTRACTOR_BLOCKED = 'CONTRACTOR_BLOCKED';

    public const CONTRACTOR_SUSPENDED = 'CONTRACTOR_SUSPENDED';

    public const CONTRACTOR_INACTIVE = 'CONTRACTOR_INACTIVE';

    public const CONTRACTOR_UNKNOWN = 'CONTRACTOR_UNKNOWN';

    // ── Documents ─────────────────────────────────────────────────────────────────────────────
    public const DOCUMENT_MISSING = 'DOCUMENT_MISSING';

    public const DOCUMENT_EXPIRED = 'DOCUMENT_EXPIRED';

    public const DOCUMENT_NOT_ACCEPTED = 'DOCUMENT_NOT_ACCEPTED';

    public const DOCUMENT_REJECTED = 'DOCUMENT_REJECTED';

    // ── The safety file ───────────────────────────────────────────────────────────────────────
    public const SAFETY_FILE_NOT_APPROVED = 'SAFETY_FILE_NOT_APPROVED';

    public const SAFETY_FILE_EXPIRED = 'SAFETY_FILE_EXPIRED';

    public const SAFETY_FILE_SUSPENDED = 'SAFETY_FILE_SUSPENDED';

    // ── The site ──────────────────────────────────────────────────────────────────────────────
    public const SITE_NOT_APPROVED = 'SITE_NOT_APPROVED';

    public const SITE_BLOCKED = 'SITE_BLOCKED';

    public const SITE_APPROVAL_EXPIRED = 'SITE_APPROVAL_EXPIRED';

    // ── The person ────────────────────────────────────────────────────────────────────────────
    public const WORKER_UNKNOWN = 'WORKER_UNKNOWN';

    public const WORKER_INACTIVE = 'WORKER_INACTIVE';

    public const INDUCTION_REQUIRED = 'INDUCTION_REQUIRED';

    public const INDUCTION_EXPIRED = 'INDUCTION_EXPIRED';

    public const MEDICAL_REQUIRED = 'MEDICAL_REQUIRED';

    public const MEDICAL_EXPIRED = 'MEDICAL_EXPIRED';

    public const COMPETENCY_MISSING = 'COMPETENCY_MISSING';

    public const COMPETENCY_EXPIRED = 'COMPETENCY_EXPIRED';

    public const COMPETENCY_NOT_ACCEPTED = 'COMPETENCY_NOT_ACCEPTED';

    // ── The work ──────────────────────────────────────────────────────────────────────────────
    public const RISK_ASSESSMENT_REQUIRED = 'RISK_ASSESSMENT_REQUIRED';

    public const METHOD_STATEMENT_REQUIRED = 'METHOD_STATEMENT_REQUIRED';

    public const PERMIT_REQUIRED = 'PERMIT_REQUIRED';

    public const PPE_REQUIRED = 'PPE_REQUIRED';

    public const EQUIPMENT_CERTIFICATION_REQUIRED = 'EQUIPMENT_CERTIFICATION_REQUIRED';

    /**
     * Mandatory compliance could not be established either way (COM-3).
     *
     * Not a refusal and not a pass. It is the answer when the evidence was unreadable rather than
     * absent — the register the check reads does not exist on this installation, a lookup threw,
     * a date is stored in a shape nothing can parse. The distinction is the point of COM-3: a
     * check that cannot run must never come back as a check that passed.
     */
    public const EVIDENCE_UNAVAILABLE = 'EVIDENCE_UNAVAILABLE';

    /**
     * Every code, each with the thing it is about and whether it is specific to the work.
     *
     * `subject` drives the grouping on every screen that reports refusals, so a safety manager
     * reads "nine refusals, all of them documents" instead of nine unrelated lines.
     *
     * `activity_specific` marks the codes that can only arise when the question named a piece of
     * work. They are the ones that must not be counted against a contractor's standing in
     * general: a worker with no confined-space ticket is not non-compliant until somebody asks
     * them to enter a confined space.
     *
     * @var array<string, array{subject:string, activity_specific:bool}>
     */
    public const CATALOGUE = [
        self::CONTRACTOR_BLOCKED => ['subject' => 'CONTRACTOR', 'activity_specific' => false],
        self::CONTRACTOR_SUSPENDED => ['subject' => 'CONTRACTOR', 'activity_specific' => false],
        self::CONTRACTOR_INACTIVE => ['subject' => 'CONTRACTOR', 'activity_specific' => false],
        self::CONTRACTOR_UNKNOWN => ['subject' => 'CONTRACTOR', 'activity_specific' => false],

        self::DOCUMENT_MISSING => ['subject' => 'DOCUMENT', 'activity_specific' => false],
        self::DOCUMENT_EXPIRED => ['subject' => 'DOCUMENT', 'activity_specific' => false],
        self::DOCUMENT_NOT_ACCEPTED => ['subject' => 'DOCUMENT', 'activity_specific' => false],
        self::DOCUMENT_REJECTED => ['subject' => 'DOCUMENT', 'activity_specific' => false],

        self::SAFETY_FILE_NOT_APPROVED => ['subject' => 'SAFETY_FILE', 'activity_specific' => false],
        self::SAFETY_FILE_EXPIRED => ['subject' => 'SAFETY_FILE', 'activity_specific' => false],
        self::SAFETY_FILE_SUSPENDED => ['subject' => 'SAFETY_FILE', 'activity_specific' => false],

        self::SITE_NOT_APPROVED => ['subject' => 'SITE', 'activity_specific' => false],
        self::SITE_BLOCKED => ['subject' => 'SITE', 'activity_specific' => false],
        self::SITE_APPROVAL_EXPIRED => ['subject' => 'SITE', 'activity_specific' => false],

        self::WORKER_UNKNOWN => ['subject' => 'WORKER', 'activity_specific' => false],
        self::WORKER_INACTIVE => ['subject' => 'WORKER', 'activity_specific' => false],
        self::INDUCTION_REQUIRED => ['subject' => 'WORKER', 'activity_specific' => false],
        self::INDUCTION_EXPIRED => ['subject' => 'WORKER', 'activity_specific' => false],
        self::MEDICAL_REQUIRED => ['subject' => 'WORKER', 'activity_specific' => true],
        self::MEDICAL_EXPIRED => ['subject' => 'WORKER', 'activity_specific' => false],
        self::COMPETENCY_MISSING => ['subject' => 'WORKER', 'activity_specific' => true],
        self::COMPETENCY_EXPIRED => ['subject' => 'WORKER', 'activity_specific' => false],
        self::COMPETENCY_NOT_ACCEPTED => ['subject' => 'WORKER', 'activity_specific' => true],

        self::RISK_ASSESSMENT_REQUIRED => ['subject' => 'WORK', 'activity_specific' => true],
        self::METHOD_STATEMENT_REQUIRED => ['subject' => 'WORK', 'activity_specific' => true],
        self::PERMIT_REQUIRED => ['subject' => 'WORK', 'activity_specific' => true],
        self::PPE_REQUIRED => ['subject' => 'WORK', 'activity_specific' => true],
        self::EQUIPMENT_CERTIFICATION_REQUIRED => ['subject' => 'WORK', 'activity_specific' => true],

        self::EVIDENCE_UNAVAILABLE => ['subject' => 'SYSTEM', 'activity_specific' => false],
    ];

    /**
     * What has to be done to lift the block (AUTO-3).
     *
     * AUTO-3 asks a blocking notice to carry three things: the reason, the evidence, and the
     * required corrective action. The first two were already in the decision — every finding
     * carries a code and whatever evidence the check that produced it had — and the notice threw
     * them away, flattening the findings down to their English `detail` string. The third did not
     * exist anywhere.
     *
     * It belongs here rather than in the notifier because it is a property of the reason, not of
     * the message: the same remedy is the right answer on the block email, on the contractor
     * portal, on the access-refused screen at the boom, and in the AI copilot's answer to "why is
     * ABC blocked". Written once, said in all of them.
     *
     * Phrased as an instruction to the person who can act, and specific enough to act on without
     * a phone call — "upload the renewed certificate" rather than "resolve the issue". A code
     * with no remedy falls back to the portal, which is a worse answer and never a wrong one.
     */
    public const REMEDIES = [
        self::CONTRACTOR_BLOCKED => 'The block is on the contractor record itself and was set by a '
            .'person, not by a lapsed document. The SHEQ office has to lift it.',
        self::CONTRACTOR_SUSPENDED => 'The suspension was set by a person and has to be lifted by '
            .'one. Ask the SHEQ office what it was for.',
        self::CONTRACTOR_INACTIVE => 'The contractor record has been deactivated. It has to be '
            .'reactivated before anything else on this list matters.',
        self::CONTRACTOR_UNKNOWN => 'There is no contractor record to act on. Create one, or check '
            .'that the right company was scanned.',

        self::DOCUMENT_MISSING => 'Upload the document. It has never been provided, so there is '
            .'nothing to renew — the portal lists which one.',
        self::DOCUMENT_EXPIRED => 'Upload the renewed document. The old one stays on the record as '
            .'history; it does not need deleting.',
        self::DOCUMENT_NOT_ACCEPTED => 'The document has been uploaded and nobody has reviewed it '
            .'yet. This one is with the SHEQ office, not with you.',
        self::DOCUMENT_REJECTED => 'The document was reviewed and refused. The rejection reason on '
            .'the portal says what was wrong with it; correct that and upload it again.',

        self::SAFETY_FILE_NOT_APPROVED => 'Submit the safety file for review, or if it is already '
            .'submitted, wait for the review. The portal shows which sections are outstanding.',
        self::SAFETY_FILE_EXPIRED => 'The approval has run out. Resubmit the file — the documents '
            .'already in it stay, so this is usually a matter of refreshing the expired ones.',
        self::SAFETY_FILE_SUSPENDED => 'The approval was suspended by a person. The SHEQ office '
            .'has to reinstate it.',

        self::SITE_NOT_APPROVED => 'The contractor is approved, but not for this site. Request '
            .'approval for it — each site sets its own requirements and this one has not been '
            .'granted.',
        self::SITE_BLOCKED => 'This site has blocked the contractor specifically. Other sites are '
            .'unaffected; this one has to lift it.',
        self::SITE_APPROVAL_EXPIRED => 'The approval for this site has run out and has to be '
            .'renewed. Approval for other sites is unaffected.',

        self::WORKER_UNKNOWN => 'There is no worker record for this person. Add them to the '
            .'contractor roster before they travel to site.',
        self::WORKER_INACTIVE => 'The worker has been taken off the roster. If they are back, '
            .'reactivate them; if the wrong pass was scanned, check the name.',

        self::INDUCTION_REQUIRED => 'The worker has to complete the site induction. It cannot be '
            .'waived and no override reaches it.',
        self::INDUCTION_EXPIRED => 'The induction has lapsed and has to be redone. The refresher '
            .'is shorter than the full induction on most sites.',

        self::MEDICAL_REQUIRED => 'Book an occupational health examination and upload the '
            .'certificate of fitness.',
        self::MEDICAL_EXPIRED => 'Book the renewal examination. An expired medical refuses access '
            .'on its own and no temporary override can authorise it, so this one cannot be worked '
            .'around for a shift.',

        self::COMPETENCY_MISSING => 'The worker has never held this competency. Training has to be '
            .'completed and the certificate uploaded before they can do this work.',
        self::COMPETENCY_EXPIRED => 'The certificate has lapsed. Book the refresher and upload the '
            .'new certificate.',
        self::COMPETENCY_NOT_ACCEPTED => 'The certificate has been uploaded and not yet reviewed. '
            .'This one is with the SHEQ office.',

        self::RISK_ASSESSMENT_REQUIRED => 'This activity needs an approved risk assessment before '
            .'anybody may be signed onto it. The SHEQ office prepares and approves it.',
        self::METHOD_STATEMENT_REQUIRED => 'Submit a method statement for this activity and have '
            .'it accepted.',
        self::PERMIT_REQUIRED => 'This work needs a permit. Request one from the site — the '
            .'competency and medical on this list have to be in order first, because the permit '
            .'checks them again when it is issued.',
        self::PPE_REQUIRED => 'Issue the required PPE and record the issue, so the register shows '
            .'the worker has it.',
        self::EQUIPMENT_CERTIFICATION_REQUIRED => 'Upload the current certificate for the '
            .'equipment being used. The certificate is for the machine, not the operator.',

        self::EVIDENCE_UNAVAILABLE => 'Nothing is wrong with the contractor — a check could not be '
            .'run, so the system refused rather than guessing. This is for the SHEQ office or '
            .'system administrator to look at, and it will usually clear on its own.',
    ];

    /**
     * The required corrective action for a code (AUTO-3).
     *
     * Falls back to the portal for an unrecognised code, which is a worse answer than a specific
     * remedy and a better one than silence.
     */
    public static function remedyFor(string $code): string
    {
        return self::REMEDIES[$code]
            ?? 'The supplier portal shows what is outstanding and lets you send it.';
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function exists(string $code): bool
    {
        return isset(self::CATALOGUE[$code]);
    }

    /** What the code is about, for grouping. `SYSTEM` for anything unrecognised. */
    public static function subjectOf(string $code): string
    {
        return self::CATALOGUE[$code]['subject'] ?? 'SYSTEM';
    }

    /**
     * The translation key for a code.
     *
     * The code is the fact; this is how it gets said out loud. Every decision also carries a
     * written `detail`, which is what a guard reads — but a detail is composed in English by the
     * service that found the problem, and a screen in Sepedi needs something it can translate.
     */
    public static function keyOf(string $code): string
    {
        return 'sheq.compliance.reason.'.strtolower($code);
    }
}
