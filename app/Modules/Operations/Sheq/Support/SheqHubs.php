<?php

namespace App\Modules\Operations\Sheq\Support;

use App\Support\Hubs\ModuleHubs;

/**
 * SHEQ navigation hubs (Operations & Support).
 *
 * Safety · Risk · Assurance · Insights · Compliance · Configure.
 *
 * The rule that governs this file: a card points at a page that exists. A card linking to an
 * unbuilt page is a 404 with a promise attached, and the hub test suite fails the build for exactly
 * that reason — which is why the module ran for a while with only two hubs while six subsystems had
 * working backends and no screens. Those screens now exist, so the hubs do.
 *
 * Still deliberately absent: AI recommendations. §7's AI Safety Officer is not built, and a card
 * leading to invented advice would breach SHEQ-AI-1 (draft only), SHEQ-AI-2 (every assertion cited)
 * and SHEQ-AI-11 (hard eval gate) all at once. In this domain a confident wrong answer is worse
 * than no answer, so there is no card until there is a model behind it.
 */
class SheqHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'sheq';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'sheq', 'icon' => 'shield-check', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'safety' => [
                'name' => 'Safety', 'path' => 'sheq/safety-hub', 'icon' => 'shield-alert', 'tier' => self::READ,
                'title' => 'sheq.hubs.safety.title', 'subtitle' => 'sheq.hubs.safety.subtitle',
                'cards' => [
                    // READ, deliberately: every worker must be able to report, and gating that
                    // behind a paid tier suppresses the reporting the module exists to encourage.
                    self::card('report_hazard', 'sheq/hazards/create', 'shield-alert-outline', self::READ, 'amber'),
                    self::card('report_incident', 'sheq/incidents/create', 'alert-plus', self::READ, 'red'),
                    self::card('hazards', 'sheq/hazards', 'shield-search', self::READ, 'teal'),
                    self::card('incidents', 'sheq/incidents', 'clipboard-alert-outline', self::READ, 'indigo'),
                    // The queue between reporting and investigating. READ, like reporting itself:
                    // a triage list only a manager can open is one that waits for a manager, and
                    // the whole value of triage is that it happens within hours.
                    self::card('triage', 'sheq/triage', 'inbox-arrow-down-outline', self::READ, 'orange'),
                    self::card('actions', 'sheq/actions', 'clipboard-check-outline', self::READ, 'green'),
                    // Beside My Actions, for the same reason that one exists: a person needs one
                    // place that answers "what is mine". READ, and no further entitlement — it is
                    // their own record, and the competency register they would otherwise have to
                    // be shown needs a permission they do not hold.
                    self::card('my_compliance', 'sheq/my-compliance', 'account-check-outline', self::READ, 'cyan'),
                    self::card('obligations', 'sheq/obligations', 'gavel', self::READ, 'orange'),
                    // The forms that discharge those obligations (SHEQ-REG-6).
                    self::card('statutory_forms', 'sheq/forms', 'file-document-edit-outline', self::READ, 'red'),
                    self::card('investigations', 'sheq/investigations', 'magnify-scan', self::READ, 'deep-purple'),
                    // What an investigation is for: carrying the finding to the people exposed to
                    // the same hazard, and following the person who got hurt until they are back.
                    self::card('safety_alerts', 'sheq/safety-alerts', 'bullhorn-outline', self::READ, 'pink'),
                    self::card('injury_cases', 'sheq/injury-cases', 'medical-bag', self::READ, 'blue'),
                    self::card('ppe', 'sheq/ppe', 'hard-hat', self::READ, 'amber'),
                    self::card('toolbox_talks', 'sheq/talks', 'account-group-outline', self::READ, 'lime'),
                    self::card('visitors', 'sheq/visitors', 'account-clock-outline', self::READ, 'cyan'),
                    self::card('inductions', 'sheq/inductions', 'card-account-details-outline', self::READ, 'purple'),
                    self::card('emergency', 'sheq/emergency', 'alarm-light-outline', self::READ, 'red'),
                ],
            ],

            'risk' => [
                'name' => 'Risk', 'path' => 'sheq/risk-hub', 'icon' => 'chart-bell-curve', 'tier' => self::READ,
                'title' => 'sheq.hubs.risk.title', 'subtitle' => 'sheq.hubs.risk.subtitle',
                'cards' => [
                    self::card('risk_assessments', 'sheq/risk-assessments', 'clipboard-list-outline', self::READ, 'deep-purple'),
                    self::card('permits', 'sheq/permits', 'file-certificate-outline', self::READ, 'orange'),
                    // Beside the register, not inside it: the register answers "every permit we
                    // have ever raised" and the board answers "what is live on my site now". The
                    // second question is asked from a control room, by someone who needs it in one
                    // screen rather than filtered out of two hundred rows.
                    self::card('permit_board', 'sheq/permits/board', 'monitor-dashboard', self::READ, 'deep-orange'),
                    // Everything in the module that needs a decision surfaces here.
                    self::card('approvals', 'sheq/approvals', 'check-decagram-outline', self::READ, 'green'),
                ],
            ],

            'assurance' => [
                'name' => 'Assurance', 'path' => 'sheq/assurance-hub', 'icon' => 'clipboard-check', 'tier' => self::READ,
                'title' => 'sheq.hubs.assurance.title', 'subtitle' => 'sheq.hubs.assurance.subtitle',
                'cards' => [
                    self::card('inspections', 'sheq/inspections', 'clipboard-text-search-outline', self::READ, 'teal'),
                    // Audits are not bigger inspections: a finding cites a clause and needs
                    // independent verification, which is why they live apart.
                    self::card('audits', 'sheq/audits', 'clipboard-check-multiple-outline', self::READ, 'indigo'),
                    self::card('suppliers', 'sheq/suppliers', 'handshake-outline', self::READ, 'brown'),
                    self::card('equipment', 'sheq/equipment', 'fire-extinguisher', self::READ, 'red'),
                    self::card('competency', 'sheq/competency', 'account-school-outline', self::READ, 'blue'),
                    self::card('documents', 'sheq/documents', 'file-document-multiple-outline', self::READ, 'indigo'),
                    /*
                     * The reader's half of document control.
                     *
                     * `documents` is the practitioner's register — it needs `sheq.document.manage`,
                     * shows drafts and offers publishing. This is where everybody else finds the
                     * procedure that governs them, and a controlled document nobody can find
                     * controls nothing.
                     */
                    self::card('library', 'sheq/library', 'bookshelf', self::READ, 'teal'),
                    self::card('contractors', 'sheq/contractors', 'account-hard-hat-outline', self::READ, 'brown'),
                ],
            ],

            'insights' => [
                'name' => 'Insights', 'path' => 'sheq/insights-hub', 'icon' => 'chart-line', 'tier' => self::READ,
                'title' => 'sheq.hubs.insights.title', 'subtitle' => 'sheq.hubs.insights.subtitle',
                'cards' => [
                    self::card('bi_analysis', 'sheq/insights/analysis', 'chart-timeline-variant', self::READ, 'cyan'),
                    self::card('bi_reports', 'sheq/insights/reports', 'file-chart-outline', self::READ, 'green'),
                    // Beside the rates it feeds: somebody looking at an LTIFR marked "estimated"
                    // needs the hours one click away, not in a settings screen.
                    self::card('exposure', 'sheq/exposure', 'clock-outline', self::READ, 'orange'),
                    // The assistant's review queue (§7). It lives under Insights rather than
                    // beside the records it drafts about, because what a reviewer does here is
                    // judge output quality — not work an incident.
                    self::card('ai_assistant', 'sheq/ai', 'robot-outline', self::READ, 'blue-grey'),
                ],
            ],

            'compliance' => [
                'name' => 'Compliance', 'path' => 'sheq/compliance-hub', 'icon' => 'scale-balanced', 'tier' => self::READ,
                'title' => 'sheq.hubs.compliance.title', 'subtitle' => 'sheq.hubs.compliance.subtitle',
                'cards' => [
                    self::card('regulatory_packs', 'sheq/regulatory-packs', 'book-open-page-variant', self::READ, 'indigo'),
                    self::card('legal_register', 'sheq/legal-register', 'scale-balance', self::READ, 'teal'),
                    /*
                     * Who is on site, right now (CON-16). In Operations rather than Configure
                     * because it is read during an evacuation, and the person who opens it then
                     * is whoever is nearest a screen — not somebody who knows where the
                     * configuration lives.
                     */
                    /*
                     * The one question the whole module exists to answer (COM-1). First in
                     * Operations, above the registers that feed it: somebody who has been told
                     * "the system says no" comes here, and sending them to a contractor profile
                     * to infer it from a status column is how a safety system loses its
                     * credibility.
                     */
                    /*
                     * The live command centre (CMD-1 to CMD-8). First, because it is the screen
                     * somebody opens to find out whether anything needs them today — every other
                     * card here answers a question you already knew you had.
                     */
                    self::card('command_centre', 'sheq/command-centre',
                        'view-dashboard-variant', self::READ, 'indigo'),
                    /*
                     * The emergency command centre (EMR-1 to EMR-6). Above everything else in
                     * Operations, and the only card in this module whose position is about
                     * seconds: it is opened by somebody who has just heard an alarm, and a card
                     * they have to scroll to find is a card they will not find. Red, for the
                     * same reason.
                     */
                    self::card('emergency_command', 'sheq/emergency-command',
                        'alarm-light', self::READ, 'red'),
                    /*
                     * Risk intelligence (RISK-1 to RISK-5). First in Operations because the
                     * high-risk board on it is read NOW by somebody deciding whether to let work
                     * continue — the trends behind it are a monthly read, and the board is not.
                     */
                    self::card('risk_intelligence', 'sheq/risk-intelligence',
                        'radar', self::READ, 'red'),
                    self::card('compliance_check', 'sheq/compliance', 'shield-search',
                        self::READ, 'teal'),
                    /*
                     * What people were seen doing (OBS-1 to OBS-4). High in Operations because
                     * it is the one register whose value is proportional to how many people
                     * file into it — a behavioural programme buried three clicks down gets
                     * observations from the safety officer and nobody else.
                     */
                    /*
                     * Quality (QLT-1 to QLT-8). High in Operations because the module was a
                     * safety system with quality mentioned in passing, and the Q is supposed to
                     * carry the same weight as the S and the H — a card buried under Configure
                     * would say otherwise.
                     */
                    self::card('quality', 'sheq/quality', 'certificate-outline',
                        self::READ, 'blue'),
                    self::card('observations', 'sheq/observations', 'eye-outline',
                        self::READ, 'cyan'),
                    /*
                     * Management of change (MOC-1 to MOC-5). In Operations rather than Configure
                     * because a change is raised by whoever is making it — an engineer, a
                     * buyer, a site manager — and not by somebody who knows where the
                     * configuration lives.
                     */
                    self::card('changes', 'sheq/changes', 'swap-horizontal-variant',
                        self::READ, 'orange'),
                    /*
                     * The contractor's whole story (CON-22, COM-5). Beside the compliance check
                     * because they are read together: one says what the answer is, the other
                     * says what made it that — and the second is the half anybody can act on.
                     */
                    /*
                     * Who appointed whom (CON-25). A card of its own because the chain is
                     * configured by somebody arranging contracts, not by whoever opens a single
                     * contractor's profile — and the visibility control on it decides what one
                     * company is shown about another.
                     */
                    self::card('subcontractors', 'sheq/subcontractors', 'sitemap-outline',
                        self::READ, 'indigo'),
                    self::card('contractor_history', 'sheq/contractor-history', 'history',
                        self::READ, 'brown'),
                    /*
                     * The document version chain (CON-24). Beside the history because they
                     * answer the same kind of question — what was true then, as against what is
                     * true now — and the contractor profile deliberately shows only the live
                     * document, which is right for a gate and useless for an audit.
                     */
                    self::card('document_versions', 'sheq/document-versions',
                        'file-document-multiple-outline', self::READ, 'blue-grey'),
                    /*
                     * The safety record and how the number was arrived at (CON-20, CON-21). A
                     * card of its own because it is read at renewal by somebody from
                     * procurement, who has no reason to know where a contractor profile lives.
                     */
                    self::card('contractor_performance', 'sheq/contractor-performance',
                        'chart-timeline-variant', self::READ, 'green'),
                    self::card('attendance', 'sheq/attendance', 'account-clock-outline',
                        self::READ, 'deep-purple'),
                    /*
                     * Every code scanned at a gate (CON-23). Next to attendance because they are
                     * two halves of the same morning: attendance says who got in, this says what
                     * was presented — including the codes that matched nobody, which is the part
                     * nothing else in the module could show.
                     */
                    self::card('pass_scans', 'sheq/pass-scans', 'qrcode-scan',
                        self::READ, 'purple'),
                    /*
                     * Temporary access (CON-17). A card of its own because an override's safety
                     * comes from being seen: on a contractor's profile it is read only by
                     * somebody who already went looking for that contractor.
                     */
                    self::card('overrides', 'sheq/overrides', 'key-variant',
                        self::READ, 'amber'),
                    /*
                     * What contractors are told, and whether it arrived (CON-19). A card of
                     * its own because a notification that was never sent looks exactly like
                     * one that was sent and ignored, and nothing else in the module shows the
                     * difference.
                     */
                    self::card('contractor_notices', 'sheq/contractor-notices',
                        'email-fast-outline', self::READ, 'light-blue'),
                    /*
                     * Occupational health and hygiene (OH-1 to OH-6). Beside the medical card
                     * because the two are one obligation seen from opposite ends: exposure is
                     * what puts somebody into surveillance, and the medical is the surveillance.
                     */
                    self::card('occupational_health', 'sheq/occupational-health',
                        'air-filter', self::READ, 'purple'),
                    self::card('medical', 'sheq/medical', 'stethoscope', self::READ, 'pink'),
                    /*
                     * Aspects, waste, spills and limits (ENV-1 to ENV-7). The card already
                     * existed and pointed at consumption monitoring; this is the register
                     * behind it, and the two reportable counts on it are the reason the section
                     * exists rather than a report.
                     */
                    self::card('environmental', 'sheq/environmental', 'leaf', self::READ, 'light-green'),
                    /*
                     * Aspects, waste, spills and limits (ENV-1 to ENV-7). Beside the
                     * consumption screen rather than merged into it: a sustainability report is
                     * compiled from meter totals once a quarter, and a reportable spill is
                     * cleared by whoever is on site this afternoon.
                     */
                    self::card('environmental_registers', 'sheq/environmental-registers',
                        'water-alert-outline', self::READ, 'green'),
                    // Read-only: the records live in Learning Management, not here.
                    self::card('training', 'sheq/training', 'school-outline', self::READ, 'deep-orange'),
                ],
            ],

            'configure' => [
                'name' => 'Configure', 'path' => 'sheq/configure-hub', 'icon' => 'cog-outline', 'tier' => self::READ,
                'title' => 'sheq.hubs.configure.title', 'subtitle' => 'sheq.hubs.configure.subtitle',
                'cards' => [
                    self::card('sites', 'sheq/sites', 'map-marker-outline', self::READ, 'blue-grey'),
                    /*
                     * What work is done here, and what each kind takes (CON-15). Beside sites
                     * because the two together are what an access decision is made against:
                     * contractor + worker + site + activity + time.
                     */
                    self::card('activities', 'sheq/activities', 'clipboard-list-outline',
                        self::READ, 'cyan'),
                    // The conflict queue is configuration-adjacent but belongs where somebody looks for it
                    // when a device reports a problem (SHEQ-OFF-5).
                    self::card('sync_conflicts', 'sheq/sync/conflicts', 'sync-alert', self::READ, 'amber'),
                    self::card('approval_workflows', 'sheq/approvals/workflows', 'sitemap-outline', self::ADMIN, 'indigo'),
                    // Where a gate is connected. ADMIN, because these credentials open a door.
                    self::card('integrations', 'sheq/integrations', 'lan-connect', self::ADMIN, 'blue'),
                    // Listed above settings: an empty PPE catalogue is the commonest reason the
                    // issue register looks broken, and this is where somebody fixes it.
                    self::card('ppe_catalogue', 'sheq/settings/ppe', 'shield-account-outline', self::ADMIN, 'teal'),
                    self::card('settings', 'sheq/settings', 'tune', self::READ, 'grey'),
                ],
            ],
        ];
    }

    /**
     * Defence in depth for URLs that are not an exact card: anything under sheq/ needs at least
     * READ on the module, so a direct URL cannot reach further than the nav would allow.
     */
    public static function guards(): array
    {
        return ['sheq' => self::READ];
    }
}
