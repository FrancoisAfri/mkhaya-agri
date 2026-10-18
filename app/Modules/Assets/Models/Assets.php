<?php

namespace App\Modules\Assets\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Hr\Models\LicensesType;
use App\Shared\Traits\Uuids;
use Database\Factories\Assets\AssetFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Assets extends Model
{
    use HasDocuments;

    use SoftDeletes;
    use Uuids, LogsActivity, SoftDeletes, HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetFactory::new();
    }

    /**
     * @var string
     */
    public $table = 'assets';


    /**
     * @var string[]
     */
    protected $hidden = [
        'id'
    ];

    /**
     * @var string[]
     */
    protected $fillable = [
        'name', 'description', 'model_number', 'make_number', 'asset_type_id',
        'user_id', 'serial_number', 'asset_tag', 'type', 'license_type_id',
        'picture', 'price', 'status', 'asset_status', 'serial_number', '',
        'asset_category_id', 'location_id', 'asset_number', 'condition',
        'purchase_cost', 'purchase_date', 'warranty_expiry', 'sars_allowance_section',
        'retention_until', 'next_impairment_review_at', 'barcode', 'last_audited_at',
        'asset_make_id', 'asset_model_id',
    ];

    protected $casts = [
        'purchase_date'             => 'date',
        'warranty_expiry'           => 'date',
        'retention_until'           => 'date',
        'next_impairment_review_at' => 'date',
        'last_audited_at'           => 'datetime',
        'purchase_cost'             => 'decimal:2',
        'sars_allowance_section'    => \App\Modules\Assets\Enums\SarsAllowanceSection::class,
    ];


    /**
     * status constants
     */
    const STATUS_SELECT = [
        'In Use' => 'In Use',
        'Un Allocated' => 'Un Allocated',
        'Discarded' => 'Discarded',
        'Missing' => 'Missing',
        'In Store' => 'In Store',
        'Sold' => 'Sold',
    ];

    const ADMIN_STATUS_SELECT = [
        'Discarded' => 'Discarded',
        'Missing' => 'Missing',
        'Sold' => 'Sold',
    ];

    /**
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(AssetMake::class, 'asset_make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'asset_model_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(AssetAssignment::class, 'asset_id')->where('is_current', true);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class, 'asset_id');
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(AssetMaintenanceSchedule::class, 'asset_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(AssetIncident::class, 'asset_id');
    }

    public function digitalTwin(): HasOne
    {
        return $this->hasOne(AssetDigitalTwin::class, 'asset_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(AssetImage::class, 'asset_id')->orderBy('sort_order');
    }

    public function custodyLogs(): HasMany
    {
        return $this->hasMany(AssetCustodyLog::class, 'asset_id')->orderByDesc('occurred_at');
    }

    /**
     * @return BelongsTo
     */
    public function AssetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class, 'asset_type_id')->orderBy('id');
    }

    public function HrPeople(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'user_id')->orderBy('id');
    }

    /**
     * @return BelongsTo
     */
    public function LicenseType(): BelongsTo
    {
        return $this->belongsTo(LicensesType::class, 'license_type_id')->orderBy('id');
    }

    /**
     *
     * @return Relation
     */
    public function store()
    {
        return $this->belongsTo(StoreRoom::class, 'store_id');
    }


    /**
     *
     * @return Relation
     */
    public function AssetTransfare(): Relation
    {
        return $this->hasMany(AssetTransfers::class, 'asset_id');
    }


    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /**
     * @return Assets[]|\Illuminate\Database\Eloquent\Builder[]|Collection|Builder[]|\Illuminate\Support\Collection
     */
    public static function getAssetsTypes()
    {
        return Assets::with('AssetType')
            ->where('status', 1)
            ->get();
    }

    /**
     * @param $status
     * @return Assets[]|\Illuminate\Database\Eloquent\Builder[]|Collection|Builder[]|\Illuminate\Support\Collection
     */
    public static function getAssetsByStatus($status = 'In Use', $asset_type)
    {
        $query = Assets::with('AssetType')
            ->where([
                'asset_status' => $status
            ]);
        // return only from asset type table if  selection from asset type
        if ($asset_type > 0) {
            $query->where('asset_type_id', $asset_type);
        }

        return $query->get();
    }

    public static function getAllAssetsByStatus($status, $type)
    {
        $query = Assets::with('AssetType')
            ->orderBy('id', 'asc');

        if ($status !== 'all') {
            $query->where('asset_status', $status);
        }

        if ($type !== 'All') {
            $query->where('asset_type_id', $type);
        }

        return $query->get();

    }

    /**
     * @param string $uuid
     * @return Assets|Builder|Model
     */
    public static function findByUuid(string $uuid)
    {
        return Assets::with('AssetType')
            ->where(
                [
                    'uuid' => $uuid,
                ]
            )
            ->first();
    }


    /**
     * @param $id
     * @return Assets|Model|Builder|null
     */
    public static function getAssetById($id)
    {
        return (new Assets)->where('id', $id)->first();
    }

	public static function getAssets()
    {
        $query = Assets::with('AssetType')
            ->whereIn('asset_status', array('In Use', 'Un Allocated', 'In Store'));

        return $query->get();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Asset')
            ->logOnly(['name', 'serial_number', 'asset_tag', 'asset_status', 'user_id', 'asset_type_id', 'price'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor = Auth::user()?->name ?? 'System';
        $name  = $this->name ?? 'Unknown asset';
        return match ($eventName) {
            'created' => "{$actor} added asset \"{$name}\" (tag: {$this->asset_tag}).",
            'updated' => "{$actor} updated asset \"{$name}\" — status: {$this->asset_status}.",
            'deleted' => "{$actor} removed asset \"{$name}\" from the system.",
            default   => "{$actor} triggered \"{$eventName}\" on asset \"{$name}\".",
        };
    }

}
