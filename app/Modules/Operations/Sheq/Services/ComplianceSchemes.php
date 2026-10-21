<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Compliance schemes an organisation writes itself — GLOBALG.A.P., SIZA, an organic standard, a
 * customer's code of practice.
 *
 * A scheme is drafted clause by clause (the requirement, what proves it, how often it is renewed,
 * who answers for it), then published as a numbered version. Adopting a published scheme at a site
 * seeds that site's legal register through the same seeding a regulatory pack uses, under the pack
 * id `scheme:<code>`: its lines are evidenced, computed and reviewed like any other, and publishing
 * a changed clause later flags the lines for review rather than silently rewriting them.
 */
class ComplianceSchemes
{
    public function __construct(private readonly LegalRegisterService $register) {}

    public function create(int $companyId, array $data, ?int $actorId): object
    {
        $code = Str::slug($data['code'] ?? $data['name']);
        if ($code === '' || DB::table('sheq_compliance_schemes')->where('company_id', $companyId)->where('code', $code)->exists()) {
            throw new InvalidArgumentException('Give the scheme a code no other scheme here uses.');
        }
        $id = DB::table('sheq_compliance_schemes')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'code' => $code,
            'name' => $data['name'], 'jurisdiction' => $data['jurisdiction'] ?? null, 'scheme' => $data['scheme'] ?? null, 'status' => 'draft',
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('sheq_compliance_schemes')->find($id);
    }

    /** Add or change a clause. Changes reach the register when the scheme is next published. */
    public function clause(int $companyId, object $scheme, array $c): void
    {
        $types = array_values(array_filter(array_map(fn ($t) => strtoupper(trim((string) $t)), (array) ($c['evidence_types'] ?? []))));
        if ($types === []) {
            throw new InvalidArgumentException('Say what proves the clause is met.');
        }
        if (! empty($c['review_frequency'])) {
            try {
                new \DateInterval($c['review_frequency']);
            } catch (\Throwable) {
                throw new InvalidArgumentException('Renewal is a period such as P12M (12 months) or P1Y.');
            }
        }
        DB::table('sheq_compliance_scheme_clauses')->updateOrInsert(['scheme_id' => $scheme->id, 'clause' => $c['clause']], [
            'company_id' => $companyId, 'instrument' => $c['instrument'] ?? $scheme->name, 'requirement' => $c['requirement'],
            'category' => $c['category'] ?? null, 'evidence_types' => json_encode($types), 'review_frequency' => ($c['review_frequency'] ?? null) ?: null,
            'responsible_role' => $c['responsible_role'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Publish the clauses as the next version; every site that adopted the scheme is refreshed. */
    public function publish(int $companyId, object $scheme, ?int $actorId): int
    {
        if (! DB::table('sheq_compliance_scheme_clauses')->where('scheme_id', $scheme->id)->exists()) {
            throw new InvalidArgumentException('A scheme needs at least one clause before it is published.');
        }

        return DB::transaction(function () use ($companyId, $scheme, $actorId) {
            $version = (int) $scheme->version + 1;
            DB::table('sheq_compliance_schemes')->where('id', $scheme->id)->update(['version' => $version, 'status' => 'published',
                'published_by' => $actorId, 'published_at' => now(), 'updated_at' => now()]);
            $fresh = DB::table('sheq_compliance_schemes')->find($scheme->id);
            foreach (DB::table('sheq_compliance_scheme_adoptions')->where('scheme_id', $scheme->id)->get() as $a) {
                $this->register->seedFromPack($companyId, $this->manifest($fresh), $a->site_id ? (int) $a->site_id : null);
                DB::table('sheq_compliance_scheme_adoptions')->where('id', $a->id)->update(['version' => $version, 'updated_at' => now()]);
            }

            return $version;
        });
    }

    /** Adopt the published scheme at a site (null: the whole organisation); its clauses become register lines. */
    public function adopt(int $companyId, object $scheme, ?int $siteId, ?int $actorId): array
    {
        if ($scheme->status !== 'published') {
            throw new InvalidArgumentException('Publish the scheme before a site adopts it.');
        }

        return DB::transaction(function () use ($companyId, $scheme, $siteId, $actorId) {
            $counts = $this->register->seedFromPack($companyId, $this->manifest($scheme), $siteId);
            DB::table('sheq_compliance_scheme_adoptions')->updateOrInsert(['scheme_id' => $scheme->id, 'site_id' => $siteId],
                ['company_id' => $companyId, 'version' => $scheme->version, 'adopted_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('sheq_legal_register_lines')->where('company_id', $companyId)->where('regulatory_pack_id', self::packId($scheme))
                ->when($siteId === null, fn ($q) => $q->whereNull('site_id'), fn ($q) => $q->where('site_id', $siteId))->whereNull('deleted_at')->pluck('uuid') as $uuid) {
                $this->register->compute($companyId, $uuid);
            }

            return $counts;
        });
    }

    public static function packId(object $scheme): string
    {
        return "scheme:{$scheme->code}";
    }

    /** The scheme as the manifest the register seeds from. */
    private function manifest(object $scheme): PackManifest
    {
        $clauses = DB::table('sheq_compliance_scheme_clauses')->where('scheme_id', $scheme->id)->orderBy('clause')->get()
            ->map(fn ($c) => ['clause' => $c->clause, 'instrument' => $c->instrument, 'requirement' => $c->requirement, 'applies_to' => 'ALL',
                'responsible_role' => $c->responsible_role, 'evidence_type' => json_decode($c->evidence_types, true) ?: [], 'review_frequency' => $c->review_frequency])->all();

        return new PackManifest(self::packId($scheme), (string) $scheme->version, ['name' => $scheme->name, 'jurisdiction' => $scheme->jurisdiction ?? 'ORG', 'legal_register' => $clauses]);
    }
}
