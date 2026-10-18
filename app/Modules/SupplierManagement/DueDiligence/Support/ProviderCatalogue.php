<?php

namespace App\Modules\SupplierManagement\DueDiligence\Support;

use App\Modules\SupplierManagement\Models\DueDiligenceProvider;

/**
 * The verification sources Mkhaya ships knowing about.
 *
 * Modelled on the Fleet module's integration catalogue: several named providers per capability, the
 * customer connects whichever they already hold an account with, and the module never learns any of
 * their names. A tenant with a TransUnion contract and a tenant with an Experian contract configure
 * the same check and get the same observation out.
 *
 * Every entry is a *slot*, not a shipped integration, and the distinction is deliberate. Access to
 * CIPC, SARS, the bureaux and the screening houses is by paid, authorised, per-customer contract, and
 * each of them speaks its own dialect over its own endpoint. Writing a client against an API contract
 * we have not been given would mean inventing one, and an invented integration that returns plausible
 * answers is the single most dangerous thing this module could contain. So each slot carries the
 * shape of its account — what credentials it needs, what it can answer — and the customer supplies
 * the endpoints their contract actually gives them. `credential_fields` is what turns that from a
 * blank JSON box into a form somebody can fill in.
 *
 * `access_note` is shown verbatim on the Data Source Registry, because "why is this not connected?"
 * is the first question anyone opening that screen has.
 */
final class ProviderCatalogue
{
    /** @return array<int, array<string,mixed>> */
    public static function definitions(): array
    {
        return array_merge(
            self::internal(),
            self::companyRegistry(),
            self::tax(),
            self::banking(),
            self::credit(),
            self::screening(),
            self::media(),
            self::social(),
            self::crossBorder(),
            self::other(),
        );
    }

    /** Always available, needs nothing, and is what makes the framework useful on day one. */
    private static function internal(): array
    {
        return [[
            'code' => 'manual',
            'name' => 'Manual check (recorded by a person)',
            'driver' => 'manual',
            'category' => 'internal',
            'country' => null,
            'capabilities' => array_keys(CheckType::all()),
            'requires_credentials' => false,
            'credential_fields' => [],
            'access_note' => 'Always available. Somebody performs the check at the source and records what they found — same observation log, same evidence entry, same effect on approval as an automated pull. Attributed to the person who looked.',
            'sort' => 0,
        ]];
    }

    /**
     * Company registry and beneficial ownership.
     *
     * CIPC itself sells direct access; the rest are the established South African aggregators that
     * resell it alongside their own enrichment. Which one a customer holds is their commercial
     * decision, and all five answer the same three check types.
     */
    private static function companyRegistry(): array
    {
        $capabilities = [
            CheckType::COMPANY_REGISTRY,
            CheckType::COMPANY_DIRECTORS,
            CheckType::COMPANY_ADDRESS,
            CheckType::OWNERSHIP_BENEFICIAL,
        ];

        $credentials = [
            ['key' => 'api_key', 'label' => 'API key or token', 'required' => true],
            ['key' => 'customer_code', 'label' => 'Customer code', 'required' => false],
            ['key' => 'username', 'label' => 'Username', 'required' => false],
        ];

        return [
            [
                'code' => 'cipc',
                'name' => 'CIPC (direct)',
                'driver' => 'http_json',
                'category' => 'government',
                'country' => 'ZA',
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Your own authorised CIPC access — customer code and API credentials from your CIPC account. Beneficial-ownership data is separately access-controlled and only returns for accounts entitled to it. This connector never scrapes the CIPC website; point it at the endpoint your account provides.',
                'sort' => 10,
            ],
            [
                'code' => 'searchworks',
                'name' => 'SearchWorks',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Commercial CIPC aggregator. Requires your SearchWorks account and its API credentials; confirm the endpoint and response fields against the documentation for your contract.',
                'sort' => 11,
            ],
            [
                'code' => 'lexisnexis_windeed',
                'name' => 'LexisNexis WinDeed',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Company, director and property searches through your WinDeed account. Per-search billing is on your contract, so set a monthly allowance.',
                'sort' => 12,
            ],
            [
                'code' => 'kyckr',
                'name' => 'Kyckr',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Cross-border company registry and UBO retrieval. Useful where suppliers are registered outside South Africa; coverage and cost vary by jurisdiction.',
                'sort' => 13,
            ],
            [
                'code' => 'creditinfo_registry',
                'name' => 'Creditinfo — company data',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Company identity and ownership data alongside their credit products. If you already license their bureau feed, this usually sits on the same contract.',
                'sort' => 14,
            ],
        ];
    }

    /** Tax compliance and VAT. SARS is the source; the rest reach it on your behalf. */
    private static function tax(): array
    {
        $credentials = [
            ['key' => 'api_key', 'label' => 'API key or token', 'required' => true],
            ['key' => 'tax_practitioner_number', 'label' => 'Tax practitioner / eFiling profile', 'required' => false],
        ];

        return [
            [
                'code' => 'sars_tcs',
                'name' => 'SARS — Tax Compliance Status',
                'driver' => 'http_json',
                'category' => 'government',
                'country' => 'ZA',
                'capabilities' => [CheckType::TAX_COMPLIANCE],
                'credential_fields' => $credentials,
                'access_note' => 'Verified with the TCS PIN the supplier gives you — that PIN is their authorisation for a third party to check. The answer is their status at the moment of verification, which is why this records a verification rather than storing a certificate.',
                'sort' => 20,
            ],
            [
                'code' => 'sars_vat',
                'name' => 'SARS — VAT vendor search',
                'driver' => 'http_json',
                'category' => 'government',
                'country' => 'ZA',
                'capabilities' => [CheckType::TAX_VAT_VENDOR],
                'credential_fields' => $credentials,
                'access_note' => 'Confirms a VAT number and the entity registered against it. Where you have no programmatic access, record the lookup as a manual check — it carries the same weight.',
                'sort' => 21,
            ],
            [
                'code' => 'tax_aggregator',
                'name' => 'Tax status via aggregator',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => [CheckType::TAX_COMPLIANCE, CheckType::TAX_VAT_VENDOR],
                'credential_fields' => $credentials,
                'access_note' => 'Several South African vendors resell TCS and VAT verification. Configure whichever you hold; the PIN is still the supplier\'s authorisation and still required.',
                'sort' => 22,
            ],
            [
                'code' => 'treasury_csd',
                'name' => 'National Treasury — Central Supplier Database',
                'driver' => 'http_json',
                'category' => 'government',
                'country' => 'ZA',
                'capabilities' => [CheckType::GOVERNMENT_CSD],
                'credential_fields' => $credentials,
                'access_note' => 'Relevant to suppliers doing government business: registration, supplier number and status. Requires authorised access.',
                'sort' => 23,
            ],
        ];
    }

    /**
     * Account verification — the check that stops payment redirection.
     *
     * Five slots because this is the one every customer will want on, and the provider they can get
     * varies entirely with who they bank with.
     */
    private static function banking(): array
    {
        $credentials = [
            ['key' => 'api_key', 'label' => 'API key', 'required' => true],
            ['key' => 'api_secret', 'label' => 'API secret', 'required' => false],
            ['key' => 'merchant_id', 'label' => 'Merchant / service ID', 'required' => false],
        ];

        return [
            [
                'code' => 'avs_bankserv',
                'name' => 'BankservAfrica AVS (via your bank)',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => [CheckType::BANK_ACCOUNT],
                'credential_fields' => $credentials,
                'access_note' => 'Account verification through your own bank\'s AVS service. Real-time and batch differ in cost and turnaround; both answer the same question — does this account exist and does the holder match.',
                'sort' => 30,
            ],
            [
                'code' => 'avs_netcash',
                'name' => 'Netcash AVS',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => [CheckType::BANK_ACCOUNT],
                'credential_fields' => $credentials,
                'access_note' => 'Account verification on your Netcash account. If you already pay suppliers through Netcash, this is usually the shortest route to having the check on.',
                'sort' => 31,
            ],
            [
                'code' => 'avs_transunion',
                'name' => 'TransUnion AVS',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => [CheckType::BANK_ACCOUNT],
                'credential_fields' => $credentials,
                'access_note' => 'Account verification alongside their bureau products. Frequently on the same contract as your credit feed.',
                'sort' => 32,
            ],
            [
                'code' => 'avs_ozow',
                'name' => 'Ozow account verification',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => [CheckType::BANK_ACCOUNT],
                'credential_fields' => $credentials,
                'access_note' => 'Account verification through Ozow. Check what their response returns for holder name — a service that confirms only that the account exists cannot answer the question this gate asks.',
                'sort' => 33,
            ],
            [
                'code' => 'avs_paystack',
                'name' => 'Paystack account resolution',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => [CheckType::BANK_ACCOUNT],
                'credential_fields' => $credentials,
                'access_note' => 'Account-name resolution across several African markets. Coverage varies by country and bank; treat an unresolved account as unverified rather than as a failure.',
                'sort' => 34,
            ],
        ];
    }

    /** Business credit. Multi-provider from the start — never one hard-coded bureau. */
    private static function credit(): array
    {
        $capabilities = [CheckType::CREDIT_REPORT, CheckType::LITIGATION];

        $credentials = [
            ['key' => 'api_key', 'label' => 'API key or client id', 'required' => true],
            ['key' => 'api_secret', 'label' => 'Client secret', 'required' => false],
            ['key' => 'subscriber_code', 'label' => 'Subscriber code', 'required' => false],
        ];

        $note = ' Licensed and metered — each report costs money, so set a monthly allowance. Reports are kept as dated history because deterioration is a diff between two of them.';

        return [
            [
                'code' => 'credit_transunion',
                'name' => 'TransUnion Business',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Commercial credit reports on your TransUnion subscriber account.'.$note,
                'sort' => 40,
            ],
            [
                'code' => 'credit_experian',
                'name' => 'Experian Business (incl. Compuscan)',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Business credit and payment behaviour on your Experian account.'.$note,
                'sort' => 41,
            ],
            [
                'code' => 'credit_xds',
                'name' => 'XDS (Xpert Decision Systems)',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'South African bureau with commercial and judgment data.'.$note,
                'sort' => 42,
            ],
            [
                'code' => 'credit_creditinfo',
                'name' => 'Creditinfo',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Bureau data across several African markets — useful where your suppliers are not all South African.'.$note,
                'sort' => 43,
            ],
            [
                'code' => 'credit_vericred',
                'name' => 'VeriCred / Consumer Profile Bureau',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Smaller South African bureau; often the cheaper option for lower volumes.'.$note,
                'sort' => 44,
            ],
        ];
    }

    /** Sanctions, PEP and watchlists. The statutory floor is free; breadth is commercial. */
    private static function screening(): array
    {
        $capabilities = [
            CheckType::SCREENING_SANCTIONS,
            CheckType::SCREENING_PEP,
            CheckType::MEDIA_ADVERSE,
        ];

        $credentials = [
            ['key' => 'api_key', 'label' => 'API key', 'required' => true],
            ['key' => 'api_secret', 'label' => 'Client secret', 'required' => false],
        ];

        return [
            [
                'code' => 'screening_worldcheck',
                'name' => 'LSEG World-Check',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Sanctions, PEP and adverse media at the widest coverage, and priced accordingly. Screen at onboarding and again whenever measures change.',
                'sort' => 50,
            ],
            [
                'code' => 'screening_dowjones',
                'name' => 'Dow Jones Risk & Compliance',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Sanctions, PEP and state-ownership data. Strong on the ownership-linkage questions this module\'s graph asks.',
                'sort' => 51,
            ],
            [
                'code' => 'screening_complyadvantage',
                'name' => 'ComplyAdvantage',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'API-first screening with ongoing monitoring. Its own matching runs before ours; both scores are kept so an adjudicator can see each.',
                'sort' => 52,
            ],
            [
                'code' => 'screening_lexisnexis',
                'name' => 'LexisNexis Bridger / WorldCompliance',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => $capabilities,
                'credential_fields' => $credentials,
                'access_note' => 'Screening and enhanced due-diligence reports. Frequently already held by customers who use LexisNexis for company searches.',
                'sort' => 53,
            ],
            [
                'code' => 'screening_opensanctions',
                'name' => 'OpenSanctions',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => [CheckType::SCREENING_SANCTIONS, CheckType::SCREENING_PEP],
                'credential_fields' => $credentials,
                'access_note' => 'Consolidated open sanctions and PEP data, including the FIC list. The cheapest way to have screening running at all — narrower than the commercial houses on adverse media, and honest about it.',
                'sort' => 54,
            ],
        ];
    }

    /** News and adverse media. */
    private static function media(): array
    {
        $credentials = [
            ['key' => 'api_key', 'label' => 'API key', 'required' => true],
            ['key' => 'search_engine_id', 'label' => 'Search engine / index id', 'required' => false],
        ];

        return [
            [
                'code' => 'media_newsapi',
                'name' => 'NewsAPI',
                'driver' => 'http_json',
                'category' => 'intelligence',
                'country' => null,
                'capabilities' => [CheckType::MEDIA_ADVERSE],
                'credential_fields' => $credentials,
                'access_note' => 'Global news search. Cheap and broad; check its licence terms for commercial use and archive depth.',
                'sort' => 60,
            ],
            [
                'code' => 'media_gdelt',
                'name' => 'GDELT',
                'driver' => 'http_json',
                'category' => 'intelligence',
                'country' => null,
                'capabilities' => [CheckType::MEDIA_ADVERSE],
                'credential_fields' => [],
                'requires_credentials' => false,
                'access_note' => 'Open global news index with no account required. Noisy by nature, which is what the entity-resolution scoring is for.',
                'sort' => 61,
            ],
            [
                'code' => 'media_google_cse',
                'name' => 'Google Programmable Search',
                'driver' => 'http_json',
                'category' => 'intelligence',
                'country' => null,
                'capabilities' => [CheckType::MEDIA_ADVERSE],
                'credential_fields' => $credentials,
                'access_note' => 'Web and news search over an index you define. Query quotas are per day; set a monthly allowance to match.',
                'sort' => 62,
            ],
            [
                'code' => 'media_bing',
                'name' => 'Bing / Azure News Search',
                'driver' => 'http_json',
                'category' => 'intelligence',
                'country' => null,
                'capabilities' => [CheckType::MEDIA_ADVERSE],
                'credential_fields' => $credentials,
                'access_note' => 'News search on an Azure subscription key.',
                'sort' => 63,
            ],
            [
                'code' => 'media_local_press',
                'name' => 'Local press aggregator',
                'driver' => 'http_json',
                'category' => 'intelligence',
                'country' => 'ZA',
                'capabilities' => [CheckType::MEDIA_ADVERSE],
                'credential_fields' => $credentials,
                'access_note' => 'For South African trade and regional press that the global indexes miss. Configure whichever aggregator you hold.',
                'sort' => 64,
            ],
        ];
    }

    /**
     * Social platforms — one slot per platform, each off until switched on.
     *
     * Separate entries rather than one "social" provider because the terms, the credentials and the
     * legality differ per platform, and a single switch would mean turning on the ones you may not
     * use in order to get the ones you may. Each carries what its API actually permits, so the person
     * enabling it can see the constraint before they agree to it.
     */
    private static function social(): array
    {
        $credentials = [
            ['key' => 'api_key', 'label' => 'API key / client id', 'required' => true],
            ['key' => 'api_secret', 'label' => 'Client secret', 'required' => false],
            ['key' => 'access_token', 'label' => 'Access token', 'required' => false],
        ];

        $platforms = [
            ['linkedin', 'LinkedIn', 'Company and people data through an approved LinkedIn Marketing or Sales API partnership. Scraping profiles is prohibited by their terms; without a partnership, leave this off.'],
            ['x', 'X (Twitter)', 'Public post search on a paid X API tier. The free tier does not permit search, so this needs a subscription to be useful.'],
            ['facebook', 'Facebook / Meta', 'Public Page content through the Graph API with an approved app and permissions. Personal profiles are out of scope and must stay so.'],
            ['instagram', 'Instagram', 'Business and Creator account content through the Graph API. Requires the same Meta app review as Facebook.'],
            ['youtube', 'YouTube', 'Public channel and video data on a Google API key. Quota is per day.'],
            ['tiktok', 'TikTok', 'Public content through the Display or Research API. Research access is granted case by case.'],
            ['reddit', 'Reddit', 'Public subreddit and comment search on a registered app. Rate-limited; commercial use has its own terms.'],
        ];

        return array_map(fn (array $platform, int $index) => [
            'code' => 'social_'.$platform[0],
            'name' => $platform[1],
            'driver' => 'http_json',
            'category' => 'social',
            'country' => null,
            'capabilities' => [CheckType::SOCIAL_REPUTATION],
            'credential_fields' => $credentials,
            'access_note' => $platform[2].' Only public content is ever retrieved, and only for entities connected to the supplier.',
            'sort' => 70 + $index,
        ], $platforms, array_keys($platforms));
    }

    /**
     * Eswatini and Botswana.
     *
     * The module is already sold into both, and a South African registry cannot answer a question
     * about a Botswana company — so a supplier registered in Gaborone would sit permanently at
     * "never checked" against sources that structurally cannot help.
     *
     * Kept as country-scoped slots rather than folded into the ZA entries, because the registry, the
     * tax authority and the company-number format are all different, and a connector that pretends
     * otherwise produces confident answers about the wrong register.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function crossBorder(): array
    {
        $credentials = [
            ['key' => 'api_key', 'label' => 'API key or token', 'required' => true],
            ['key' => 'customer_code', 'label' => 'Customer / account code', 'required' => false],
        ];

        return [
            [
                'code' => 'esw_registrar',
                'name' => 'Eswatini — Registrar of Companies',
                'driver' => 'http_json',
                'category' => 'government',
                'country' => 'SZ',
                'capabilities' => [CheckType::COMPANY_REGISTRY, CheckType::COMPANY_DIRECTORS, CheckType::COMPANY_ADDRESS],
                'credential_fields' => $credentials,
                'access_note' => 'Company registry for Eswatini, through whatever authorised access your organisation holds. Company number formats differ from South Africa, so confirm the field map against a known company before relying on it.',
                'sort' => 90,
            ],
            [
                'code' => 'esw_revenue',
                'name' => 'Eswatini Revenue Service — tax status',
                'driver' => 'http_json',
                'category' => 'government',
                'country' => 'SZ',
                'capabilities' => [CheckType::TAX_COMPLIANCE, CheckType::TAX_VAT_VENDOR],
                'credential_fields' => $credentials,
                'access_note' => 'Tax compliance for Eswatini-registered suppliers. As with SARS, the answer is a statement about the moment it was asked.',
                'sort' => 91,
            ],
            [
                'code' => 'bw_cipa',
                'name' => 'Botswana — CIPA',
                'driver' => 'http_json',
                'category' => 'government',
                'country' => 'BW',
                'capabilities' => [CheckType::COMPANY_REGISTRY, CheckType::COMPANY_DIRECTORS, CheckType::COMPANY_ADDRESS, CheckType::OWNERSHIP_BENEFICIAL],
                'credential_fields' => $credentials,
                'access_note' => 'Companies and Intellectual Property Authority of Botswana. Beneficial-ownership data is separately controlled, as it is at CIPC.',
                'sort' => 92,
            ],
            [
                'code' => 'bw_burs',
                'name' => 'Botswana Unified Revenue Service — tax status',
                'driver' => 'http_json',
                'category' => 'government',
                'country' => 'BW',
                'capabilities' => [CheckType::TAX_COMPLIANCE, CheckType::TAX_VAT_VENDOR],
                'credential_fields' => $credentials,
                'access_note' => 'Tax compliance for Botswana-registered suppliers.',
                'sort' => 93,
            ],
            [
                'code' => 'regional_bureau',
                'name' => 'Regional credit bureau (SADC)',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => [CheckType::CREDIT_REPORT],
                'credential_fields' => $credentials,
                'access_note' => 'Credit data across SADC markets, for suppliers your South African bureau does not cover. Coverage varies sharply by country — treat a thin file as unverified rather than as clean.',
                'sort' => 94,
            ],
        ];
    }

    /** Cyber posture and B-BBEE. */
    private static function other(): array
    {
        return [
            [
                'code' => 'cyber_posture',
                'name' => 'Cyber risk rating provider',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => null,
                'capabilities' => [CheckType::CYBER_POSTURE],
                'credential_fields' => [['key' => 'api_key', 'label' => 'API key', 'required' => true]],
                'access_note' => 'External security ratings — domain, certificate and breach exposure. Relevant for suppliers with access to your data or systems; noise for a stationery supplier.',
                'sort' => 80,
            ],
            [
                'code' => 'bbbee_verification',
                'name' => 'B-BBEE verification agency',
                'driver' => 'http_json',
                'category' => 'commercial',
                'country' => 'ZA',
                'capabilities' => [CheckType::BBBEE_CERTIFICATE],
                'credential_fields' => [['key' => 'api_key', 'label' => 'API key', 'required' => true]],
                'access_note' => 'Confirms a certificate and the accreditation of the professional who issued it. Certificates and sworn affidavits are not interchangeable, and the system records which one it saw.',
                'sort' => 81,
            ],
        ];
    }

    /**
     * Write the catalogue into the database, idempotently.
     *
     * Upsert by code, so re-running installs on a new system and refreshes capabilities and notes on
     * an existing one. Tenant credentials live in another table and are never touched.
     */
    public static function install(): int
    {
        $count = 0;

        foreach (self::definitions() as $definition) {
            DueDiligenceProvider::updateOrCreate(
                ['code' => $definition['code']],
                $definition + ['is_active' => true, 'requires_credentials' => $definition['requires_credentials'] ?? true]
            );
            $count++;
        }

        return $count;
    }

    /** Providers grouped by what they answer, for a screen that offers a choice per capability. */
    public static function byCapability(): array
    {
        $grouped = [];

        foreach (self::definitions() as $definition) {
            foreach ($definition['capabilities'] as $capability) {
                $grouped[$capability][] = $definition['code'];
            }
        }

        return $grouped;
    }
}
