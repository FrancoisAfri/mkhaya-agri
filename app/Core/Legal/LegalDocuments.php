<?php

namespace App\Core\Legal;

use App\Modules\Admin\Setup\Models\CompanyIdentity;
use Illuminate\Support\Carbon;

/**
 * The published privacy policy and the data deletion notice.
 *
 * These exist because Facebook and LinkedIn will not let an app out of development mode without a
 * reachable privacy policy URL, and Facebook additionally requires a data deletion URL. Their
 * reviewers open both pages and read them, so the wording describes what this system actually does
 * with a connected account rather than the generic text a policy generator produces — the specific
 * claim a reviewer checks is whether the permissions being asked for match the stated purpose.
 *
 * ── Held here rather than in a template ─────────────────────────────────────
 *
 * The document is structured data, not markup, so the same wording can be rendered as a page, sent
 * as a PDF, or printed, and so a change to it is a readable diff rather than a change to a layout.
 *
 * ── Not translated ──────────────────────────────────────────────────────────
 *
 * Every other screen in this system is carried in twelve languages. This document is not, and that
 * is deliberate: a machine translation of "you may object to processing" that lands a shade wrong is
 * a defect with legal weight rather than a cosmetic one, and the page says plainly which language
 * governs. The chrome around it — the heading, the dates, the print control — is translated.
 */
class LegalDocuments
{
    /**
     * Who is answerable for the data. Anything not configured is omitted rather than guessed at.
     *
     * @return array<string, string|null>
     */
    public function operator(): array
    {
        $identity = CompanyIdentity::systemSettings();
        $support = $identity['support_email'] ?: config('mail.from.address');

        return [
            'name'                => $identity['full_company_name'] ?: $identity['company_name'],
            'short_name'          => $identity['company_name'],
            'logo'                => $identity['company_logo_url'] ?: null,
            'registration_number' => config('legal.registration_number'),
            'address'             => config('legal.address'),
            'phone'               => config('legal.phone'),
            'privacy_email'       => config('legal.privacy_email') ?: $support,
            'support_email'       => $support,
            'information_officer' => config('legal.information_officer'),
            // Falls back to the privacy address: a named officer with no way to reach them is worse
            // than an unnamed office that answers.
            'officer_email'       => config('legal.information_officer_email')
                ?: (config('legal.privacy_email') ?: $support),
            'site'                => rtrim((string) config('app.url'), '/'),
        ];
    }

    public function effectiveDate(): string
    {
        return Carbon::parse((string) config('legal.effective_date'))->toFormattedDateString();
    }

    /**
     * The policy.
     *
     * @return list<array{heading: string, body?: list<string>, list?: list<string>}>
     */
    public function privacy(): array
    {
        $op = $this->operator();
        $days = (int) config('legal.request_days', 30);
        $name = $op['name'];

        return [
            [
                'heading' => 'Who this policy is from',
                'body'    => [
                    "{$name} (\"we\", \"us\") operates the Mkhaya Hub business platform at {$op['site']}. "
                        . 'This policy explains what personal information the platform collects, why, who it is '
                        . 'shared with, and what you can ask us to do about it.',
                    'The platform is sold to organisations, and most of the personal information in it is placed '
                        . 'there by those organisations about their own employees, customers and suppliers. For that '
                        . 'information the organisation decides what is collected and why — they are the responsible '
                        . 'party under the Protection of Personal Information Act 4 of 2013 (POPIA), and we process it '
                        . 'as their operator, on their instruction and under contract. This policy covers both roles '
                        . 'and says which applies where it matters.',
                ],
            ],
            [
                'heading' => 'Connecting a social media account',
                'body'    => [
                    'The platform can announce an article on social media when the article is published to the '
                        . 'organisation\'s own website. Connecting an account is how that is authorised, and it is the '
                        . 'part of this policy a Facebook or LinkedIn reviewer is here to read.',
                    'When you connect an account we receive and store only what is needed to publish on your behalf:',
                ],
                'list' => [
                    'Facebook — the list of Facebook Pages your account administers (their names and ids), and an '
                        . 'access token for the Page you choose. We ask for pages_show_list to offer you that choice, '
                        . 'pages_read_engagement to confirm the Page is reachable, and pages_manage_posts to publish '
                        . 'the announcement.',
                    'LinkedIn — your name, your LinkedIn member id and the email address on your LinkedIn account '
                        . '(from the openid, profile and email scopes, which identify the account the posts will '
                        . 'appear under), and an access token. We ask for w_member_social to publish the announcement.',
                    'Instagram, X and WhatsApp, where an organisation connects them, follow the same pattern: an '
                        . 'account identifier and a token, and nothing else.',
                ],
            ],
            [
                'heading' => 'What we never do with a connected account',
                'body'    => [
                    'Stated as plainly as we can, because these are the things people reasonably worry about:',
                ],
                'list' => [
                    'We do not read your personal feed, your friends or connections, your messages, your contacts, '
                        . 'your photos or any content you did not publish through this platform.',
                    'We do not post anything unless the organisation has switched automatic announcing on, or '
                        . 'somebody has pressed a control that says it will post. Nothing is posted on sign-up.',
                    'We do not advertise, run campaigns or spend money on your behalf through these connections.',
                    'We do not sell personal information, and we do not share it with anyone for their own marketing.',
                ],
            ],
            [
                'heading' => 'What is published, and where it goes',
                'body'    => [
                    'An announcement is a short caption, the article\'s picture, and a link back to the article on '
                        . 'the organisation\'s website. It is sent to the network the organisation chose, and the '
                        . 'network then shows it according to its own terms and privacy policy — once a post is on '
                        . 'Facebook or LinkedIn, their policy governs it as well as ours.',
                    'The caption is written by an artificial intelligence service. To write it we send the article\'s '
                        . 'title and an extract of its text to a third-party AI provider. Article text is business '
                        . 'content written for publication, and we do not send personal information from the platform '
                        . 'to AI providers for this purpose. Those providers are used as processors and are not '
                        . 'permitted to use what we send them to train their models.',
                ],
            ],
            [
                'heading' => 'The rest of the platform',
                'body'    => [
                    'Where an organisation uses the platform for human resources, payroll, procurement, health and '
                        . 'safety or any other module, the personal information involved is placed there by that '
                        . 'organisation. Depending on the modules in use it can include names and contact details, '
                        . 'identity and tax numbers, banking details, employment and salary records, leave and '
                        . 'attendance, medical information recorded for occupational health or injury reporting, and '
                        . 'documents the organisation uploads.',
                    'We hold that information to provide the service the organisation bought. We do not use it for '
                        . 'our own purposes, we do not profile the people in it, and we do not disclose it to anyone '
                        . 'outside the organisation except where the organisation instructs us to, or where the law '
                        . 'requires it.',
                    'Each organisation\'s data is separated inside the database and enforced at the database itself, '
                        . 'not only in the application, so one customer\'s records are not reachable from another\'s '
                        . 'account.',
                ],
            ],
            [
                'heading' => 'How it is protected',
                'list'    => [
                    'All access to the platform requires an account and a password, and the connection is encrypted '
                        . 'in transit.',
                    'Social media access tokens, and the files organisations upload, are encrypted where they are '
                        . 'stored. Tokens are never shown back to a browser, and are never written to logs.',
                    'Access within our own team is limited to the people who need it to run and support the service, '
                        . 'and administrative actions inside the platform are recorded.',
                    'No system is immune. If personal information is accessed or acquired by somebody who should not '
                        . 'have it, we will notify the affected organisation and the Information Regulator as POPIA '
                        . 'section 22 requires.',
                ],
            ],
            [
                'heading' => 'How long it is kept',
                'body'    => [
                    'A social media connection is kept until it is disconnected. Disconnecting it deletes the stored '
                        . 'token and account details immediately. Posts already published to a network stay on that '
                        . 'network until they are deleted there — we cannot remove them for you, and the page on data '
                        . 'deletion explains what we can do.',
                    'Other personal information is kept for as long as the organisation\'s account is active, and '
                        . 'afterwards only for as long as the law requires them to keep those records — payroll and '
                        . 'tax records, for example, carry their own statutory retention periods. When an organisation '
                        . 'leaves, their data is deleted or returned to them on their instruction.',
                ],
            ],
            [
                'heading' => 'Your rights',
                'body'    => [
                    'Under POPIA you may ask what personal information we hold about you, ask for it to be corrected '
                        . 'or deleted, object to how it is being used, and complain to the Information Regulator. '
                        . 'Where the General Data Protection Regulation applies to an organisation using the '
                        . 'platform, the equivalent rights apply through them.',
                    'If the information concerns your employment or your dealings with one of our customers, the '
                        . 'quickest route is that organisation, because they decide what is held and why. Ask us and '
                        . "we will pass the request on and tell you we have done so. We answer requests within {$days} days.",
                    'The Information Regulator of South Africa can be reached at inforeg@inforegulator.org.za.',
                ],
            ],
            [
                'heading' => 'Children',
                'body'    => [
                    'The platform is a business system and is not offered to or aimed at children. We do not '
                        . 'knowingly collect information from children except where an organisation records a '
                        . 'dependant\'s details as part of an employee\'s benefit or medical record, which that '
                        . 'organisation is responsible for.',
                ],
            ],
            [
                'heading' => 'Changes to this policy',
                'body'    => [
                    'If this wording changes materially we will change the effective date at the top of this page '
                        . 'and, where the change affects how personal information is used, tell the organisations '
                        . 'using the platform. The English version of this policy governs.',
                ],
            ],
        ];
    }

    /**
     * How somebody gets their data removed.
     *
     * Facebook requires a URL for this before an app may leave development mode, and accepts a page
     * of instructions in place of a callback endpoint. Written as instructions a person can actually
     * follow, which is also what it is for.
     *
     * @return list<array{heading: string, body?: list<string>, list?: list<string>}>
     */
    public function dataDeletion(): array
    {
        $op = $this->operator();
        $days = (int) config('legal.request_days', 30);

        return [
            [
                'heading' => 'Removing a connected social media account',
                'body'    => [
                    'You can do this yourself, immediately, without asking us:',
                ],
                'list' => [
                    'Sign in and go to Content Marketing → Social Distribution.',
                    'Find the account under Social connections and press Disconnect.',
                    'The stored access token and the account details are deleted at that moment, and nothing further '
                        . 'can be posted to that account.',
                ],
            ],
            [
                'heading' => 'Removing it from Facebook\'s side',
                'body'    => [
                    'You can also revoke our access from your own Facebook settings, which works whether or not you '
                        . 'can still sign in here: Facebook → Settings & privacy → Settings → Apps and Websites, find '
                        . 'the app, and remove it. On LinkedIn the equivalent is Settings & Privacy → Data privacy → '
                        . 'Permitted services.',
                    'Revoking access there stops any further posting straight away. Ask us as well if you want the '
                        . 'stored record deleted on our side too.',
                ],
            ],
            [
                'heading' => 'Posts that were already published',
                'body'    => [
                    'An announcement that has already appeared on Facebook or LinkedIn lives on that network, under '
                        . 'your account. Disconnecting does not remove it, and we have no way to remove it for you '
                        . 'once our access is revoked — delete it on the network itself, or ask us before '
                        . 'disconnecting and we will delete the posts we published.',
                ],
            ],
            [
                'heading' => 'Asking us to delete everything',
                'body'    => [
                    "Write to {$op['privacy_email']} with the email address or account name the data is held under, and "
                        . 'say what you want deleted. We will confirm receipt, tell you anything we are obliged to '
                        . "keep and why, and complete the rest within {$days} days.",
                    'Where the information belongs to an organisation using the platform — your employer, for '
                        . 'instance — we are their operator and may not delete their records on our own initiative. '
                        . 'We will pass your request to them, tell you we have, and act as soon as they instruct us.',
                ],
            ],
        ];
    }
}
