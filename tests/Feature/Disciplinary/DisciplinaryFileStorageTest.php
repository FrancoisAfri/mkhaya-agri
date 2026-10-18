<?php

namespace Tests\Feature\Disciplinary;

use App\Modules\Disciplinary\Models\DisciplinaryAiEvidenceReport;
use App\Modules\Disciplinary\Models\DisciplinaryAppealEvidence;
use App\Modules\Disciplinary\Models\DisciplinaryCorrectiveAction;
use App\Modules\Disciplinary\Models\DisciplinaryEvidence;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigationReport;
use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use App\Modules\Disciplinary\Models\DisciplinaryWarning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Files attached to disciplinary cases.
 *
 * Written because this module had no tests at all — `--filter Disciplinary` executed nothing — and
 * seven of its models were moved onto the shared storage without a single assertion covering them.
 * A module holding warnings, suspensions and appeal evidence is a poor place for that to be true.
 *
 * The headers matter more here than anywhere else in the platform. Investigation reports and
 * hearing packs are generated HTML, and they used to be served as text/html with no `nosniff` —
 * the browser renders them same-origin with the application, so anything script-like inside reads
 * the session of the HR user reading evidence in a live case.
 */
class DisciplinaryFileStorageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);
    }

    /** @return list<class-string> */
    public static function fileOwningModels(): array
    {
        return [
            [DisciplinaryEvidence::class],
            [DisciplinaryWarning::class],
            [DisciplinarySuspension::class],
            [DisciplinaryCorrectiveAction::class],
            [DisciplinaryAppealEvidence::class],
            [DisciplinaryInvestigationReport::class],
            [DisciplinaryAiEvidenceReport::class],
        ];
    }

    #[DataProvider('fileOwningModels')]
    public function test_every_model_stores_and_returns_its_bytes(string $model): void
    {
        $record = new $model;
        $payload = "%PDF-1.4\x00 evidence " . random_bytes(256);

        $path = $record->writeBytes($payload, 'pdf');

        try {
            $this->assertTrue(Storage::disk('documents')->exists($path));
            $this->assertSame($payload, $record->bytes());
            $this->assertTrue($record->hasBytes());
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    #[DataProvider('fileOwningModels')]
    public function test_no_model_serves_a_file_as_something_the_browser_will_render(string $model): void
    {
        $record = new $model;
        $path = $record->writeBytes('<script>alert(document.cookie)</script>', 'html');

        try {
            $response = $record->fileResponse('report.html', 'text/html');

            // The defect this replaced: text/html, no nosniff, rendered same-origin with the
            // application while an HR user reads evidence in a live case.
            $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_a_case_file_is_never_left_in_a_shared_cache(): void
    {
        $record = new DisciplinaryEvidence;
        $path = $record->writeBytes('witness statement', 'txt');

        try {
            $cacheControl = $record->fileResponse('statement.txt', 'text/plain')->headers->get('Cache-Control');

            foreach (['private', 'no-store'] as $directive) {
                $this->assertStringContainsString($directive, $cacheControl);
            }
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_a_filename_cannot_escape_the_header(): void
    {
        $record = new DisciplinaryEvidence;
        $path = $record->writeBytes('x', 'txt');

        try {
            $disposition = $record
                ->fileResponse('evidence"; filename="payslip.pdf', 'text/plain')
                ->headers->get('Content-Disposition');

            $this->assertSame(1, substr_count($disposition, 'filename='));
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_evidence_written_before_the_move_still_opens(): void
    {
        // Every existing row in the module is this case: bytes in the column, no path. If it ever
        // stops working, the evidence attached to open cases is what disappears.
        $record = new DisciplinaryEvidence;
        $record->file_data = base64_encode('an older statement');

        $this->assertSame('an older statement', $record->bytes());
        $this->assertTrue($record->hasBytes());
    }

    public function test_nothing_attached_reads_as_nothing(): void
    {
        $record = new DisciplinaryEvidence;

        $this->assertNull($record->bytes());
        $this->assertFalse($record->hasBytes());
    }

    public function test_a_missing_file_is_refused_rather_than_served_empty(): void
    {
        $record = new DisciplinaryEvidence;
        $record->storage_path = 'disciplinary/evidence/gone.pdf';

        // An empty 200 would look like a blank document to whoever opened it, which in a
        // disciplinary case is worse than an error.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $record->fileResponse('gone.pdf', 'application/pdf');
    }
}
