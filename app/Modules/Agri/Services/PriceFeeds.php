<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\MarketPrice;
use App\Modules\Agri\Models\PriceFeed;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Sources;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Commodity prices fetched from a feed the organisation configures — JSE/SAFEX has no free API,
 * so this reads whatever the organisation's data vendor, broker or co-op publishes, as CSV or
 * JSON, through a column mapping:
 *
 *     {"list": "data.rows",                        JSON only: where the rows are
 *      "delimiter": ",",                           CSV only
 *      "columns": {"date": "Date", "product": "Contract", "contract_month": "Expiry",
 *                  "price": "MTM", "price_type": null, "grade": null, "currency": null, "unit": null},
 *      "products": {"WMAZ": {"product": "White maize", "crop_code": "MAIZE-W"}},
 *      "price_multiplier": 1,
 *      "date_format": "d/m/Y"}
 *
 * A column is named by its header (CSV) or its path (JSON), or by its position (CSV, from 0).
 * What the feed leaves out comes from the feed itself (market, product, crop, currency, unit,
 * price type). Each row is stored once per day, product, market, type and contract month; fetched
 * again, it is updated, not duplicated.
 *
 * As for every outside source (Manufacturing's Sources): the credentials are encrypted, a fetch
 * that fails backs off and marks the feed failing (its prices stay, with their dates), the body is
 * size-capped, and the text it carries is cleaned and checked for instructions — a row that reads
 * like one is kept as text and flagged, never acted on. Fetches run through the integration log,
 * so each one is visible and retried there.
 */
class PriceFeeds
{
    public const MAX_BYTES = 5_000_000;

    public const ADAPTERS = ['csv', 'json'];

    public const COLUMNS = ['date', 'product', 'contract_month', 'price', 'price_type', 'grade', 'currency', 'unit', 'market'];

    public function __construct(private readonly Sources $sources) {}

    public function save(int $companyId, array $data, ?PriceFeed $feed, User $actor): PriceFeed
    {
        if (! in_array($data['adapter'] ?? null, self::ADAPTERS, true)) {
            throw ValidationException::withMessages(['adapter' => 'A feed is CSV or JSON.']);
        }
        if (! preg_match('#^https?://#i', (string) ($data['url'] ?? ''))) {
            throw ValidationException::withMessages(['url' => 'The feed is fetched from an http(s) address.']);
        }
        $config = (array) ($data['config'] ?? []);
        if (empty($config['columns']['price']) && ($config['columns']['price'] ?? null) !== 0 && ($config['columns']['price'] ?? null) !== '0') {
            throw ValidationException::withMessages(['config' => 'Map at least the price column (and the date, unless every row is today\'s).']);
        }
        $feed ??= new PriceFeed(['company_id' => $companyId, 'created_by' => $actor->id, 'status' => 'active']);
        $feed->fill(array_intersect_key($data, array_flip(['name', 'adapter', 'url', 'market', 'product', 'crop_type_id', 'currency', 'unit', 'price_type', 'refresh_minutes'])));
        $feed->config = $config;
        if (array_key_exists('auth', $data)) {
            $feed->putAuth($data['auth']);
        }
        if (! empty($data['paused'])) {
            $feed->status = 'paused';
        } elseif ($feed->status === 'paused') {
            $feed->status = 'active';
        }
        $feed->next_attempt_at = null;
        $feed->save();

        return $feed;
    }

    /** Feeds due a fetch: never fetched, or past their next attempt. */
    public function due(int $companyId)
    {
        return PriceFeed::withoutGlobalScopes()->where('company_id', $companyId)->where('status', '!=', 'paused')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->get();
    }

    /**
     * Through the integration log: once per feed per day for the schedule, or at once with `force`.
     * Hedges are marked against the new prices afterwards.
     *
     * @return array{ok: bool, items: int, error?: string}
     */
    public function run(PriceFeed $feed, bool $force = false): array
    {
        $key = "feed:{$feed->id}:".($force ? 'now:'.now()->format('Y-m-d\TH:i:s').':'.\Illuminate\Support\Str::random(6) : today()->toDateString());
        ['event' => $e] = app(Integrations::class)->receive((int) $feed->company_id, 'price_feed', $key, ['feed_id' => $feed->id]);
        if ($e->status !== 'processed') {
            return ['ok' => false, 'items' => 0, 'error' => (string) $e->error];
        }

        return ['ok' => true, 'items' => (int) ($e->result['items'] ?? 0)];
    }

    /** The integration handler: fetch, store, mark the hedges. A failure throws, so the log retries it. */
    public function handle(array $payload, IntegrationEvent $e): array
    {
        $feed = PriceFeed::withoutGlobalScopes()->where('company_id', $e->company_id)->findOrFail($payload['feed_id'] ?? 0);
        $r = $this->fetch($feed);
        if (! $r['ok']) {
            throw new RuntimeException($r['error'] ?? 'The feed could not be fetched.');
        }
        $marked = app(Hedging::class)->markAll((int) $feed->company_id);

        return ['items' => $r['items'], 'flagged' => $r['flagged'] ?? 0, 'skipped' => $r['skipped'] ?? 0, 'hedges_marked' => $marked];
    }

    /**
     * Fetch a feed now and store what it publishes, recording how it went on the feed.
     *
     * @return array{ok: bool, items: int, skipped?: int, flagged?: int, error?: string}
     */
    public function fetch(PriceFeed $feed): array
    {
        if ($feed->status === 'paused') {
            return ['ok' => false, 'items' => 0, 'error' => 'The feed is paused.'];
        }
        $now = now();
        $feed->update(['last_attempt_at' => $now]);
        try {
            $rows = $this->rows($feed, $this->download($feed));
            $items = $skipped = $flagged = 0;
            foreach ($rows as $raw) {
                $attrs = $this->normalise($feed, $raw);
                if (! $attrs) {
                    $skipped++;
                    continue;
                }
                $p = $this->store($feed, $attrs);
                $items++;
                $flagged += $p->flagged ? 1 : 0;
            }
            if ($rows && ! $items) {
                throw new RuntimeException('The feed answered, but no row had a usable date and price: check the column mapping.');
            }
            $feed->update(['status' => 'active', 'last_success_at' => $now, 'failure_count' => 0, 'last_error' => null, 'last_items' => $items,
                'next_attempt_at' => $now->copy()->addMinutes(max(60, (int) $feed->refresh_minutes))]);

            return ['ok' => true, 'items' => $items, 'skipped' => $skipped, 'flagged' => $flagged];
        } catch (\Throwable $x) {
            $failures = (int) $feed->failure_count + 1;
            $feed->update(['failure_count' => $failures, 'status' => 'failing', 'last_error' => mb_strimwidth($x->getMessage(), 0, 290),
                'next_attempt_at' => $now->copy()->addMinutes(min(360, 2 ** min($failures, 9)))]);

            return ['ok' => false, 'items' => 0, 'error' => $x->getMessage()];
        }
    }

    public function download(PriceFeed $feed): string
    {
        $request = Http::timeout(20)->withHeaders(['User-Agent' => 'Mkhaya Agri price feed', 'Accept' => $feed->adapter === 'json' ? 'application/json' : 'text/csv, text/plain, */*']);
        $auth = $feed->auth();
        $query = [];
        match ($auth['type'] ?? null) {
            'header' => $request = $request->withHeaders([$auth['name'] ?? 'Authorization' => $auth['value'] ?? '']),
            'bearer' => $request = $request->withToken($auth['value'] ?? ''),
            'basic' => $request = $request->withBasicAuth($auth['username'] ?? '', $auth['password'] ?? ''),
            'query' => $query = [$auth['name'] ?? 'key' => $auth['value'] ?? ''],
            default => null,
        };
        $response = $request->get($feed->url, $query);
        if (! $response->successful()) {
            throw new RuntimeException("{$feed->url} answered {$response->status()}.");
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('The feed is larger than '.(self::MAX_BYTES / 1_000_000).' MB.');
        }

        return $body;
    }

    /** The rows of a body as associative arrays (CSV: by header and by position). @return list<array> */
    public function rows(PriceFeed $feed, string $body): array
    {
        $config = (array) ($feed->config ?? []);

        return $feed->adapter === 'json' ? $this->jsonRows($body, $config) : $this->csvRows($body, $config);
    }

    /** @return list<array> */
    public function csvRows(string $body, array $config): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $lines = preg_split('/\r\n|\n|\r/', trim($body)) ?: [];
        $delimiter = (string) ($config['delimiter'] ?? '');
        if ($delimiter === '') {
            $first = $lines[0] ?? '';
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        }
        $lines = array_slice($lines, (int) ($config['skip_rows'] ?? 0));
        $header = array_map(fn ($h) => trim((string) $h), str_getcsv((string) array_shift($lines), $delimiter));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($cells as $i => $v) {
                $row[(string) $i] = trim((string) $v);
                if (isset($header[$i]) && $header[$i] !== '') {
                    $row[$header[$i]] = trim((string) $v);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<array> */
    public function jsonRows(string $body, array $config): array
    {
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $list = ! empty($config['list']) ? data_get($data, $config['list'], []) : $data;

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** One row as market-price attributes, or null when it has no usable price or date. */
    public function normalise(PriceFeed $feed, array $row): ?array
    {
        $config = (array) ($feed->config ?? []);
        $col = function (string $name) use ($config, $row) {
            $from = $config['columns'][$name] ?? null;
            if ($from === null || $from === '') {
                return null;
            }
            $v = array_key_exists((string) $from, $row) ? $row[(string) $from] : data_get($row, (string) $from);

            return is_scalar($v) ? trim((string) $v) : null;
        };

        $price = $this->number($col('price'));
        if ($price === null) {
            return null;
        }
        $price = round($price * (float) ($config['price_multiplier'] ?? 1), 4);
        if ($price <= 0) {
            return null;
        }
        $rawDate = $col('date');
        $date = $rawDate === null ? today() : $this->date($rawDate, $config['date_format'] ?? null);
        if (! $date || $date->isAfter(today())) {
            return null;                                                  // an actual price is never in the future
        }

        // The feed's product code can map to a product name and a crop.
        $code = $col('product');
        $map = $code !== null ? (($config['products'] ?? [])[$code] ?? null) : null;
        $product = $map['product'] ?? ($code ?: $feed->product);
        $cropTypeId = $feed->crop_type_id;
        if (! empty($map['crop_code'])) {
            $cropTypeId = CropType::withoutGlobalScopes()->where('company_id', $feed->company_id)->where('code', $map['crop_code'])->value('id') ?? $cropTypeId;
        }
        if (! $product) {
            return null;
        }
        $type = mb_strtolower((string) ($col('price_type') ?: ($map['price_type'] ?? $feed->price_type)));
        $type = in_array($type, ['spot', 'futures', 'cash'], true) ? $type : $feed->price_type;
        $month = self::contractMonth($col('contract_month'));
        $text = implode(' ', array_filter([$code, $product, $col('grade'), $col('market')]));

        return [
            'product' => $this->sources->clean((string) $product, 120), 'crop_type_id' => $cropTypeId, 'grade' => ($g = $col('grade')) ? $this->sources->clean($g, 40) : null,
            'market' => ($m = $col('market')) ? $this->sources->clean($m, 120) : $feed->market, 'region' => null,
            'price_date' => $date->toDateString(), 'price' => $price,
            'currency' => strtoupper(mb_substr((string) ($col('currency') ?: $feed->currency), 0, 3)), 'unit' => mb_substr((string) ($col('unit') ?: $feed->unit), 0, 12),
            'kind' => 'actual', 'price_type' => $type, 'contract_month' => $type === 'futures' ? $month : null,
            'source' => mb_strimwidth($feed->name, 0, 160), 'source_url' => mb_strimwidth($feed->url, 0, 300), 'feed_id' => $feed->id,
            'flagged' => (bool) $this->sources->flags($text),
        ];
    }

    /** Stored once per feed, day, product, market, type and month: fetched again, updated. */
    public function store(PriceFeed $feed, array $attrs): MarketPrice
    {
        $external = sha1(implode('|', [$attrs['product'], $attrs['market'], $attrs['price_type'], $attrs['contract_month'], $attrs['grade'], $attrs['price_date']]));

        return MarketPrice::withoutGlobalScopes()->updateOrCreate(['company_id' => $feed->company_id, 'feed_id' => $feed->id, 'external_id' => $external],
            $attrs + ['company_id' => $feed->company_id]);
    }

    /**
     * A futures month in any of the usual spellings, as YYYY-MM: "JUL27", "Jul 2027", "2027-07",
     * "2027/07", "07/2027", "2027-07-18", "18 Jul 2027".
     */
    public static function contractMonth(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        $months = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
        $u = strtoupper($s);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})([-\/.]\d{1,2})?$/', $u, $m)) {
            return sprintf('%04d-%02d', $m[1], $m[2]);
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{4})$/', $u, $m)) {
            return sprintf('%04d-%02d', $m[2], $m[1]);
        }
        if (preg_match('/(?:\d{1,2}\s*)?([A-Z]{3})[A-Z]*[\s\-\/]*(\d{2}|\d{4})$/', $u, $m) && isset($months[$m[1]])) {
            $year = strlen($m[2]) === 2 ? 2000 + (int) $m[2] : (int) $m[2];

            return sprintf('%04d-%02d', $year, $months[$m[1]]);
        }

        return null;
    }

    private function date(string $raw, ?string $format): ?Carbon
    {
        try {
            if ($format) {
                $d = Carbon::createFromFormat($format, $raw);

                return $d ? $d->startOfDay() : null;
            }
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m)) {     // dd/mm/yyyy, as South African feeds write it
                return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
            }

            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(?string $v): ?float
    {
        if ($v === null) {
            return null;
        }
        $v = str_replace([' ', "\u{00A0}", 'R'], '', $v);
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $v)) {                        // 4.125,50
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (preg_match('/^-?\d+,\d+$/', $v)) {                                // 4125,50
            $v = str_replace(',', '.', $v);
        } else {
            $v = str_replace(',', '', $v);                                          // 4,125.50
        }

        return is_numeric($v) ? (float) $v : null;
    }
}
