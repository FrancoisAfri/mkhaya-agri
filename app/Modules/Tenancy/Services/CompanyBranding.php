<?php

namespace App\Modules\Tenancy\Services;

use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Whose name and logo an output wears, for a GIVEN company — not only the signed-in user's.
 *
 * The rule, stated once: white label on (the company was granted it, or the installation is
 * self-hosted), and everything the company's customers, growers and auditors see carries the
 * company's own name and logo and nothing of ours. White label off, the product speaks for itself
 * as it always has.
 *
 * Used by the in-app header (for the company in play, which for a platform administrator switched
 * into a client is that client, not their own), and by every outward-facing output that has no
 * signed-in user at all: the public trace page, PDFs, the grower portal and its emails.
 */
class CompanyBranding
{
    public const PRODUCT = 'Mkhaya Agri';

    public const DEFAULT_LOGO = '/images/logo_default.png';

    public function __construct(private readonly TenantContext $tenants, private readonly CurrentCompany $current) {}

    /** The company in play for a request: the one switched into, else the user's home company. */
    public function companyIdFor(?User $user): ?int
    {
        return $this->current->id() ?? ($user?->company_id ? (int) $user->company_id : null);
    }

    /**
     * @return array{white_label: bool, hide_mkhaya: bool, name: string, company_name: ?string, company_uuid: ?string,
     *               logo_data_uri: ?string, logo_version: ?string, has_logo: bool}
     */
    public function for(?int $companyId, bool $withLogoData = true): array
    {
        $company = $companyId ? DB::table('companies')->where('id', $companyId)->first(['id', 'uuid', 'name', 'white_label']) : null;
        $whiteLabel = Edition::isSelfHosted() || (bool) ($company->white_label ?? false);
        $base = ['white_label' => false, 'hide_mkhaya' => false, 'name' => self::PRODUCT, 'company_name' => $company->name ?? null,
            'company_uuid' => $company->uuid ?? null, 'logo_data_uri' => null, 'logo_version' => null, 'has_logo' => false];
        if (! $company || ! $whiteLabel) {
            return $base;
        }

        // Read as that company: the identity row is behind row-level security.
        $identity = $this->tenants->run((int) $company->id, fn () => DB::table('company_identities')->where('company_id', $company->id)
            ->first(['company_logo', 'company_name', 'full_company_name']));
        $name = trim((string) ($identity->company_name ?? '')) ?: trim((string) ($identity->full_company_name ?? '')) ?: (string) $company->name;
        $raw = $identity->company_logo ?? null;
        // Decoded only where the picture is embedded (PDFs, server pages); a link to it needs no decoding.
        $logo = $withLogoData ? $this->decode($raw) : null;

        return ['white_label' => true, 'hide_mkhaya' => true, 'name' => $name, 'company_name' => $name, 'company_uuid' => $company->uuid,
            'logo_data_uri' => $logo ? 'data:'.$logo['mime'].';base64,'.base64_encode($logo['binary']) : null,
            'logo_version' => filled($raw) ? substr(md5((string) $raw), 0, 10) : null, 'has_logo' => $withLogoData ? (bool) $logo : filled($raw)];
    }

    /** The company's logo as stored (base64 blob in the database; a legacy file path still read). */
    public function logo(int $companyId): ?array
    {
        $raw = $this->tenants->run($companyId, fn () => DB::table('company_identities')->where('company_id', $companyId)->value('company_logo'));

        return $this->decode($raw);
    }

    /** @return array{binary: string, mime: string}|null */
    private function decode(?string $raw): ?array
    {
        if (! $raw) {
            return null;
        }
        $blob = json_decode($raw, true);
        if (is_array($blob) && ! empty($blob['data'])) {
            $binary = base64_decode((string) $blob['data'], true);

            return $binary === false ? null : ['binary' => $binary, 'mime' => (string) ($blob['mime'] ?? 'image/png')];
        }
        try {
            if (Storage::disk('public')->exists($raw)) {
                return ['binary' => (string) Storage::disk('public')->get($raw), 'mime' => Storage::disk('public')->mimeType($raw) ?: 'image/png'];
            }
        } catch (\Throwable) {
            // not a path either
        }

        return null;
    }
}
