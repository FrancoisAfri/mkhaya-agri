<?php

namespace Tests\Feature\Sheq;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Traceability for the mandatory testing matrices (§21 to §31).
 *
 * The specification lists 105 numbered test cases across eleven matrices. Almost all of them were
 * already covered by the suite — this module has well over sixteen hundred tests — and none of
 * them was mapped to the identifier the specification uses. That gap is not cosmetic: "is TC-CON-09
 * covered?" was a question nobody could answer without reading every test, which in practice means
 * nobody answered it.
 *
 * So this is the map, and it is a test rather than a document for two reasons. A document goes
 * stale the first time somebody renames a test; this fails. And a case nobody has covered has to
 * be visible as a gap rather than absent from a list — `GAP` entries are counted and reported,
 * so the number of untested requirements is a figure somebody can look at instead of a thing they
 * have to go and work out.
 *
 * Each entry names the class that covers the case. Deliberately the class and not the method:
 * a method name is an implementation detail that changes with the wording of a test, and pinning
 * 105 of them would make this file the most frequently broken test in the module for no gain. The
 * class is stable, and `assertTrue(class_exists(...))` catches the case that actually matters —
 * somebody deleting the coverage.
 */
class RequirementMatrixTest extends TestCase
{
    /** Marks a case with no coverage. Counted and reported rather than quietly omitted. */
    private const GAP = 'GAP';

    /**
     * The 105 cases, by identifier.
     *
     * @return array<string, array{0:string, 1:string, 2:string}>  id => [covered-by, scenario, note]
     */
    public static function matrix(): array
    {
        $cases = [
            // ── §21 Contractor ──────────────────────────────────────────────────────────────
            'TC-CON-01' => [ContractorTest::class, 'Create contractor', ''],
            'TC-CON-02' => [ContractorTest::class, 'Create worker', ''],
            'TC-CON-03' => [ContractorDocumentEngineTest::class,
                'Remove mandatory document', ''],
            'TC-CON-04' => [ExpiryAutomationTest::class, 'Expire document', ''],
            'TC-CON-05' => [SafetyFileWorkflowTest::class, 'Submit safety file', ''],
            'TC-CON-06' => [SafetyFileWorkflowTest::class, 'Reject safety file', ''],
            'TC-CON-07' => [SafetyFileWorkflowTest::class, 'Approve safety file', ''],
            'TC-CON-08' => [AccessPassTest::class, 'Scan approved QR', ''],
            'TC-CON-09' => [EndToEndScenarioTest::class,
                'Expire document after QR approval', 'E2E-02 walks the whole transition.'],
            'TC-CON-10' => [SiteApprovalTest::class, 'Site A approved, site B not', ''],
            'TC-CON-11' => [ActivityAccessTest::class, 'Worker lacks competency', ''],
            'TC-CON-12' => [AccessOverrideTest::class, 'Temporary override', ''],
            'TC-CON-13' => [AccessOverrideTest::class, 'Override expires', ''],
            'TC-CON-14' => [ContractorHistoryTest::class,
                'Upload replacement, old stays historical', ''],
            'TC-CON-15' => [ComplianceDecisionTest::class,
                'Approve replacement, compliance recalculates', ''],
            'TC-CON-16' => [ContractorPortalTest::class, 'Contractor portal scoping', ''],
            'TC-CON-17' => [SubcontractorTest::class, 'Parent/child permissions', ''],
            'TC-CON-18' => [AccessPassTest::class, 'QR tampering', ''],
            'TC-CON-19' => [SecurityRequirementsTest::class, 'QR enumeration', ''],
            'TC-CON-20' => [ContractorComplianceStatusTest::class,
                'Dashboard reflects the block', ''],

            // ── §22 Compliance engine ───────────────────────────────────────────────────────
            'TC-COM-01' => [ComplianceDecisionTest::class, 'All requirements valid', ''],
            'TC-COM-02' => [ComplianceDecisionTest::class, 'One mandatory failure', ''],
            'TC-COM-03' => [ComplianceDecisionTest::class, 'Multiple failures', ''],
            'TC-COM-04' => [ComplianceDecisionTest::class,
                'Service unavailable, no silent approval', 'Also SecurityRequirementsTest.'],
            'TC-COM-05' => [ComplianceDecisionTest::class, 'Replace failed evidence', ''],
            'TC-COM-06' => [ComplianceDecisionTest::class, 'Evidence approved, block removed', ''],
            'TC-COM-07' => [ActivityAccessTest::class, 'Activity changes', ''],
            'TC-COM-08' => [SiteApprovalTest::class, 'Site changes', ''],
            'TC-COM-09' => [ComplianceDecisionTest::class, 'Worker changes', ''],

            // ── §23 Mobile ──────────────────────────────────────────────────────────────────
            'TC-MOB-01' => [SyncTest::class, 'Offline hazard saved locally',
                'Server side only: there is no mobile client, so the device half is untested.'],
            'TC-MOB-02' => [SyncTest::class, 'Reconnect syncs once', ''],
            'TC-MOB-03' => [SyncTest::class, 'Offline incident syncs', ''],
            'TC-MOB-04' => [ChunkedUploadApiTest::class, 'Offline photograph uploads', ''],
            'TC-MOB-05' => [ChunkedUploadApiTest::class, 'Upload resumes', ''],
            'TC-MOB-06' => [SyncTest::class, 'Duplicate replay', ''],
            'TC-MOB-07' => [SyncChildCollectionTest::class, 'Witness syncs', ''],
            'TC-MOB-08' => [ChunkedUploadApiTest::class, 'Evidence syncs', ''],
            'TC-MOB-09' => [SyncChildCollectionTest::class, 'Cause syncs', ''],
            'TC-MOB-10' => [SyncTest::class, 'Conflict preserves both versions', ''],
            'TC-MOB-11' => [SyncTest::class, 'Invalid transition rejected with explanation', ''],

            // ── §24 Management of change ────────────────────────────────────────────────────
            'TC-MOC-01' => [ChangeManagementTest::class, 'Create change', ''],
            'TC-MOC-02' => [ChangeManagementTest::class, 'Change affects HIRA', ''],
            'TC-MOC-03' => [ChangeManagementTest::class, 'Change affects training', ''],
            'TC-MOC-04' => [EndToEndScenarioTest::class,
                'Unapproved change blocked', 'E2E-07 walks it.'],
            'TC-MOC-05' => [ChangeManagementTest::class, 'Approval allows implementation', ''],
            'TC-MOC-06' => [ChangeManagementTest::class, 'Effectiveness review created', ''],
            'TC-MOC-07' => [ChangeManagementTest::class, 'Failed effectiveness raises CAPA', ''],

            // ── §25 Quality ─────────────────────────────────────────────────────────────────
            'TC-QLT-01' => [QualityTest::class, 'Quality inspection uses the right template', ''],
            'TC-QLT-02' => [QualityTest::class, 'Failed inspection generates NCR/action', ''],
            'TC-QLT-03' => [QualityTest::class, 'NCR enforces required information', ''],
            'TC-QLT-04' => [QualityTest::class, 'Evidence retained', ''],
            'TC-QLT-05' => [QualityTest::class, 'High severity needs independent verification', ''],
            'TC-QLT-06' => [QualityTest::class, 'NCR closes only when satisfied', ''],
            'TC-QLT-07' => [QualityTest::class, 'Repeat defect detected', ''],
            'TC-QLT-08' => [ContractorScoreTest::class, 'Contractor defect affects score', ''],

            // ── §26 Environmental ───────────────────────────────────────────────────────────
            'TC-ENV-01' => [EnvironmentalRegisterTest::class, 'Create aspect', ''],
            'TC-ENV-02' => [EnvironmentalRegisterTest::class, 'Monitoring below limit', ''],
            'TC-ENV-03' => [EnvironmentalRegisterTest::class, 'Monitoring above limit alerts', ''],
            'TC-ENV-04' => [EnvironmentalRegisterTest::class, 'Spill workflow', ''],
            'TC-ENV-05' => [EnvironmentalRegisterTest::class, 'Waste manifest retained', ''],
            'TC-ENV-06' => [EnvironmentalRegisterTest::class, 'Hazardous waste controls', ''],
            'TC-ENV-07' => [EnvironmentalRegisterTest::class, 'Environmental incident CAPA', ''],

            // ── §27 Occupational health ─────────────────────────────────────────────────────
            'TC-OH-01' => [OccupationalHealthTest::class, 'Create exposure', ''],
            'TC-OH-02' => [OccupationalHealthTest::class, 'Exposure exceeds threshold', ''],
            'TC-OH-03' => [OccupationalHealthTest::class, 'Medical required flags worker', ''],
            'TC-OH-04' => [ExpiryAutomationTest::class, 'Medical expires', ''],
            'TC-OH-05' => [OccupationalHealthTest::class, 'Exposure history retained', ''],
            'TC-OH-06' => [OccupationalHealthTest::class, 'PPE linked', ''],

            // ── §28 Emergency ───────────────────────────────────────────────────────────────
            'TC-EMR-01' => [EmergencyCommandTest::class, 'Declare emergency', ''],
            'TC-EMR-02' => [EmergencyCommandTest::class, 'View occupancy', ''],
            'TC-EMR-03' => [EmergencyCommandTest::class, 'Mark missing', ''],
            'TC-EMR-04' => [EmergencyCommandTest::class, 'Mark accounted with timestamp', ''],
            'TC-EMR-05' => [EmergencyCommandTest::class, 'View the site plan', ''],
            'TC-EMR-06' => [EmergencyCommandTest::class, 'View active permits', ''],
            'TC-EMR-07' => [EmergencyCommandTest::class, 'Drill history and actions', ''],

            // ── §29 AI ──────────────────────────────────────────────────────────────────────
            'TC-AI-01' => [AiChatTest::class, 'Answer has citations', ''],
            'TC-AI-02' => [AiCopilotTest::class, 'Contractor status matches the engine', ''],
            'TC-AI-03' => [AiCopilotTest::class, 'Why blocked shows exact evidence', ''],
            'TC-AI-04' => [AiCopilotTest::class, 'Recurring hazards supported by evidence', ''],
            'TC-AI-05' => [AiCopilotTest::class, 'Draft report generated', ''],
            'TC-AI-06' => [AiCopilotTest::class, 'Approve permit refused', ''],
            'TC-AI-07' => [AiCopilotTest::class, 'Close incident refused', ''],
            'TC-AI-08' => [AiCopilotTest::class, 'Block contractor refused', ''],
            'TC-AI-09' => [AiCopilotTest::class, 'Alter risk rating refused', ''],
            'TC-AI-10' => [AiCopilotTest::class, 'Cross-tenant prompt leaks nothing', ''],
            'TC-AI-11' => [AiGovernanceTest::class, 'Uncited answer rejected', ''],
            'TC-AI-12' => [DataProtectionTest::class, 'Sensitive data redacted', ''],

            // ── §30 Security ────────────────────────────────────────────────────────────────
            'TC-SEC-01' => [SecurityRequirementsTest::class, 'Cross-tenant read denied', ''],
            'TC-SEC-02' => [SiteEntitlementTest::class,
                'Site-scoped user denied an unauthorised site',
                'Enforced, and off until a host switches it on per tenant — see the migration '
                .'for why enforcing on a unit assignment globally would re-segregate every '
                .'existing installation unasked.'],
            'TC-SEC-03' => [AccessPassTest::class, 'Tampered QR denied', ''],
            'TC-SEC-04' => [AccessPassTest::class, 'Expired QR token denied', ''],
            'TC-SEC-05' => [SecurityRequirementsTest::class, 'QR brute force rate limited', ''],
            'TC-SEC-06' => [FileStorageTest::class, 'Unauthorised file access denied', ''],
            'TC-SEC-07' => [DataProtectionTest::class, 'Sensitive field encryption enforced', ''],
            'TC-SEC-08' => [SecurityRequirementsTest::class, 'Audit manipulation prevented', ''],
            'TC-SEC-09' => [IntegrationContractTest::class, 'API replay is idempotent', ''],
            'TC-SEC-10' => [CronTenancyTest::class, 'Jobs are tenant scoped', ''],

            // ── §31 Regulatory and production gaps ──────────────────────────────────────────
            'TC-REG-01' => [RegulatoryAssuranceTest::class, 'A different pack per site', ''],
            'TC-REG-02' => [RegulatoryAssuranceTest::class, 'Fresh installation readiness', ''],
            'TC-REG-03' => [StatutoryFormPdfTest::class, 'Generate a populated statutory PDF', ''],
            'TC-REG-04' => [RegulatoryAssuranceTest::class, 'Valid pack accepted', ''],
            'TC-REG-05' => [RegulatoryAssuranceTest::class, 'Invalid pack rejected', ''],
            'TC-REG-06' => [SiteTimezoneTest::class, 'Timezone boundary', ''],
            'TC-REG-07' => [RefresherEnrolmentTest::class, 'Refresher auto-enrolled', ''],
            'TC-REG-08' => [SyncChildCollectionTest::class,
                'Offline witness/evidence/cause sync', ''],
        ];

        $out = [];

        foreach ($cases as $id => [$class, $scenario, $note]) {
            $out[$id] = [$id, $class, $scenario, $note];
        }

        return $out;
    }

    #[DataProvider('matrix')]
    public function test_the_case_is_covered_by_a_test_that_exists(
        string $id, string $class, string $scenario, string $note
    ): void {
        if ($class === self::GAP) {
            /*
             * An explicit gap. Marked rather than omitted, and it must carry a reason — a gap
             * with no explanation is indistinguishable from an oversight, and the next person
             * to read this cannot tell whether it was a decision.
             */
            $this->assertGreaterThan(60, strlen($note),
                "{$id} is marked as a gap with no explanation of why.");

            $this->markTestIncomplete("{$id} ({$scenario}) is not covered: {$note}");
        }

        $this->assertTrue(class_exists($class),
            "{$id} ({$scenario}) is mapped to {$class}, which does not exist. Either the "
            .'coverage was deleted or the class was renamed — and in both cases the requirement '
            .'is now untested.');
    }

    public function test_every_matrix_in_the_specification_is_represented(): void
    {
        /*
         * The counts the specification itself gives. A matrix silently losing entries is the
         * failure this guards: somebody tidies the map, the total drops, and the coverage figure
         * improves because the denominator shrank.
         */
        $expected = [
            'TC-CON' => 20, 'TC-COM' => 9, 'TC-MOB' => 11, 'TC-MOC' => 7, 'TC-QLT' => 8,
            'TC-ENV' => 7, 'TC-OH' => 6, 'TC-EMR' => 7, 'TC-AI' => 12, 'TC-SEC' => 10,
            'TC-REG' => 8,
        ];

        $actual = [];

        foreach (array_keys(self::matrix()) as $id) {
            $prefix = substr($id, 0, (int) strrpos($id, '-'));
            $actual[$prefix] = ($actual[$prefix] ?? 0) + 1;
        }

        foreach ($expected as $prefix => $count) {
            $this->assertSame($count, $actual[$prefix] ?? 0,
                "The {$prefix} matrix has {$count} cases in the specification and "
                .($actual[$prefix] ?? 0).' in this map.');
        }

        $this->assertSame(105, count(self::matrix()),
            'The specification lists 105 cases across eleven matrices.');
    }

    public function test_the_number_of_uncovered_cases_is_visible(): void
    {
        /*
         * The figure somebody can look at. Not an assertion that there are none — there is one,
         * and pretending otherwise would be worse than recording it — but an assertion that it
         * has not grown, so a case losing its coverage fails here.
         */
        $gaps = array_filter(self::matrix(), fn (array $row) => $row[1] === self::GAP);

        /*
         * Zero. TC-SEC-02 was the last one and is now enforced; this stays as an assertion
         * rather than being deleted, because a case losing its coverage has to fail here rather
         * than quietly reappearing as a gap nobody counted.
         */
        $this->assertCount(0, $gaps,
            'A matrix case has lost its coverage: '.implode(', ', array_keys($gaps)));
    }
}
