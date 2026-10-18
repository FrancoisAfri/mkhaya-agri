<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Services\CoaImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CoaImportTest extends TestCase
{
    use DatabaseTransactions;

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'coa') . '.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'coa.csv', 'text/csv', null, true);
    }

    public function test_imports_valid_rows_and_links_parents(): void
    {
        $csv = "code,name,type,parent_code,is_postable\n"
            . "Z100,Test Assets,asset,,0\n"
            . "Z110,Test Bank,asset,Z100,1\n"
            . "Z400,Test Sales,income,,1\n";

        $result = app(CoaImportService::class)->import($this->csvFile($csv));

        $this->assertEquals(3, $result['imported']);
        $this->assertCount(0, $result['skipped']);

        $parent = FinAccount::where('code', 'Z100')->first();
        $child  = FinAccount::where('code', 'Z110')->first();
        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertFalse($parent->is_postable);
        $this->assertEquals('credit', FinAccount::where('code', 'Z400')->value('normal_balance'));
    }

    public function test_bad_rows_are_skipped_and_reported(): void
    {
        $csv = "code,name,type\n"
            . "Z500,Good Account,expense\n"
            . ",No Code,asset\n"
            . "Z600,,asset\n"
            . "Z700,Bad Type,frog\n";

        $result = app(CoaImportService::class)->import($this->csvFile($csv));

        $this->assertEquals(1, $result['imported']);
        $this->assertCount(3, $result['skipped']);
        $reasons = implode(' ', array_column($result['skipped'], 'reason'));
        $this->assertStringContainsString('code', strtolower($reasons));
        $this->assertStringContainsString('type', strtolower($reasons));
    }

    public function test_reimport_updates_existing(): void
    {
        $svc = app(CoaImportService::class);
        $svc->import($this->csvFile("code,name,type\nZ800,Original,asset\n"));
        $result = $svc->import($this->csvFile("code,name,type\nZ800,Renamed,asset\n"));

        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(1, $result['updated']);
        $this->assertEquals('Renamed', FinAccount::where('code', 'Z800')->value('name'));
    }
}
