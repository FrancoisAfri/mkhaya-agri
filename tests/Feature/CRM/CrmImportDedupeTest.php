<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Services\CrmDuplicateService;
use App\Modules\CRM\Services\CrmImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmImportDedupeTest extends TestCase
{
    use DatabaseTransactions;

    private function writeCsv(string $content): string
    {
        $path = sys_get_temp_dir() . '/crm_test_' . Str::random(8) . '.csv';
        file_put_contents($path, $content);
        return $path;
    }

    public function test_csv_import_creates_leads_with_mapping(): void
    {
        $csv = "First,Last,Company,Email\nJane,Doe,Acme,jane@acme.test\nJohn,Roe,Globex,john@globex.test\n";
        $path = $this->writeCsv($csv);

        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'Company' => 'company', 'Email' => 'email'];
        $import = app(CrmImportService::class)->import($path, 'lead', $mapping, 'skip', 'leads.csv');

        $this->assertSame(2, $import->created_count);
        $this->assertDatabaseHas('crm_leads', ['email' => 'jane@acme.test', 'first_name' => 'Jane', 'company' => 'Acme']);
        @unlink($path);
    }

    public function test_import_skips_duplicate_email(): void
    {
        CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Existing', 'email' => 'dupe@x.test', 'status' => 'new']);

        $csv = "First,Email\nNew,dupe@x.test\n";
        $path = $this->writeCsv($csv);

        $import = app(CrmImportService::class)->import($path, 'lead', ['First' => 'first_name', 'Email' => 'email'], 'skip', 'x.csv');

        $this->assertSame(0, $import->created_count);
        $this->assertSame(1, $import->duplicate_count);
        $this->assertSame(1, $import->skipped_count);
        @unlink($path);
    }

    public function test_preview_suggests_mapping(): void
    {
        $csv = "first_name,Email Address,company\nA,a@a.test,Acme\n";
        $path = $this->writeCsv($csv);

        $preview = app(CrmImportService::class)->preview($path, 'lead');

        $this->assertSame(['first_name', 'Email Address', 'company'], $preview['headers']);
        $this->assertSame('first_name', $preview['suggested']['first_name']);
        $this->assertSame('company', $preview['suggested']['company']);
        @unlink($path);
    }

    public function test_duplicate_clusters_and_merge_reparents_activities(): void
    {
        $primary = CrmContact::create(['uuid' => Str::uuid(), 'first_name' => 'Sam', 'email' => 'sam@x.test']);
        $dupe    = CrmContact::create(['uuid' => Str::uuid(), 'first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 'sam@x.test']);

        CrmActivity::create([
            'uuid' => Str::uuid(), 'type' => 'note', 'subject' => 'hi', 'related_type' => 'contact',
            'related_id' => $dupe->id, 'status' => 'done',
        ]);

        $svc = app(CrmDuplicateService::class);

        $clusters = $svc->clusters('contact');
        $cluster  = collect($clusters)->firstWhere('key', 'sam@x.test');
        $this->assertNotNull($cluster);
        $this->assertCount(2, $cluster['records']);

        $svc->merge('contact', $primary->id, $dupe->id);

        // Activity re-parented to primary, duplicate soft-deleted, blank field filled.
        $this->assertDatabaseHas('crm_activities', ['subject' => 'hi', 'related_id' => $primary->id]);
        $this->assertNotNull($dupe->fresh()->deleted_at);
        $this->assertSame('Lee', $primary->fresh()->last_name);
    }

    /**
     * Colleagues are not duplicates of each other.
     *
     * `company` used to sit in the lead dedup keys, and those keys are checked with OR — so the
     * second person at an employer already on file was discarded as a duplicate of a colleague. One
     * real import kept 126 of 716 rows: exactly one lead per company, and the other 590 were thrown
     * away with nothing recorded to say which.
     */
    public function test_several_people_at_the_same_company_all_import(): void
    {
        $csv = "First,Last,Company,Email\n"
            . "Thabo,Mokoena,Acme,thabo@acme.test\n"
            . "Zanele,Dube,Acme,zanele@acme.test\n"
            . "Sipho,Ndlovu,Acme,sipho@acme.test\n";
        $path = $this->writeCsv($csv);

        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'Company' => 'company', 'Email' => 'email'];
        $import = app(CrmImportService::class)->import($path, 'lead', $mapping, 'skip', 'leads.csv');

        $this->assertSame(3, $import->created_count, 'An employer with three staff is not two duplicates.');
        $this->assertSame(0, $import->duplicate_count);
        @unlink($path);
    }

    /**
     * A skipped row says which row it was, who was on it, and why.
     *
     * Counting them was never enough: "590 skipped" tells somebody their import went wrong without
     * telling them what to do, so the only way to find out was to compare the spreadsheet against
     * the CRM by hand.
     */
    public function test_every_skipped_row_is_reported_with_its_reason(): void
    {
        CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Existing', 'email' => 'dupe@x.test', 'status' => 'new']);

        $csv = "First,Last,Company,Email\n"
            . "Fresh,Person,Acme,fresh@acme.test\n"
            . "Repeat,Person,Acme,dupe@x.test\n";
        $path = $this->writeCsv($csv);

        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'Company' => 'company', 'Email' => 'email'];
        $import = app(CrmImportService::class)->import($path, 'lead', $mapping, 'skip', 'leads.csv');

        $this->assertSame(1, $import->created_count);
        $this->assertCount(1, $import->errors);

        $issue = $import->errors[0];
        // Line 3 as the client sees it in their spreadsheet — the header is line 1.
        $this->assertSame(3, $issue['row']);
        $this->assertSame('Repeat Person', $issue['name']);
        $this->assertSame('dupe@x.test', $issue['email']);
        $this->assertStringContainsString('matched an existing lead on email', $issue['reason']);
        @unlink($path);
    }

    public function test_a_row_missing_a_required_field_names_the_field(): void
    {
        \App\Modules\CRM\Models\CrmSetting::updateOrCreate(
            ['key' => 'import_required_fields'],
            ['value' => json_encode(['lead' => ['email']])],
        );

        $csv = "First,Last,Company,Email\nNoEmail,Person,Acme,\n";
        $path = $this->writeCsv($csv);

        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'Company' => 'company', 'Email' => 'email'];
        $import = app(CrmImportService::class)->import($path, 'lead', $mapping, 'skip', 'leads.csv');

        $this->assertSame(0, $import->created_count);
        $this->assertStringContainsString('Missing required: email', $import->errors[0]['reason']);
        $this->assertSame('NoEmail Person', $import->errors[0]['name']);
        @unlink($path);
    }

    /**
     * The dry run promises exactly what the import delivers.
     *
     * The whole value of a preview is that the two agree, so it is the real import rolled back
     * rather than a second implementation of the same rules — a count derived separately drifts the
     * first time either side changes, and a preview that promises 590 and delivers 126 is worse
     * than no preview.
     */
    public function test_a_dry_run_predicts_exactly_what_the_import_does(): void
    {
        CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Existing', 'email' => 'dupe@x.test', 'status' => 'new']);

        $csv = "First,Last,Company,Email\n"
            . "Thabo,Mokoena,Acme,thabo@acme.test\n"
            . "Zanele,Dube,Acme,zanele@acme.test\n"
            . "Repeat,Person,Acme,dupe@x.test\n";
        $path = $this->writeCsv($csv);
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'Company' => 'company', 'Email' => 'email'];

        $dry = app(CrmImportService::class)->dryRun($path, 'lead', $mapping, 'skip');
        $real = app(CrmImportService::class)->import($path, 'lead', $mapping, 'skip', 'leads.csv');

        $this->assertSame($real->total_rows, $dry['total']);
        $this->assertSame($real->created_count, $dry['create']);
        $this->assertSame($real->skipped_count, $dry['skip']);
        $this->assertSame($real->duplicate_count, $dry['duplicates']);
        $this->assertCount(count($real->errors), $dry['rows']);
        @unlink($path);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $before = CrmLead::count();
        $imports = \App\Modules\CRM\Models\CrmImport::count();

        $csv = "First,Last,Company,Email\nThabo,Mokoena,Acme,thabo@acme.test\n";
        $path = $this->writeCsv($csv);
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'Company' => 'company', 'Email' => 'email'];

        $dry = app(CrmImportService::class)->dryRun($path, 'lead', $mapping, 'skip');

        $this->assertSame(1, $dry['create']);
        // A dry run that committed because something threw halfway is the one outcome worse than
        // not having a dry run at all.
        $this->assertSame($before, CrmLead::count());
        $this->assertSame($imports, \App\Modules\CRM\Models\CrmImport::count());
        @unlink($path);
    }

    public function test_a_dry_run_names_the_rows_that_will_not_come_in(): void
    {
        CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Existing', 'email' => 'dupe@x.test', 'status' => 'new']);

        $csv = "First,Last,Company,Email\nFresh,Person,Acme,fresh@acme.test\nRepeat,Person,Acme,dupe@x.test\n";
        $path = $this->writeCsv($csv);
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'Company' => 'company', 'Email' => 'email'];

        $dry = app(CrmImportService::class)->dryRun($path, 'lead', $mapping, 'skip');

        $this->assertSame(1, $dry['create']);
        $this->assertSame(1, $dry['skip']);
        $this->assertSame(3, $dry['rows'][0]['row']);
        $this->assertSame('Repeat Person', $dry['rows'][0]['name']);
        @unlink($path);
    }
}
