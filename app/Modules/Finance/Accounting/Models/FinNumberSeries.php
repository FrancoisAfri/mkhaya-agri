<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use Illuminate\Support\Facades\DB;

/**
 * Document numbering, per business (journals, invoices, receipts…).
 *
 * Each of a tenant's trading businesses numbers its own documents from 1. Mkhaya MK's INV-000042
 * and Mkhaya Move's INV-000042 are two different invoices, issued by two different businesses,
 * and neither may skip a number because the other used it — a gap in an invoice sequence is
 * something SARS asks about.
 *
 * The entity scope on this model is what makes that true: the lookup below sees only the current
 * business's series, and a business with no series row yet gets its own, starting at 1.
 */
class FinNumberSeries extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_number_series';

    protected $fillable = ['entity_id', 'company_id', 'doc_type', 'prefix', 'next_number'];

    /**
     * Atomically reserve and return the next formatted number for a doc type.
     * Uses a row lock so concurrent posts never collide.
     *
     * The business is taken from the request rather than passed: every caller here already has a
     * company to hand and none of them has an entity, and a parameter that every call site would
     * fill from the same ambient source is a parameter that eventually gets filled wrongly at one
     * of them. `$entityId` is there for the console and for tests, which have no request.
     */
    /** @var list<string>|null */
    private static ?array $numberedTables = null;

    public static function next(?int $companyId, string $docType, string $prefix = '', ?int $entityId = null): string
    {
        $entities = app(CurrentEntity::class);

        /*
         * Resolved exactly the way the row will be stamped, and that is the whole point.
         *
         * The lookup below and BelongsToEntity's stamping have to agree on which business this
         * series belongs to. If the lookup asks for "no business" and the stamp writes the
         * company's default, the lookup never finds the row it just made: the next call creates it
         * again, and lands on the duplicate key. Before that key included the business it would
         * not even have failed — it would have handed out document number 1 twice.
         *
         * So: the business in context, else the one this company defaults to. The second half is
         * what a console post has, and a console post is exactly where this went wrong.
         */
        $entityId = $entityId
            ?? $entities->id()
            ?? ($companyId !== null ? $entities->defaultFor($companyId)?->id : null);

        return DB::transaction(function () use ($companyId, $docType, $prefix, $entityId) {
            /*
             * The entity is named here, so the ambient scope must not also apply.
             *
             * Left on, the scope adds "entity = whichever business the request is in", which an
             * explicit `$entityId` for a different business then contradicts: the two conditions
             * cannot both hold, the lookup finds nothing, and the series is created again — the
             * second time onto a duplicate key. Worse, before the key was widened it would not
             * have failed at all; it would have handed out number 1 twice.
             */
            $series = static::withoutEntityScope()
                ->where('company_id', $companyId)->where('doc_type', $docType)
                ->when($entityId !== null,
                    fn ($q) => $q->where('entity_id', $entityId),
                    fn ($q) => $q->whereNull('entity_id'))
                ->lockForUpdate()->first();

            if (! $series) {
                $usePrefix = $prefix ?: (strtoupper(substr($docType, 0, 2)) . '-');

                $series = static::create([
                    'company_id'  => $companyId,
                    'entity_id'   => $entityId,
                    'doc_type'    => $docType,
                    'prefix'      => $usePrefix,
                    'next_number' => self::carryOnFrom($usePrefix, $companyId, $entityId),
                ]);
            }

            $number = $series->next_number;
            $series->increment('next_number');

            return $series->prefix . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Pull every existing series up past the documents its business already holds.
     *
     * A series only consults the books when it is created. That is enough while documents arrive
     * one at a time, and wrong the moment a batch of them is adopted into a business afterwards:
     * the series still points at 2 while the business now holds QUO-000115, and the next three
     * documents reuse numbers a client has already seen.
     *
     * @return array<string, array{from: int, to: int}> what moved, keyed by prefix
     */
    public static function catchUp(?int $companyId, ?int $entityId): array
    {
        $moved = [];

        $series = static::withoutEntityScope()
            ->where('company_id', $companyId)
            ->when($entityId !== null,
                fn ($q) => $q->where('entity_id', $entityId),
                fn ($q) => $q->whereNull('entity_id'))
            ->get();

        foreach ($series as $row) {
            $shouldBe = self::carryOnFrom($row->prefix, $companyId, $entityId);

            // Only ever forwards. Winding a series back would reissue numbers.
            if ($shouldBe > $row->next_number) {
                $moved[$row->prefix] = ['from' => (int) $row->next_number, 'to' => $shouldBe];

                $row->next_number = $shouldBe;
                $row->save();
            }
        }

        return $moved;
    }

    /**
     * Where a brand-new series should start, which is rarely one.
     *
     * A business acquires a series row the first time it issues a document — but it may already
     * own documents, because the books it inherited were renumbered into it when businesses were
     * introduced. Starting at one then hands out QUO-000001 to a business whose last quote was
     * QUO-000115: the new document sorts to the bottom of the list, looks like a duplicate of
     * nothing, and the sequence a client sees goes backwards.
     *
     * So the series carries on from the highest number already issued under its own prefix. The
     * tables are found by their shape rather than listed, because a list of document tables is
     * right on the day it is written — and this already covers quotes, invoices, credit notes,
     * journals, delivery notes, claims and whatever is added next, without being told about them.
     *
     * This runs once per business per document type, at the moment the series is created.
     */
    private static function carryOnFrom(string $prefix, ?int $companyId, ?int $entityId): int
    {
        $highest = 0;
        $schema = DB::connection()->getSchemaBuilder();
        $from = strlen($prefix) + 1;

        foreach (self::tablesThatNumberTheirDocuments() as $table) {
            $columns = $schema->getColumnListing($table);

            $max = DB::table($table)
                ->where('number', 'like', $prefix.'%')
                ->when(in_array('company_id', $columns, true),
                    fn ($q) => $q->where('company_id', $companyId))
                ->when(in_array('entity_id', $columns, true) && $entityId !== null,
                    fn ($q) => $q->where('entity_id', $entityId))
                // Only our own shape: PREFIX followed by digits. A reference somebody typed by
                // hand must not push the sequence into the millions.
                //
                // The offset is written into the SQL rather than bound. It is strlen() of a
                // prefix this class chose, never anything a user sent, and mixing bound
                // parameters between the select list and the where clause is a class of bug that
                // is invisible until the numbers come out wrong.
                ->whereRaw("substring(number from {$from}) ~ '^[0-9]+$'")
                ->selectRaw("max(cast(substring(number from {$from}) as bigint)) as n")
                ->value('n');

            $highest = max($highest, (int) $max);
        }

        return $highest + 1;
    }

    /**
     * The finance tables that hold a document number, found once per process.
     *
     * @return list<string>
     */
    private static function tablesThatNumberTheirDocuments(): array
    {
        if (self::$numberedTables !== null) {
            return self::$numberedTables;
        }

        $tables = DB::select(
            "select table_name from information_schema.columns
             where table_schema = 'public' and column_name = 'number' and table_name like 'fin\\_%'"
        );

        return self::$numberedTables = array_column($tables, 'table_name');
    }
}
