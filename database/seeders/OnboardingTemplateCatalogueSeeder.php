<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shared onboarding template catalogue — owned by the platform, readable by every tenant.
 *
 * Rows are written with `company_id = null`, which the `global_catalogue_read` RLS policy makes
 * visible to everyone while the existing `tenant_isolation` policy keeps them read-only. A tenant
 * copies one into their own company to change it.
 *
 * Every template is BASE plus its own tasks. Real onboarding is nine-tenths identical whatever the
 * job — contract, tax number, payroll, equipment, policies, manager one-to-one — and writing that
 * out forty times would guarantee forty slightly different versions of it. The specific tasks are
 * what actually distinguishes a welder from a call-centre agent.
 *
 * South African by default because the platform is: SARS, UIF, BCEA, POPIA, the OHS Act, SETA,
 * PSIRA and FAIS all appear where the law actually requires them. A tenant elsewhere copies the
 * template and deletes what does not apply, which is less work than inventing the compliance steps.
 *
 * Idempotent on `catalogue_key`, so re-running upgrades the catalogue rather than duplicating it.
 */
class OnboardingTemplateCatalogueSeeder extends Seeder
{
    /** hr | it | manager | buddy — matches OnboardingTemplateController's validation. */
    private const BASE = [
        ['policy_acknowledgement', 'Sign and return the employment contract', -5, 'hr'],
        ['reading', 'Submit ID / passport, proof of address and banking details', -5, 'hr'],
        ['reading', 'Submit SARS tax number and complete the EMP101 details', -4, 'hr'],
        ['system_setup', 'Create payroll profile and register for UIF', -3, 'hr'],
        ['system_setup', 'Create email account and directory entry', -2, 'it'],
        ['system_setup', 'Prepare laptop, phone and building access', -2, 'it'],
        ['introduction', 'Send the welcome pack and first-day joining instructions', -1, 'hr'],
        ['meeting', 'Day one welcome and workplace tour', 0, 'hr'],
        ['introduction', 'Introduce the team and assign a buddy', 0, 'manager'],
        ['policy_acknowledgement', 'Acknowledge the employee handbook and code of conduct', 1, 'hr'],
        ['policy_acknowledgement', 'Acknowledge the POPIA and information-security policy', 1, 'hr'],
        ['training', 'Occupational health and safety induction (OHS Act)', 1, 'hr'],
        ['meeting', 'Role expectations and objectives with the manager', 2, 'manager'],
        ['meeting', 'End of first week check-in', 5, 'manager'],
        ['meeting', '30-day review', 30, 'manager'],
        ['meeting', '60-day review', 60, 'manager'],
        ['meeting', '90-day review and probation outcome', 90, 'manager'],
    ];

    /**
     * catalogue_key => [name, description, industry, department, job_title, employment_type, extra tasks]
     *
     * `null` on department / job title / employment type means "applies to anyone", which is what
     * the picker filters on.
     */
    private const CATALOGUE = [
        // ── Universal ────────────────────────────────────────────────────────
        'standard' => ['Standard Onboarding', 'The default first 90 days for any permanent hire.', null, null, null, 'permanent', []],
        'remote' => ['Remote / Hybrid Worker', 'For someone who will rarely be on site.', null, null, null, null, [
            ['system_setup', 'Courier equipment and confirm delivery before day one', -3, 'it'],
            ['training', 'Remote working, VPN and collaboration tools', 0, 'it'],
            ['policy_acknowledgement', 'Acknowledge the remote work and home-office safety policy', 1, 'hr'],
            ['meeting', 'Daily check-in for the first week', 1, 'manager'],
        ]],
        'executive' => ['Executive / C-Suite', 'Senior appointment with board and stakeholder exposure.', null, null, null, 'permanent', [
            ['meeting', 'Board or shareholder introduction', 1, 'hr'],
            ['reading', 'Delegation of authority, mandate and signing powers', 2, 'hr'],
            ['meeting', 'One-to-ones with each direct report', 3, 'manager'],
            ['reading', 'Strategy, budget and current risk register', 5, 'manager'],
            ['meeting', 'First 100-day plan presented to the board', 45, 'manager'],
        ]],
        'first_time_manager' => ['First-Time Manager', 'Someone managing people for the first time.', null, null, null, null, [
            ['training', 'Managing people: performance, leave approval and discipline', 3, 'hr'],
            ['training', 'Fair labour practice and the BCEA for managers', 5, 'hr'],
            ['meeting', 'Handover of the team from the outgoing manager', 1, 'manager'],
            ['training', 'Payroll and timesheet approval responsibilities', 7, 'hr'],
        ]],
        'internal_transfer' => ['Internal Transfer / Promotion', 'Already an employee — only what actually changes.', null, null, null, null, [
            ['system_setup', 'Adjust system access for the new role', 0, 'it'],
            ['policy_acknowledgement', 'Sign the amended contract or role change letter', -2, 'hr'],
            ['meeting', 'Handover from the previous role', 0, 'manager'],
            ['meeting', 'Introduce to the new team', 0, 'manager'],
        ]],
        'rehire' => ['Returning Employee', 'A former employee coming back.', null, null, null, null, [
            ['system_setup', 'Reactivate payroll, email and system access', -2, 'it'],
            ['reading', 'Confirm banking and tax details are still current', -3, 'hr'],
            ['training', 'What has changed since they left', 1, 'manager'],
        ]],
        'graduate' => ['Graduate Programme', 'Structured entry for a graduate intake.', null, null, null, 'permanent', [
            ['training', 'Graduate programme orientation week', 0, 'hr'],
            ['meeting', 'Assign a mentor for the programme', 1, 'hr'],
            ['training', 'Business fundamentals and how the company makes money', 5, 'hr'],
            ['meeting', 'Rotation planning', 20, 'manager'],
        ]],
        'intern' => ['Intern / Learner', 'Fixed-period placement, often SETA-funded.', null, null, null, 'contract', [
            ['reading', 'Register the learnership with the relevant SETA', -3, 'hr'],
            ['meeting', 'Assign a workplace supervisor and mentor', 0, 'hr'],
            ['reading', 'Explain the logbook and portfolio of evidence', 1, 'manager'],
            ['meeting', 'Mid-placement assessment', 45, 'manager'],
        ]],
        'apprentice' => ['Apprentice / Artisan Trainee', 'Trade apprenticeship with a training contract.', null, null, null, 'contract', [
            ['reading', 'Register the apprenticeship contract with the SETA', -5, 'hr'],
            ['training', 'Trade-specific safety and tool handling', 0, 'hr'],
            ['system_setup', 'Issue PPE and tools', -1, 'hr'],
            ['meeting', 'Assign a qualified artisan as mentor', 0, 'manager'],
        ]],
        'fixed_term' => ['Fixed-Term Contract', 'Defined end date under section 198B of the LRA.', null, null, null, 'contract', [
            ['reading', 'Record the justifiable reason for the fixed term', -5, 'hr'],
            ['reading', 'Diarise the contract end and review date', 0, 'hr'],
        ]],
        'part_time' => ['Part-Time', 'Reduced hours, pro-rata benefits.', null, null, null, 'part_time', [
            ['reading', 'Confirm working days, hours and pro-rata leave', -3, 'hr'],
        ]],
        'seasonal' => ['Seasonal / Peak', 'Short high-volume intake.', null, null, null, 'contract', [
            ['training', 'Condensed one-day induction', 0, 'hr'],
            ['system_setup', 'Issue uniform, locker and clock-in number', 0, 'hr'],
        ]],
        'contractor' => ['Contractor / Vendor', 'Not an employee — access and compliance only.', null, null, null, 'contract', [
            ['reading', 'Verify company registration, tax clearance and BBBEE certificate', -5, 'hr'],
            ['policy_acknowledgement', 'Sign the NDA and site rules', -3, 'hr'],
            ['reading', 'Confirm letter of good standing and public liability cover', -3, 'hr'],
            ['system_setup', 'Issue restricted site and system access', 0, 'it'],
        ]],
        'relocation' => ['International Hire / Relocation', 'Someone moving country for the role.', null, null, null, 'permanent', [
            ['reading', 'Confirm work visa or critical-skills permit', -30, 'hr'],
            ['reading', 'Relocation, flights and temporary accommodation', -20, 'hr'],
            ['reading', 'Open a local bank account and register for tax', -5, 'hr'],
            ['training', 'Local orientation and cultural induction', 2, 'hr'],
        ]],

        // ── By function ──────────────────────────────────────────────────────
        'engineering' => ['Software Engineering', 'Developers, QA and platform engineers.', 'IT', 'Engineering', null, null, [
            ['system_setup', 'Source control, CI and environment access', 0, 'it'],
            ['reading', 'Architecture overview and coding standards', 1, 'buddy'],
            ['training', 'Local development environment set up and running', 1, 'buddy'],
            ['meeting', 'Pair with the buddy on a starter ticket', 3, 'buddy'],
            ['reading', 'Secure development and code review practice', 5, 'manager'],
            ['meeting', 'First change merged to production', 14, 'manager'],
        ]],
        'sales' => ['Sales / Business Development', 'Quota-carrying commercial roles.', null, 'Sales', null, null, [
            ['system_setup', 'CRM access and pipeline set up', 0, 'it'],
            ['training', 'Product and pricing', 2, 'manager'],
            ['reading', 'Commission plan and targets signed', 3, 'hr'],
            ['meeting', 'Shadow a senior on live calls', 5, 'buddy'],
            ['training', 'Objection handling and demo certification', 15, 'manager'],
            ['meeting', 'First qualified opportunity created', 30, 'manager'],
        ]],
        'customer_support' => ['Customer Support', 'Service desk and support agents.', null, 'Support', null, null, [
            ['system_setup', 'Helpdesk, phone queue and knowledge base access', 0, 'it'],
            ['training', 'Product training and common issues', 2, 'manager'],
            ['training', 'Tone of voice and escalation path', 4, 'manager'],
            ['meeting', 'Listen in on live tickets with a buddy', 5, 'buddy'],
            ['meeting', 'First solo shift with review', 14, 'manager'],
        ]],
        'finance' => ['Finance & Accounting', 'Finance, payables, receivables and reporting.', null, 'Finance', null, null, [
            ['system_setup', 'Ledger, banking and approval-limit access', 0, 'it'],
            ['reading', 'Delegation of authority and segregation of duties', 1, 'hr'],
            ['training', 'Month-end close calendar and responsibilities', 5, 'manager'],
            ['policy_acknowledgement', 'Acknowledge the fraud, anti-bribery and gifts policy', 2, 'hr'],
        ]],
        'hr' => ['Human Resources', 'HR practitioners handling personal data.', null, 'Human Resources', null, null, [
            ['policy_acknowledgement', 'Acknowledge confidentiality of employee records', 0, 'hr'],
            ['training', 'BCEA, LRA and Employment Equity essentials', 3, 'hr'],
            ['training', 'POPIA obligations when processing employee data', 2, 'hr'],
            ['system_setup', 'HR system access with an audit trail', 0, 'it'],
        ]],
        'marketing' => ['Marketing', 'Brand, content and campaigns.', null, 'Marketing', null, null, [
            ['system_setup', 'Analytics, social and design tool access', 0, 'it'],
            ['reading', 'Brand guidelines and tone of voice', 1, 'manager'],
            ['training', 'Campaign calendar and approval process', 4, 'manager'],
            ['policy_acknowledgement', 'Acknowledge the social media and public comment policy', 2, 'hr'],
        ]],
        'operations' => ['Operations', 'Planning, scheduling and delivery.', null, 'Operations', null, null, [
            ['training', 'Operating procedures and daily rhythm', 2, 'manager'],
            ['system_setup', 'Scheduling and reporting system access', 0, 'it'],
            ['meeting', 'Shadow a full operating shift', 3, 'buddy'],
        ]],
        'legal' => ['Legal & Compliance', 'Legal, risk and compliance roles.', null, 'Legal', null, null, [
            ['reading', 'Matter management, privilege and retention rules', 1, 'manager'],
            ['policy_acknowledgement', 'Acknowledge conflict-of-interest declarations', 0, 'hr'],
            ['reading', 'Regulatory register and reporting obligations', 5, 'manager'],
        ]],
        'it_infrastructure' => ['IT & Infrastructure', 'Sysadmins and infrastructure engineers with elevated access.', 'IT', 'IT', null, null, [
            ['policy_acknowledgement', 'Sign the privileged access and administrator undertaking', 0, 'it'],
            ['system_setup', 'Issue admin credentials with MFA enforced', 1, 'it'],
            ['reading', 'Change control, backup and disaster recovery procedures', 3, 'manager'],
            ['training', 'Incident response and on-call rotation', 10, 'manager'],
        ]],
        'administration' => ['Administration / Office Support', 'Reception, office and administrative support.', null, 'Administration', null, null, [
            ['training', 'Switchboard, visitor and courier procedures', 1, 'buddy'],
            ['system_setup', 'Office systems and petty cash access', 0, 'it'],
        ]],

        // ── By industry ──────────────────────────────────────────────────────
        'construction' => ['Construction Site', 'Site-based building and civils work.', 'Construction', null, null, null, [
            ['training', 'Site-specific safety induction before entering site', 0, 'hr'],
            ['system_setup', 'Issue PPE, hard hat and site access card', 0, 'hr'],
            ['reading', 'Medical fitness certificate on file', -3, 'hr'],
            ['policy_acknowledgement', 'Acknowledge the Construction Regulations and site rules', 0, 'hr'],
            ['training', 'Working at heights and fall protection', 2, 'hr'],
            ['meeting', 'Introduce to the site agent and safety officer', 0, 'manager'],
        ]],
        'manufacturing' => ['Manufacturing / Plant', 'Production, plant and machine operation.', 'Manufacturing', null, null, null, [
            ['training', 'Machine-specific operation and lock-out / tag-out', 1, 'hr'],
            ['system_setup', 'Issue PPE and clock-in number', 0, 'hr'],
            ['training', 'Quality standards and defect reporting', 3, 'manager'],
            ['reading', 'Shift roster, overtime and BCEA hours', 1, 'hr'],
        ]],
        'mining' => ['Mining', 'Underground and surface mining operations.', 'Mining', null, null, null, [
            ['reading', 'Medical surveillance and certificate of fitness', -10, 'hr'],
            ['training', 'Mine Health and Safety Act induction', 0, 'hr'],
            ['training', 'Underground orientation and emergency escape', 1, 'hr'],
            ['system_setup', 'Issue PPE, lamp and tracking tag', 0, 'hr'],
            ['policy_acknowledgement', 'Acknowledge the substance abuse and fitness-for-duty policy', 0, 'hr'],
        ]],
        'healthcare' => ['Healthcare / Clinical', 'Clinical and patient-facing staff.', 'Healthcare', null, null, null, [
            ['reading', 'Verify HPCSA / SANC registration and indemnity cover', -10, 'hr'],
            ['training', 'Infection prevention and control', 0, 'hr'],
            ['policy_acknowledgement', 'Acknowledge patient confidentiality and POPIA in a clinical setting', 0, 'hr'],
            ['training', 'Clinical systems and record keeping', 2, 'manager'],
            ['reading', 'Immunisation status and occupational health screening', -5, 'hr'],
        ]],
        'education' => ['Education / Teaching', 'Teaching and academic staff.', 'Education', null, null, null, [
            ['reading', 'SACE registration and police clearance', -15, 'hr'],
            ['policy_acknowledgement', 'Acknowledge the child protection and safeguarding policy', 0, 'hr'],
            ['training', 'Curriculum, assessment and reporting', 2, 'manager'],
            ['meeting', 'Classroom handover and timetable', 1, 'manager'],
        ]],
        'retail' => ['Retail Store', 'Store, till and floor staff.', 'Retail', null, null, null, [
            ['training', 'Point of sale, cash handling and refunds', 1, 'buddy'],
            ['system_setup', 'Issue uniform, name badge and till number', 0, 'hr'],
            ['training', 'Customer service and shrinkage awareness', 2, 'manager'],
            ['reading', 'Shift roster and Sunday / public holiday pay rules', 1, 'hr'],
        ]],
        'hospitality' => ['Hospitality', 'Hotel, restaurant and guest-facing roles.', 'Hospitality', null, null, null, [
            ['training', 'Food safety and hygiene certification', 1, 'hr'],
            ['system_setup', 'Issue uniform and locker', 0, 'hr'],
            ['training', 'Guest service standards and complaint handling', 2, 'manager'],
            ['reading', 'Tips, gratuities and cash-up procedure', 3, 'manager'],
        ]],
        'logistics' => ['Logistics / Warehouse / Driver', 'Drivers, warehouse and distribution staff.', 'Logistics', null, null, null, [
            ['reading', 'Verify driver licence, PrDP and driving record', -10, 'hr'],
            ['training', 'Forklift and materials handling certification', 1, 'hr'],
            ['training', 'Load securing, route planning and vehicle inspection', 2, 'manager'],
            ['policy_acknowledgement', 'Acknowledge the vehicle use, fatigue and alcohol policy', 0, 'hr'],
        ]],
        'agriculture' => ['Agriculture / Farm', 'Farm and agri-processing work.', 'Agriculture', null, null, null, [
            ['training', 'Chemical handling, spraying and re-entry intervals', 2, 'hr'],
            ['system_setup', 'Issue PPE and accommodation if applicable', 0, 'hr'],
            ['reading', 'Sectoral determination wages and working hours', 1, 'hr'],
            ['training', 'Machinery and tractor safety', 1, 'hr'],
        ]],
        'financial_services' => ['Financial Services', 'FSP roles under FAIS and FICA.', 'Financial Services', null, null, null, [
            ['reading', 'Fit and proper assessment and FSCA representative registration', -10, 'hr'],
            ['training', 'FICA, anti-money-laundering and reporting obligations', 1, 'hr'],
            ['policy_acknowledgement', 'Acknowledge the conflict of interest and TCF policy', 0, 'hr'],
            ['training', 'Product and advice competency', 5, 'manager'],
            ['meeting', 'Supervision plan agreed while under supervision', 3, 'manager'],
        ]],
        'public_sector' => ['Public Sector', 'Government and municipal appointments.', 'Government', null, null, null, [
            ['reading', 'Security vetting and qualification verification', -20, 'hr'],
            ['policy_acknowledgement', 'Declare financial interests and outside remunerative work', 1, 'hr'],
            ['training', 'PFMA / MFMA and supply chain regulations', 3, 'hr'],
            ['policy_acknowledgement', 'Acknowledge the public service code of conduct', 0, 'hr'],
        ]],
        'ngo' => ['NGO / Non-Profit', 'Donor-funded and community roles.', 'Non-Profit', null, null, null, [
            ['training', 'Donor reporting and grant compliance', 3, 'manager'],
            ['policy_acknowledgement', 'Acknowledge the safeguarding and beneficiary protection policy', 0, 'hr'],
            ['reading', 'Which grant funds this post and until when', 1, 'hr'],
        ]],
        'security' => ['Security Services', 'Guarding and security officers under PSIRA.', 'Security', null, null, null, [
            ['reading', 'Verify PSIRA registration and grade', -10, 'hr'],
            ['training', 'Use of force, arrest procedure and incident reporting', 1, 'hr'],
            ['system_setup', 'Issue uniform, radio and equipment', 0, 'hr'],
            ['reading', 'Firearm competency where the post requires it', -5, 'hr'],
        ]],
        'call_centre' => ['Call Centre / BPO', 'High-volume contact centre roles.', 'BPO', null, null, null, [
            ['system_setup', 'Dialler, headset and CRM access', 0, 'it'],
            ['training', 'Script, call flow and quality criteria', 2, 'manager'],
            ['training', 'Call recording, consent and POPIA on calls', 1, 'hr'],
            ['meeting', 'Side-by-side listening with a buddy', 4, 'buddy'],
            ['meeting', 'First quality assessment', 21, 'manager'],
        ]],
        'professional_services' => ['Professional Services / Consulting', 'Client-facing billable roles.', 'Professional Services', null, null, null, [
            ['training', 'Timesheets, billing rates and utilisation', 2, 'hr'],
            ['policy_acknowledgement', 'Acknowledge client confidentiality and conflict checks', 0, 'hr'],
            ['meeting', 'Shadow a client engagement', 5, 'buddy'],
            ['reading', 'Methodology and deliverable standards', 3, 'manager'],
        ]],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::CATALOGUE as $key => [$name, $description, $industry, $department, $jobTitle, $employmentType, $extra]) {
            // Upsert by catalogue_key so a re-run improves the catalogue instead of duplicating it.
            $templateId = DB::table('onboarding_templates')->where('catalogue_key', $key)->value('id');

            $attributes = [
                'name' => $name,
                'description' => $description,
                'industry' => $industry,
                'department' => $department,
                'job_title' => $jobTitle,
                'employment_type' => $employmentType,
                'is_active' => true,
                'company_id' => null,   // the catalogue belongs to the platform, not to a tenant
                'created_by' => null,
                'updated_at' => $now,
            ];

            if ($templateId) {
                DB::table('onboarding_templates')->where('id', $templateId)->update($attributes);
                DB::table('onboarding_template_tasks')->where('onboarding_template_id', $templateId)->delete();
            } else {
                $templateId = DB::table('onboarding_templates')->insertGetId($attributes + [
                    // The table carries a NOT NULL public id that the model normally fills on save;
                    // this seeder writes through the query builder, so it supplies its own.
                    'uuid' => (string) Str::uuid(),
                    'catalogue_key' => $key,
                    'created_at' => $now,
                ]);
            }

            $tasks = array_merge(self::BASE, $extra);

            // Sorted by day so a plan generated from this reads as a timeline rather than as the
            // order somebody happened to type it in.
            usort($tasks, fn ($a, $b) => $a[2] <=> $b[2]);

            $rows = [];

            foreach (array_values($tasks) as $index => [$type, $title, $dayOffset, $owner]) {
                $rows[] = [
                    'onboarding_template_id' => $templateId,
                    'type' => $type,
                    'title' => $title,
                    'day_offset' => $dayOffset,
                    'owner_type' => $owner,
                    'sort_order' => $index,
                    'company_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('onboarding_template_tasks')->insert($rows);
        }

        $this->command?->info(sprintf(
            'Onboarding catalogue: %d global templates, %d tasks.',
            count(self::CATALOGUE),
            DB::table('onboarding_template_tasks')->whereNull('company_id')->count()
        ));
    }
}
