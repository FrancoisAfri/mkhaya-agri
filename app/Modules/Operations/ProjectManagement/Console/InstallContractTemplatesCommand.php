<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Models\ContractTemplate;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Install Project Management's starter contract templates for a tenant.
 *
 * A library that opens empty teaches nobody what belongs in it. These are the agreements a project
 * practice actually raises — a subcontract, a consultant appointment, plant hire, a site NDA — as
 * starting points drafted to South African law.
 *
 * They are per tenant, not global rows. Contract Management's shipped library uses a NULL
 * company_id shared by everybody, which works there because that module's templates are read-only
 * until copied. Here they are meant to be edited on arrival: a practice's subcontract terms are
 * its own, and copy-on-write against a shared row is a trap nobody remembers is set.
 *
 * Re-running is safe: a template already installed under the same name is left exactly as it is,
 * including any edits made to it.
 */
class InstallContractTemplatesCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:contract-templates:install
        {--company= : Install for one company id instead of every entitled tenant}';

    protected $description = "Install Project Management's starter contract templates";

    public function handle(TenantContext $tenants): int
    {
        // Fanned out through the shared trait rather than a hand-rolled loop: it applies the
        // company to the Postgres session as well as the app scope, so RLS is live inside the
        // callback, and one tenant throwing does not silently end the pass for the rest.
        $this->eachTenant($tenants, function (int $companyId) {
            $added = 0;

            foreach ($this->templates() as $template) {
                // Matched by name rather than uuid: the question is "does this practice already
                // have a subcontract template", and a fresh uuid every deploy would add a
                // duplicate every time. Includes trashed — a template somebody deleted on purpose
                // must not come back on the next install.
                if (ContractTemplate::withTrashed()->where('name', $template['name'])->exists()) {
                    continue;
                }

                ContractTemplate::create($template + [
                    'uuid'      => (string) Str::uuid(),
                    'source'    => 'seeded',
                    'is_active' => true,
                ]);

                $added++;
            }

            $this->line(sprintf('  company %d: %d added', $companyId, $added));
        });

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * The starter set.
     *
     * Every one carries the same warning at the top, because that is the honest position: this is
     * a drafting aid, the blanks are real blanks, and a contract of any size should be read by
     * somebody qualified before it is signed.
     *
     * @return list<array<string, string>>
     */
    private function templates(): array
    {
        return [
            [
                'name'     => 'Subcontractor agreement',
                'category' => 'Construction',
                'summary'  => 'Engaging a subcontractor on a defined package of work.',
                'body'     => $this->subcontract(),
            ],
            [
                'name'     => 'Professional services appointment',
                'category' => 'Professional',
                'summary'  => 'Appointing a consultant — engineer, architect, quantity surveyor.',
                'body'     => $this->professionalServices(),
            ],
            [
                'name'     => 'Plant and equipment hire',
                'category' => 'Construction',
                'summary'  => 'Hiring plant, with or without an operator.',
                'body'     => $this->plantHire(),
            ],
            [
                'name'     => 'Site confidentiality undertaking',
                'category' => 'General',
                'summary'  => 'A short NDA for anybody given access to a site or its documents.',
                'body'     => $this->nda(),
            ],
        ];
    }

    private function preamble(): string
    {
        return '<blockquote><p><strong>Before use:</strong> this is a starting point drafted to '
            . 'South African law, not legal advice. Complete every blank marked in [SQUARE BRACKETS], '
            . 'delete the alternatives that do not apply, and have the document reviewed by a suitably '
            . 'qualified legal practitioner before signature.</p></blockquote>';
    }

    private function parties(): string
    {
        return '<h2>Parties</h2>'
            . '<p>Entered into between <strong>[FULL REGISTERED NAME]</strong> (registration number '
            . '[NUMBER]), a company duly incorporated in terms of the laws of the Republic of South '
            . 'Africa, with its principal place of business at [ADDRESS] (<strong>"the Employer"</strong>)</p>'
            . '<p>and</p>'
            . '<p><strong>{{contractor_name}}</strong> of [ADDRESS], contactable at '
            . '{{contractor_email}} (<strong>"the Contractor"</strong>).</p>';
    }

    private function subcontract(): string
    {
        return $this->preamble()
            . '<h1>Subcontract Agreement</h1>'
            . $this->parties()
            . '<h2>1. The works</h2>'
            . '<p>The Contractor shall execute and complete the following works on the project '
            . '<strong>{{project_name}}</strong> ({{project_code}}): [DESCRIBE THE PACKAGE OF WORK].</p>'
            . '<h2>2. Period</h2>'
            . '<p>The works shall commence on {{start_date}} and reach practical completion by '
            . '{{end_date}}, subject to any extension of time granted in writing.</p>'
            . '<h2>3. Contract sum</h2>'
            . '<p>The Employer shall pay the Contractor <strong>{{contract_value}}</strong>, exclusive '
            . 'of VAT, measured and paid in accordance with clause 4.</p>'
            . '<h2>4. Payment</h2>'
            . '<p>The Contractor shall submit a payment claim by the [DAY] of each month. The Employer '
            . 'shall assess it within [NUMBER] days and pay the certified amount within [NUMBER] days '
            . 'of certification. Retention of [PERCENTAGE]% shall be held and released on [MILESTONE].</p>'
            . '<h2>5. Health and safety</h2>'
            . '<p>The Contractor is a mandatary as contemplated in section 37(2) of the Occupational '
            . 'Health and Safety Act 85 of 1993 and shall comply with the Construction Regulations, the '
            . "Employer's health and safety specification, and all lawful site instructions.</p>"
            . '<h2>6. Insurance</h2>'
            . '<p>The Contractor shall maintain public liability cover of not less than [AMOUNT] and '
            . 'shall be registered with the Compensation Fund, and shall produce a letter of good '
            . 'standing on demand.</p>'
            . '<h2>7. Defects</h2>'
            . '<p>The Contractor shall make good any defect notified within [NUMBER] months of practical '
            . 'completion at its own cost.</p>'
            . '<h2>8. Termination</h2>'
            . '<p>Either party may terminate on [NUMBER] days written notice for a material breach that '
            . 'remains unremedied after [NUMBER] days written notice to remedy.</p>'
            . '<h2>9. Dispute resolution</h2>'
            . '<p>Any dispute shall first be referred to the parties&rsquo; senior representatives, '
            . 'failing which to mediation, failing which to arbitration under the rules of the '
            . 'Arbitration Foundation of Southern Africa.</p>'
            . '<h2>10. Whole agreement</h2>'
            . '<p>This document constitutes the whole agreement. No variation is of any force unless '
            . 'reduced to writing and signed by both parties.</p>'
            . $this->signatureBlock();
    }

    private function professionalServices(): string
    {
        return $this->preamble()
            . '<h1>Appointment of Professional Consultant</h1>'
            . $this->parties()
            . '<h2>1. Services</h2>'
            . '<p>The Contractor is appointed as [DISCIPLINE] on <strong>{{project_name}}</strong> '
            . '({{project_code}}) and shall provide the services described in [SCOPE / STAGE].</p>'
            . '<h2>2. Standard of care</h2>'
            . '<p>The Contractor shall exercise the reasonable skill, care and diligence of a member of '
            . 'its profession, and shall remain registered with [COUNCIL] for the duration.</p>'
            . '<h2>3. Fee</h2>'
            . '<p>The fee is <strong>{{contract_value}}</strong>, exclusive of VAT, [LUMP SUM / '
            . 'TARIFF-BASED / TIME-BASED AT [RATE] PER HOUR], invoiced [MONTHLY / ON STAGE COMPLETION].</p>'
            . '<h2>4. Period</h2>'
            . '<p>The appointment runs from {{start_date}} to {{end_date}} or until the services are '
            . 'complete, whichever is later.</p>'
            . '<h2>5. Professional indemnity</h2>'
            . '<p>The Contractor shall maintain professional indemnity cover of not less than [AMOUNT] '
            . 'for the duration and for [NUMBER] years after completion.</p>'
            . '<h2>6. Intellectual property</h2>'
            . '<p>Copyright in documents prepared under this appointment vests in [PARTY]. The Employer '
            . 'has an irrevocable licence to use them for the project.</p>'
            . '<h2>7. Confidentiality and data</h2>'
            . '<p>Each party shall keep the other&rsquo;s confidential information confidential and shall '
            . 'process personal information in accordance with the Protection of Personal Information '
            . 'Act 4 of 2013.</p>'
            . $this->signatureBlock();
    }

    private function plantHire(): string
    {
        return $this->preamble()
            . '<h1>Plant and Equipment Hire Agreement</h1>'
            . $this->parties()
            . '<h2>1. The plant</h2>'
            . '<p>The Contractor hires to the Employer the following plant for use on '
            . '<strong>{{project_name}}</strong>: [DESCRIBE PLANT, MAKE, MODEL, REGISTRATION].</p>'
            . '<h2>2. Period and rate</h2>'
            . '<p>Hire runs from {{start_date}} to {{end_date}} at [RATE] per [HOUR / DAY / MONTH], to a '
            . 'total of <strong>{{contract_value}}</strong>. [WET HIRE — OPERATOR INCLUDED / DRY HIRE — '
            . 'OPERATOR EXCLUDED].</p>'
            . '<h2>3. Condition</h2>'
            . '<p>The plant is delivered in good working order, with a valid load test or certificate of '
            . 'fitness where applicable. Both parties shall sign an on-hire and off-hire condition report.</p>'
            . '<h2>4. Operator</h2>'
            . '<p>Where an operator is supplied, the operator remains the Contractor&rsquo;s employee and '
            . 'shall hold a valid licence and competency certificate for the plant operated.</p>'
            . '<h2>5. Maintenance and breakdown</h2>'
            . '<p>The Contractor shall service the plant at its own cost. Hire charges abate for any '
            . 'stoppage exceeding [NUMBER] hours that is not caused by the Employer.</p>'
            . '<h2>6. Risk and insurance</h2>'
            . '<p>Risk in the plant passes to the Employer on delivery and reverts on collection. The '
            . '[PARTY] shall insure the plant for its full replacement value.</p>'
            . $this->signatureBlock();
    }

    private function nda(): string
    {
        return $this->preamble()
            . '<h1>Confidentiality Undertaking</h1>'
            . $this->parties()
            . '<h2>1. Purpose</h2>'
            . '<p>The Contractor is given access to the site, documents and information relating to '
            . '<strong>{{project_name}}</strong> ({{project_code}}) for the purpose of [PURPOSE].</p>'
            . '<h2>2. Undertaking</h2>'
            . '<p>The Contractor shall keep all such information confidential, shall use it only for the '
            . 'stated purpose, and shall not disclose it to any third party without prior written consent.</p>'
            . '<h2>3. Exclusions</h2>'
            . '<p>This undertaking does not apply to information that is or becomes public through no '
            . 'breach by the Contractor, was lawfully known before disclosure, or must be disclosed by law.</p>'
            . '<h2>4. Personal information</h2>'
            . '<p>Where the information includes personal information, the Contractor shall process it '
            . 'strictly in accordance with the Protection of Personal Information Act 4 of 2013.</p>'
            . '<h2>5. Duration</h2>'
            . '<p>This undertaking endures for [NUMBER] years from {{start_date}}, and indefinitely in '
            . 'respect of trade secrets.</p>'
            . $this->signatureBlock();
    }

    private function signatureBlock(): string
    {
        return '<h2>Signature</h2>'
            . '<p>Signed at [PLACE] on [DATE].</p>'
            . '<table><tbody>'
            . '<tr><td><strong>For the Employer</strong></td><td><strong>For the Contractor</strong></td></tr>'
            . '<tr><td>Name: [NAME]</td><td>Name: {{contractor_name}}</td></tr>'
            . '<tr><td>Capacity: [CAPACITY]</td><td>Capacity: [CAPACITY]</td></tr>'
            . '<tr><td>Signature:</td><td>Signature:</td></tr>'
            . '</tbody></table>';
    }
}
