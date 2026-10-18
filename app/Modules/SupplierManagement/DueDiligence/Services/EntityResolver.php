<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierHandle;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use Illuminate\Support\Str;

/**
 * Is this mention actually about our supplier?
 *
 * This is the single most important thing in reputation monitoring and the one most often skipped,
 * because skipping it looks like it works. Search a supplier's name, get results, show them: the
 * demo is impressive and the production system is unusable by month two, when a procurement officer
 * has read forty articles about a differently-owned company with a similar name and stopped opening
 * the alerts. The one real story then arrives into a queue nobody reads.
 *
 * So a mention is scored against everything we independently know about the supplier — registration
 * number, domain, address, directors, brands, industry, geography — and the score is built from
 * stated reasons rather than a similarity number nobody can argue with. Below the floor, a finding is
 * not scored, not alerted and not counted: it goes to a review queue where a person decides, because
 * a wrong attribution that reaches the risk score is worse than a missed mention.
 *
 * The asymmetry is deliberate. A registration number or a domain in the text is near-proof; a name
 * on its own is barely evidence at all.
 */
class EntityResolver
{
    /** Below this, a finding is not attributed — it goes to review instead of onto the score. */
    public const CONFIDENCE_FLOOR = 60;

    /** At or above this, attribution is treated as settled. */
    public const CONFIDENCE_STRONG = 85;

    public function __construct(private readonly FieldComparator $comparator) {}

    /**
     * Score one mention against one supplier.
     *
     * @param  array{title?:string, excerpt?:string, url?:string, author?:string, matched_subject?:string}  $mention
     * @return array{confidence:int, basis:array<int,array{factor:string,effect:string,detail:string}>, attributable:bool, strong:bool}
     */
    public function resolve(Supplier $supplier, array $mention): array
    {
        $text = Str::lower(trim(
            ($mention['title'] ?? '').' '.($mention['excerpt'] ?? '').' '.($mention['url'] ?? '')
        ));

        $basis = [];
        $confidence = 0;

        // ── Near-proof: identifiers that belong to one company only ─────────
        $registration = preg_replace('/\D+/', '', (string) $supplier->company_registration_number);

        if ($registration !== '' && str_contains(preg_replace('/\D+/', '', $text) ?: '', $registration)) {
            $basis[] = ['factor' => 'registration_number', 'effect' => 'confirms', 'detail' => 'The registration number appears in the text.'];
            // Near-proof on its own: a registration number belongs to exactly one company.
            $confidence += 85;
        }

        if ($domain = $this->domain($supplier)) {
            if (str_contains($text, $domain)) {
                $basis[] = ['factor' => 'domain', 'effect' => 'confirms', 'detail' => "The supplier's own domain ({$domain}) appears."];
                // A company's own domain is theirs — enough to attribute on its own, short of proof.
                $confidence += 65;
            }
        }

        // A platform handle we hold for this supplier is the account itself speaking or being named.
        $handles = SupplierHandle::query()->where('supplier_id', $supplier->id)->active()->get();

        foreach ($handles->where('kind', SupplierHandle::KIND_HANDLE) as $handle) {
            $needle = Str::lower(ltrim((string) $handle->value, '@'));

            if ($needle !== '' && str_contains($text, $needle)) {
                $basis[] = ['factor' => 'handle', 'effect' => 'confirms', 'detail' => "Mentions the account {$handle->value}."];
                $confidence += 40;

                break;
            }
        }

        // ── Supporting: names, which are evidence but not proof ─────────────
        $nameScore = $this->nameEvidence($supplier, $handles, $text, $basis);
        $confidence += $nameScore;

        // ── Corroborating: things that make a same-name company less likely ─
        foreach ($this->directorNames($supplier) as $director) {
            if ($director !== '' && str_contains($text, $director)) {
                $basis[] = ['factor' => 'director', 'effect' => 'confirms', 'detail' => 'A director of the supplier is named.'];
                $confidence += 30;

                break;
            }
        }

        if ($town = $this->town($supplier)) {
            if (str_contains($text, $town)) {
                $basis[] = ['factor' => 'location', 'effect' => 'supports', 'detail' => "Mentions {$town}, where the supplier is registered."];
                $confidence += 10;
            }
        }

        // ── Contradicting ───────────────────────────────────────────────────
        // A different registration number in the same text is a strong signal that this is a
        // different company with a similar name, which is exactly the case that floods a queue.
        if ($registration !== '' && preg_match('/\b(19|20)\d{2}\s?\/\s?\d{6}\s?\/\s?\d{2}\b/', $text, $found)) {
            $seen = preg_replace('/\D+/', '', $found[0]);

            if ($seen !== $registration) {
                $basis[] = ['factor' => 'registration_number', 'effect' => 'contradicts', 'detail' => 'A different registration number appears in the text.'];
                $confidence -= 45;
            }
        }

        if ($nameScore === 0 && $confidence > 0) {
            $basis[] = ['factor' => 'name', 'effect' => 'weakens', 'detail' => 'The supplier is not named directly.'];
        }

        if ($basis === []) {
            $basis[] = ['factor' => 'none', 'effect' => 'weakens', 'detail' => 'Nothing in the text ties this to the supplier.'];
        }

        $confidence = (int) max(0, min(100, $confidence));

        return [
            'confidence' => $confidence,
            'basis' => $basis,
            'attributable' => $confidence >= self::CONFIDENCE_FLOOR,
            'strong' => $confidence >= self::CONFIDENCE_STRONG,
        ];
    }

    /**
     * How much the names in the text are worth.
     *
     * A distinctive registered or trading name counts for a lot; a two-word generic one for much less,
     * because "National Trading" matching "National Trading" is close to no information. Brands are
     * scored like names because a brand is frequently the only name a story uses.
     */
    private function nameEvidence(Supplier $supplier, $handles, string $text, array &$basis): int
    {
        $candidates = collect([
            $supplier->company_name,
            $supplier->trading_name,
        ])->merge($handles->where('kind', SupplierHandle::KIND_BRAND)->pluck('value'))
            ->filter()
            ->unique();

        $best = 0;

        foreach ($candidates as $candidate) {
            $normalised = $this->comparator->normaliseEntityName((string) $candidate);

            if ($normalised === '' || ! str_contains($text, $normalised)) {
                continue;
            }

            $distinctive = $this->isDistinctive($normalised);
            $score = $distinctive ? 45 : 20;

            if ($score > $best) {
                $best = $score;
                $basis[] = [
                    'factor' => 'name',
                    'effect' => $distinctive ? 'confirms' : 'supports',
                    'detail' => $distinctive
                        ? "Names \"{$candidate}\"."
                        : "Names \"{$candidate}\", which is a common form of words.",
                ];
            }
        }

        return $best;
    }

    /**
     * Is this name distinctive enough that a match means something?
     *
     * Two tests: length, and whether it is made only of generic trading words. "Zolani Civils" is
     * distinctive; "National Supply Services" would match half the register.
     */
    private function isDistinctive(string $normalised): bool
    {
        $generic = ['national', 'general', 'supply', 'supplies', 'services', 'trading', 'group',
            'holdings', 'solutions', 'enterprises', 'projects', 'consulting', 'logistics',
            'construction', 'civils', 'the', 'and', 'of', 'sa', 'south', 'africa'];

        $words = array_filter(explode(' ', $normalised));
        $specific = array_filter($words, fn ($word) => ! in_array($word, $generic, true) && strlen($word) > 2);

        return $specific !== [] && strlen($normalised) >= 8;
    }

    /** @return array<int,string> normalised director names, lower-cased for a substring test */
    private function directorNames(Supplier $supplier): array
    {
        return SupplierPerson::query()
            ->where('supplier_id', $supplier->id)
            ->whereNull('resigned_at')
            ->get()
            ->map(fn (SupplierPerson $person) => Str::lower(trim($person->name())))
            ->filter(fn ($name) => $name !== '' && $name !== '—' && str_contains($name, ' '))
            ->values()
            ->all();
    }

    private function domain(Supplier $supplier): ?string
    {
        $website = trim((string) $supplier->website_url);

        if ($website === '') {
            return null;
        }

        $host = parse_url(Str::startsWith($website, 'http') ? $website : "https://{$website}", PHP_URL_HOST);
        $host = Str::lower((string) ($host ?: $website));

        return Str::of($host)->replaceFirst('www.', '')->trim()->value() ?: null;
    }

    /** The town or suburb out of the physical address — imprecise, and only ever used as support. */
    private function town(Supplier $supplier): ?string
    {
        $address = trim((string) $supplier->physical_address);

        if ($address === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $address) ?: [])));

        // The last line is usually the country or a postal code; the one before it is the town.
        $candidate = $parts === [] ? null : Str::lower((string) ($parts[count($parts) - 2] ?? end($parts)));

        return $candidate && strlen($candidate) > 3 && ! preg_match('/^\d+$/', $candidate) ? $candidate : null;
    }
}
