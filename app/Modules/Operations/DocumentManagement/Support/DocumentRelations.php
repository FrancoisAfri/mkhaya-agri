<?php

namespace App\Modules\Operations\DocumentManagement\Support;

use Illuminate\Support\Facades\Schema;

/**
 * The kinds of record a document can be about.
 *
 * A named list rather than "any model", because the relation appears in filters, on screens and in
 * reports, and a value nobody declared is a value nothing can render. Adding one is a line here,
 * not a migration — which is the point of the link table underneath.
 *
 * `model` is the class on the other end. `label` is how the relation reads to a person. `resolve`
 * names the attribute to use for the stored label, so a link shows "Acme Trading (Pty) Ltd" rather
 * than an id, and keeps showing it if that record is later removed.
 */
class DocumentRelations
{
    public const EMPLOYEE = 'employee';
    public const SUPPLIER = 'supplier';
    public const CUSTOMER = 'customer';
    public const CONTRACT = 'contract';
    public const ASSET = 'asset';
    public const PROJECT = 'project';
    public const PROPERTY = 'property';
    public const COMPANY = 'company';
    public const TENDER = 'tender';
    public const PRODUCT = 'product';
    public const QUALITY_INSPECTION = 'quality_inspection';
    public const NONCONFORMANCE = 'nonconformance';
    public const PRODUCTION_ORDER = 'production_order';
    public const RECALL = 'recall';
    public const BOM = 'bom';
    public const ROUTING = 'routing';
    public const LOSS_FINDING = 'loss_finding';
    public const SUBCONTRACT = 'subcontract_order';

    /*
     * The rest of Phase 4. Each is registered only where the module actually exists on this
     * platform: there is no property module here, so §4's "related property/unit" has nowhere to
     * point and is deliberately absent rather than registered against a model nobody has.
     */
    public const VEHICLE = 'vehicle';
    public const CANDIDATE = 'candidate';
    public const PAYROLL_RUN = 'payroll_run';

    /**
     * @return array<string, array{label:string, model:class-string, resolve:list<string>}>
     */
    public static function all(): array
    {
        return array_filter([
            self::EMPLOYEE => [
                'label' => 'Employee',
                'model' => \App\Modules\HR\Hr\Models\HRPerson::class,
                // No `name` column on a person — first name and surname, which is why this is a
                // list rather than a single attribute.
                'resolve' => ['first_name', 'surname'],
                'search' => ['first_name', 'surname', 'employee_number', 'email'],
                'subtitle' => 'employee_number',
            ],
            self::SUPPLIER => [
                'label' => 'Supplier',
                'model' => \App\Modules\SupplierManagement\Models\Supplier::class,
                // company_name, not name — verified against the schema rather than assumed, which
                // is the difference between a readable link and a blank one.
                'resolve' => ['company_name'],
                'search' => ['company_name', 'trading_name', 'supplier_number'],
                'subtitle' => 'supplier_number',
            ],
            self::CUSTOMER => [
                'label' => 'Customer',
                // A customer is a CRM account on this platform; there is no separate customer
                // record, and inventing one here would create a second answer to the same question.
                'model' => \App\Modules\CRM\Models\CrmAccount::class,
                'resolve' => ['name'],
                'search' => ['name'],
            ],
            self::CONTRACT => [
                'label' => 'Contract',
                'model' => \App\Modules\ContractManagement\Models\Contract::class,
                'resolve' => ['title'],
                'search' => ['title', 'contract_number'],
                'subtitle' => 'contract_number',
            ],
            self::ASSET => [
                'label' => 'Asset',
                // Plural, and deliberately so — the model is named for its table.
                'model' => \App\Modules\Assets\Models\Assets::class,
                'resolve' => ['name'],
                'search' => ['name', 'asset_code', 'serial_number'],
                'subtitle' => 'asset_code',
            ],
            self::PRODUCT => [
                'label' => 'Product',
                // A manufactured product's engineering record: its drawings, work instructions,
                // specifications and images. The name lives on the finance stock item it extends,
                // so the label is a computed attribute and the picker has no column of its own to
                // search -- it is reached from the product's page, which is where this is used.
                'model' => \App\Modules\Operations\Manufacturing\Models\Item::class,
                'resolve' => ['display_name'],
                'search' => [],
            ],
            // Quality evidence: certificates, test reports and release records filed against the
            // inspection they prove, and investigation evidence against its non-conformance.
            self::QUALITY_INSPECTION => [
                'label' => 'Quality inspection',
                'model' => \App\Modules\Operations\Manufacturing\Models\Inspection::class,
                'resolve' => ['number'],
                'search' => ['number'],
            ],
            // Attachments from the floor and the office: photos, signed sheets, customer drawings.
            self::PRODUCTION_ORDER => [
                'label' => 'Production order',
                'model' => \App\Modules\Operations\Manufacturing\Models\ProductionOrder::class,
                'resolve' => ['number'],
                'search' => ['number'],
            ],
            // Notices to customers and regulators, retrieval and disposal evidence.
            self::RECALL => [
                'label' => 'Product recall',
                'model' => \App\Modules\Operations\Manufacturing\Models\Recall::class,
                'resolve' => ['number'],
                'search' => ['number'],
            ],
            // MFG-023: approved recipes and routing instructions carry their drawings, specifications
            // and work instructions; loss investigations their evidence; subcontract orders the
            // supplier's paperwork (delivery notes, certificates of conformance).
            self::BOM => [
                'label' => 'Bill of materials',
                'model' => \App\Modules\Operations\Manufacturing\Models\Bom::class,
                'resolve' => ['revision'],
                'search' => [],
            ],
            self::ROUTING => [
                'label' => 'Routing',
                'model' => \App\Modules\Operations\Manufacturing\Models\Routing::class,
                'resolve' => ['revision'],
                'search' => [],
            ],
            self::LOSS_FINDING => [
                'label' => 'Loss investigation',
                'model' => \App\Modules\Operations\Manufacturing\Models\LossFinding::class,
                'resolve' => ['number'],
                'search' => ['number'],
            ],
            self::SUBCONTRACT => [
                'label' => 'Subcontract order',
                'model' => \App\Modules\Operations\Manufacturing\Models\SubcontractOrder::class,
                'resolve' => ['number'],
                'search' => ['number'],
            ],
            self::NONCONFORMANCE => [
                'label' => 'Non-conformance',
                'model' => \App\Modules\Operations\Manufacturing\Models\Nonconformance::class,
                'resolve' => ['number'],
                'search' => ['number'],
            ],
            self::PROJECT => [
                'label' => 'Project',
                'model' => \App\Modules\Operations\ProjectManagement\Models\Project::class,
                'resolve' => ['name'],
                'search' => ['name', 'code'],
                'subtitle' => 'code',
            ],
            /*
             * Property is declared but has no module yet.
             *
             * Left in place rather than dropped: the filter below removes relations whose model is
             * absent, so this costs nothing today and the day a property module lands its documents
             * link without anyone having to remember this file exists.
             */
            self::PROPERTY => [
                'label' => 'Property / Unit',
                'model' => \App\Modules\Property\Models\Property::class,
                'resolve' => ['name'],
                'search' => ['name'],
            ],
            self::TENDER => [
                'label' => 'Tender',
                'model' => \App\Modules\TenderPursuit\Models\Tender::class,
                'resolve' => ['name'],
                'search' => ['name', 'tender_number'],
                'subtitle' => 'tender_number',
            ],
            self::VEHICLE => [
                'label' => 'Vehicle',
                'model' => \App\Modules\Fleet\Models\FleetVehicle::class,
                // Registration first: it is what is written on the licence disc somebody is holding
                // when they come looking for the document.
                'resolve' => ['registration_number'],
                'search' => ['registration_number', 'fleet_number', 'make', 'model'],
                'subtitle' => 'fleet_number',
            ],
            self::CANDIDATE => [
                'label' => 'Candidate',
                'model' => \App\Modules\HR\Recruitment\Models\Candidate::class,
                // last_name, not surname — the recruitment module names it differently from HR, and
                // assuming otherwise produces a link that reads as a first name alone.
                'resolve' => ['first_name', 'last_name'],
                'search' => ['first_name', 'last_name', 'email', 'id_number'],
                'subtitle' => 'email',
            ],
            self::PAYROLL_RUN => [
                'label' => 'Payroll run',
                'model' => \App\Modules\HR\Payroll\Models\PayrollRun::class,
                'resolve' => ['name'],
                'search' => ['name'],
            ],
            self::COMPANY => [
                'label' => 'Company',
                'model' => \App\Modules\Tenancy\Models\Company::class,
                'resolve' => ['name'],
                'search' => ['name'],
            ],
            // A relation whose module is not installed is dropped rather than offered: pointing at
            // a class that does not exist turns a dropdown into a fatal error.
        ], fn (array $relation) => class_exists($relation['model']));
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function isKnown(string $relation): bool
    {
        return array_key_exists($relation, self::all());
    }

    /** @return array{label:string, model:class-string, resolve:list<string>}|null */
    public static function definition(string $relation): ?array
    {
        return self::all()[$relation] ?? null;
    }

    /** The relation a model class belongs to, for linking from the other end. */
    public static function forModel(object|string $model): ?string
    {
        $class = is_object($model) ? $model::class : $model;

        foreach (self::all() as $relation => $definition) {
            if ($definition['model'] === $class || is_subclass_of($class, $definition['model'])) {
                return $relation;
            }
        }

        return null;
    }

    /**
     * A readable name for the thing being linked.
     *
     * Stored on the link rather than looked up each time, so a listing does not query eight other
     * modules to render one page — and so the link still reads sensibly after the record it points
     * at has been deleted.
     */
    public static function labelFor(string $relation, object $record): ?string
    {
        $definition = self::definition($relation);

        if (! $definition) {
            return null;
        }

        $parts = [];

        foreach ($definition['resolve'] as $attribute) {
            $value = $record->{$attribute} ?? null;

            if (filled($value)) {
                $parts[] = (string) $value;
            }
        }

        return $parts === [] ? null : trim(implode(' ', $parts));
    }


    /**
     * Searches one relation's records by name.
     *
     * Columns that do not exist are dropped rather than queried. These are eight different modules
     * written by different people at different times, and a column this file assumed — `code` on a
     * project, `serial_number` on an asset — would otherwise turn the picker into a 500 the first
     * time somebody typed in it.
     *
     * Tenant scoping is left to the models: each carries BelongsToCompany or row-level security, so
     * adding a company_id filter here would be a second, weaker copy of a rule that already holds.
     *
     * @return list<array{id:int, label:string, subtitle:?string}>
     */
    public static function search(string $relation, string $term, int $limit = 20): array
    {
        $definition = self::definition($relation);

        if (! $definition) {
            return [];
        }

        $model = new $definition['model'];
        $columns = array_values(array_filter(
            $definition['search'] ?? $definition['resolve'],
            fn (string $column) => Schema::hasColumn($model->getTable(), $column),
        ));

        if ($columns === []) {
            return [];
        }

        $term = trim($term);

        $query = $model->newQuery()->where(function ($q) use ($columns, $term) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'ilike', '%' . $term . '%');
            }
        });

        $subtitle = ($definition['subtitle'] ?? null);
        $subtitle = $subtitle && Schema::hasColumn($model->getTable(), $subtitle) ? $subtitle : null;

        return $query->limit($limit)->get()
            ->map(fn ($record) => [
                'id' => (int) $record->getKey(),
                'label' => self::labelFor($relation, $record) ?? ('#' . $record->getKey()),
                // The reference number, where there is one — two suppliers called "Acme Trading"
                // are indistinguishable in a dropdown without it.
                'subtitle' => $subtitle ? ($record->{$subtitle} ?? null) : null,
            ])
            ->all();
    }

    /** Relations whose table is actually present, for a settings screen to report honestly. */
    public static function available(): array
    {
        return array_filter(self::all(), function (array $definition) {
            $table = (new $definition['model'])->getTable();

            return Schema::hasTable($table);
        });
    }
}
