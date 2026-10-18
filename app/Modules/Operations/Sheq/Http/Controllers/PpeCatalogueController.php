<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\PpeService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a company says what PPE it issues and how much of it is on the shelf.
 *
 * This used to be a collapsible panel on the issue register — two forms with no list, no edit and
 * no delete, sat above the thing they configure. That shape has a specific failure: a typo in a
 * catalogue item was permanent, and the only way to see stock was to add another row and hope the
 * upsert matched. Configuration that cannot be corrected stops being maintained.
 *
 * So it is a page of its own, in Configure, where somebody looks when setting the company up —
 * and the register now reads from it rather than carrying it.
 *
 * **Catalogue and stock are deliberately separate.** An item is a kind of thing this company
 * issues ("safety boots, SANS 20345, 24-month life"). Stock is a count of one size of it, at one
 * site. Sized PPE is the reason: one catalogue entry for boots, eleven stock rows for sizes 3 to
 * 13, each with its own reorder level, because running out of size 12 is not the same as running
 * out of boots.
 */
class PpeCatalogueController extends Controller
{
    use ResolvesCompany;

    /** What SANS/ISO-shaped PPE falls under. Kept with the register's list so they cannot drift. */
    private const CATEGORIES = ['HEAD', 'EYE', 'HEARING', 'RESPIRATORY', 'HAND', 'FOOT', 'BODY',
        'FALL_ARREST', 'HIGH_VISIBILITY'];

    public function __construct(private PpeService $ppe) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return Inertia::render('Modules/Sheq/Settings/PpeCatalogue', ['noCompany' => true]);
        }

        return Inertia::render('Modules/Sheq/Settings/PpeCatalogue', [
            'noCompany' => false,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'items' => $this->items($companyId),
            'stock' => $this->stock($companyId),
            'sites' => $this->sites($companyId),
            'categories' => self::CATEGORIES,
            'lowStock' => $this->ppe->lowStock($companyId),
        ]);
    }

    /**
     * The catalogue, each item carrying what it would cost to get rid of it.
     *
     * `issued_count` and `stock_rows` are why: an item somebody wants to delete may have thirty
     * issues hanging off it, and the screen has to be able to say so *before* the click rather
     * than refusing afterwards.
     *
     * @return list<array<string, mixed>>
     */
    private function items(int $companyId): array
    {
        return DB::table('sheq_ppe_items as i')
            ->where('i.company_id', $companyId)
            ->whereNull('i.deleted_at')
            ->leftJoin('sheq_ppe_issues as s', function ($join) {
                $join->on('s.ppe_item_id', '=', 'i.id')->whereNull('s.deleted_at');
            })
            ->leftJoin('sheq_ppe_stock as k', 'k.ppe_item_id', '=', 'i.id')
            ->groupBy('i.id')
            ->orderBy('i.name')
            ->select([
                'i.id', 'i.uuid', 'i.code', 'i.name', 'i.category', 'i.specification',
                'i.lifespan_months', 'i.requires_size', 'i.is_active',
                DB::raw('count(distinct s.id) as issued_count'),
                DB::raw('count(distinct k.id) as stock_rows'),
                DB::raw('coalesce(sum(distinct k.quantity_on_hand), 0) as total_on_hand'),
            ])
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'uuid' => $r->uuid,
                'code' => $r->code,
                'name' => $r->name,
                'category' => $r->category,
                'specification' => $r->specification,
                'lifespan_months' => $r->lifespan_months !== null ? (int) $r->lifespan_months : null,
                'requires_size' => (bool) $r->requires_size,
                'is_active' => (bool) $r->is_active,
                'issued_count' => (int) $r->issued_count,
                'stock_rows' => (int) $r->stock_rows,
                'total_on_hand' => (int) $r->total_on_hand,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> every stock line, with the item and site it belongs to */
    private function stock(int $companyId): array
    {
        return DB::table('sheq_ppe_stock as k')
            ->join('sheq_ppe_items as i', 'i.id', '=', 'k.ppe_item_id')
            ->leftJoin('sheq_sites as t', 't.id', '=', 'k.site_id')
            ->where('k.company_id', $companyId)
            ->whereNull('i.deleted_at')
            ->orderBy('i.name')->orderBy('k.size')
            ->select(['k.uuid', 'k.id', 'k.ppe_item_id', 'k.size', 'k.site_id',
                'k.quantity_on_hand', 'k.reorder_level', 'k.barcode',
                'i.name as item_name', 'i.code as item_code', 'i.requires_size', 't.name as site_name'])
            ->get()
            ->map(fn ($r) => [
                'uuid' => $r->uuid,
                'id' => (int) $r->id,
                'ppe_item_id' => (int) $r->ppe_item_id,
                'item_name' => $r->item_name,
                'item_code' => $r->item_code,
                'requires_size' => (bool) $r->requires_size,
                'size' => $r->size,
                'site_id' => $r->site_id !== null ? (int) $r->site_id : null,
                'site_name' => $r->site_name,
                'quantity_on_hand' => (int) $r->quantity_on_hand,
                'reorder_level' => (int) $r->reorder_level,
                'barcode' => $r->barcode,
                // Computed here rather than in the page: the same rule decides the amber row on
                // screen and the low-stock alert on the register, and two copies would drift.
                'is_low' => (int) $r->quantity_on_hand <= (int) $r->reorder_level,
            ])
            ->all();
    }

    /** @return list<array{id:int,name:string}> */
    private function sites(int $companyId): array
    {
        if (! DB::getSchemaBuilder()->hasTable('sheq_sites')) {
            return [];
        }

        return DB::table('sheq_sites')
            ->where('company_id', $companyId)
            ->when(DB::getSchemaBuilder()->hasColumn('sheq_sites', 'is_active'),
                fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->all();
    }

    // ── Catalogue ────────────────────────────────────────────────────────────────────────────

    public function storeItem(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        $data = $this->validateItem($request, $companyId);

        DB::table('sheq_ppe_items')->insert($data + [
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', __('sheq.ppe_config.flash.item_added'));
    }

    public function updateItem(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        $item = $this->findItem($companyId, $uuid);
        $data = $this->validateItem($request, $companyId, $item->id);

        DB::table('sheq_ppe_items')->where('id', $item->id)
            ->update($data + ['updated_at' => now()]);

        return back()->with('success', __('sheq.ppe_config.flash.item_updated'));
    }

    /**
     * Retire an item rather than delete it, when it has been issued.
     *
     * A retired item disappears from the issue form and keeps every record of what was handed out
     * under it. Deleting it would take the issue history with it, and PPE issue records are exactly
     * what an inspector asks for after an injury.
     */
    public function toggleItem(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        $item = $this->findItem($companyId, $uuid);

        DB::table('sheq_ppe_items')->where('id', $item->id)->update([
            'is_active' => ! $item->is_active,
            'updated_at' => now(),
        ]);

        return back()->with('success', $item->is_active
            ? __('sheq.ppe_config.flash.item_retired')
            : __('sheq.ppe_config.flash.item_restored'));
    }

    /** Delete outright — refused once anything has been issued, for the reason above. */
    public function destroyItem(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        $item = $this->findItem($companyId, $uuid);

        $issued = DB::table('sheq_ppe_issues')
            ->where('ppe_item_id', $item->id)->whereNull('deleted_at')->count();

        if ($issued > 0) {
            return back()->withErrors([
                'item' => __('sheq.ppe_config.rules.item_has_issues', ['count' => $issued]),
            ]);
        }

        DB::transaction(function () use ($item) {
            DB::table('sheq_ppe_stock')->where('ppe_item_id', $item->id)->delete();
            DB::table('sheq_ppe_items')->where('id', $item->id)->update(['deleted_at' => now()]);
        });

        return back()->with('success', __('sheq.ppe_config.flash.item_deleted'));
    }

    // ── Stock ────────────────────────────────────────────────────────────────────────────────

    /**
     * Set the count for one item/size/site.
     *
     * An absolute figure, not a delta, because this is filled in after counting the shelf. A
     * "+10 received" field would be the wrong shape: it invites arithmetic at the exact moment
     * somebody is holding a clipboard and already knows the answer.
     */
    public function storeStock(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        $data = $request->validate([
            'ppe_item_id' => ['required', 'integer',
                Rule::exists('sheq_ppe_items', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'size' => ['nullable', 'string', 'max:20'],
            'site_id' => ['nullable', 'integer'],
            'quantity_on_hand' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'barcode' => ['nullable', 'string', 'max:80'],
        ]);

        $item = DB::table('sheq_ppe_items')->where('id', $data['ppe_item_id'])->first();

        // A sized item with no size would be one anonymous pile that no size can be issued from.
        if ($item?->requires_size && ! filled($data['size'] ?? null)) {
            return back()->withErrors(['size' => __('sheq.ppe_config.rules.size_required')]);
        }

        DB::table('sheq_ppe_stock')->updateOrInsert(
            [
                'company_id' => $companyId,
                'ppe_item_id' => $data['ppe_item_id'],
                // Coalesced, not indexed: validate() returns only the keys that were actually
                // submitted, so an optional field left blank is absent rather than empty.
                'size' => ($data['size'] ?? null) ?: null,
                'site_id' => $data['site_id'] ?? null,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'quantity_on_hand' => $data['quantity_on_hand'],
                'reorder_level' => $data['reorder_level'] ?? 0,
                'barcode' => ($data['barcode'] ?? null) ?: null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return back()->with('success', __('sheq.ppe_config.flash.stock_saved'));
    }

    public function destroyStock(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        $deleted = DB::table('sheq_ppe_stock')
            ->where('company_id', $companyId)->where('uuid', $uuid)->delete();

        abort_if($deleted === 0, 404);

        return back()->with('success', __('sheq.ppe_config.flash.stock_removed'));
    }

    // ── Shared ───────────────────────────────────────────────────────────────────────────────

    private function authoriseConfigure(Request $request): void
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');
    }

    private function findItem(int $companyId, string $uuid): object
    {
        $item = DB::table('sheq_ppe_items')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->whereNull('deleted_at')->first();

        abort_if($item === null, 404);

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Load a catalogue from a spreadsheet.
     *
     * A PPE catalogue arrives as a list — from the supplier, from the safety file, from whatever
     * the previous system exported — and typing eighty items into a dialog one at a time is the
     * reason a catalogue stays half-populated and the issue register stays empty behind it.
     *
     * ── Why it updates rather than refuses ───────────────────────────────────
     *
     * Matched on `code`, and an existing code is updated rather than rejected. The realistic use is
     * not one clean import: it is the supplier list re-sent with three new items and a corrected
     * specification, and a load that fails on "code already exists" makes somebody diff two
     * spreadsheets by hand. Codes are unique per company already, so there is exactly one row to
     * update.
     *
     * ── Why bad rows are skipped, not fatal ──────────────────────────────────
     *
     * Eighty rows with one bad category should load seventy-nine items and say what happened to the
     * eightieth. Refusing the file sends the whole list back over a typo, and the person retries by
     * deleting the row rather than fixing it — so the item quietly never exists.
     */
    public function importItems(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        $request->validate([
            // Small on purpose: a PPE catalogue is a list of items, and anything of this size is a
            // different file that somebody has picked by mistake.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $rows = $this->readCsv($request->file('file')->getRealPath());

        if ($rows === []) {
            return back()->withErrors(['file' => __('sheq.ppe_config.import.empty')]);
        }

        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach ($rows as $line => $row) {
            $data = $this->itemFromRow($row);

            $validator = Validator::make($data, [
                'code' => ['required', 'string', 'max:40'],
                'name' => ['required', 'string', 'max:160'],
                'category' => ['nullable', Rule::in(self::CATEGORIES)],
                'specification' => ['nullable', 'string', 'max:160'],
                'lifespan_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            ]);

            if ($validator->fails()) {
                // The line number as the file shows it, because that is what somebody will go and
                // look at — a row index is a number they then have to translate.
                $skipped[] = ['line' => $line, 'reason' => $validator->errors()->first()];

                continue;
            }

            $existing = DB::table('sheq_ppe_items')
                ->where('company_id', $companyId)
                ->where('code', $data['code'])
                ->whereNull('deleted_at')
                ->first(['id']);

            if ($existing) {
                DB::table('sheq_ppe_items')->where('id', $existing->id)
                    ->update($data + ['updated_at' => now()]);
                $updated++;

                continue;
            }

            DB::table('sheq_ppe_items')->insert($data + [
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return back()
            ->with('success', __('sheq.ppe_config.import.done', [
                'created' => $created, 'updated' => $updated, 'skipped' => count($skipped),
            ]))
            // Named rows rather than a count: "12 skipped" tells nobody which twelve.
            ->with('ppeImportSkipped', array_slice($skipped, 0, 50));
    }

    /**
     * The columns, so nobody has to guess at them.
     *
     * Sent as the file the importer accepts rather than described in help text, because a template
     * that is also a working example is the difference between a load that works first time and
     * three that do not.
     */
    public function importTemplate(Request $request): StreamedResponse
    {
        $this->requireCompany($request);
        $this->authoriseConfigure($request);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['code', 'name', 'category', 'specification', 'lifespan_months', 'requires_size']);
            fputcsv($out, ['BOOT-01', 'Safety boot', 'FOOT', 'SANS 20345 S3', '12', 'yes']);
            fputcsv($out, ['HAT-01', 'Hard hat', 'HEAD', 'SANS 1397', '24', 'no']);

            fclose($out);
        }, 'ppe-catalogue-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * A CSV read by its header row, keyed by the line number it came from.
     *
     * Headers are matched case- and space-insensitively: a file exported from a spreadsheet has
     * "Lifespan Months" where this wants `lifespan_months`, and rejecting it over capitalisation is
     * a rule with no purpose behind it.
     *
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        // A file saved from Excel on Windows starts with a byte-order mark, which otherwise becomes
        // part of the first header and makes `code` unfindable in a file that plainly has one.
        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);

            return [];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);

        $headers = array_map(
            fn ($h) => Str::of($h)->trim()->lower()->replace([' ', '-'], '_')->value(),
            str_getcsv(rtrim($first, "\r\n")),
        );

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;

            // A trailing newline reads as one empty cell; that is the end of the file, not a row.
            if ($values === [null] || $values === ['']) {
                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = isset($values[$i]) ? trim((string) $values[$i]) : '';
            }

            $rows[$line] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /** @param  array<string, string>  $row */
    private function itemFromRow(array $row): array
    {
        return [
            'code' => $row['code'] ?? '',
            'name' => $row['name'] ?? '',
            'category' => Str::upper($row['category'] ?? '') ?: null,
            'specification' => ($row['specification'] ?? '') ?: null,
            'lifespan_months' => ($row['lifespan_months'] ?? '') !== ''
                ? (int) $row['lifespan_months']
                : null,
            // "yes", "y", "true", "1" — a spreadsheet column of ticks arrives as any of them.
            'requires_size' => in_array(
                Str::lower($row['requires_size'] ?? ''),
                ['1', 'y', 'yes', 'true', 't'],
                true,
            ),
        ];
    }

    private function validateItem(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate([
            // Unique per company, not globally: two customers both issuing "BOOT-01" is normal,
            // and the same code twice inside one company makes the issue dropdown ambiguous.
            'code' => ['required', 'string', 'max:40',
                Rule::unique('sheq_ppe_items', 'code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'specification' => ['nullable', 'string', 'max:160'],
            'lifespan_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'requires_size' => ['nullable', 'boolean'],
        ]) + ['requires_size' => (bool) $request->boolean('requires_size')];
    }
}
