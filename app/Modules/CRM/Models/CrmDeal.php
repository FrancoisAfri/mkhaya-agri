<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use App\Shared\Traits\KeepsDatabaseDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmDeal extends Model
{

    use SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'crm_deals';

    /** URLs reference the uuid, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'attribution', 'click_id', 'marketing_campaign_id',
        'uuid', 'title', 'account_id', 'contact_id', 'pipeline_id', 'stage_id', 'amount', 'currency',
        'status', 'probability', 'expected_close_date', 'closed_at', 'source', 'risk_level',
        'risk_reason', 'lost_reason', 'lost_category', 'lost_ai_advice', 'stage_changed_at', 'owner_id', 'notes', 'created_by',
        'ai_win_probability', 'ai_win_reason', 'ai_win_at', 'crm_import_id', 'product_id',
        'hold_reason', 'hold_until', 'held_at', 'held_by', 'hold_stage_id', 'hold_sequence_id', 'hold_woken_at',
    ];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CrmProduct::class, 'product_id');
    }

    protected $casts = [
        'attribution' => 'array',
        'amount'              => 'decimal:2',
        'probability'         => 'integer',
        'expected_close_date' => 'date',
        'closed_at'           => 'datetime',
        'stage_changed_at'    => 'datetime',
        'hold_until'          => 'date',
        'held_at'             => 'datetime',
        'hold_woken_at'       => 'datetime',
    ];

    /*
     * KeepsDatabaseDefaults is what lets a deal be raised before anybody knows what it is worth.
     * `crm_deals.amount` is NOT NULL DEFAULT 0, and a default protects an insert that omits the
     * column — not one that names it and passes null, which is what an optional form field does
     * every time it is left blank. Adding a deal from an account page was a 500 because of it.
     *
     * On the model rather than in the controller because deals are raised from three places: the
     * board, lead conversion and the workflow engine.
     */

    const STATUSES = [
        'open'    => ['label' => 'Open',    'color' => 'primary'],
        'on_hold' => ['label' => 'On Hold', 'color' => 'warning'],
        'won'     => ['label' => 'Won',     'color' => 'success'],
        'lost'    => ['label' => 'Lost',    'color' => 'error'],
    ];

    /**
     * On hold is a status, not a stage, and that distinction is the whole design.
     *
     * A stage would have to be added to every pipeline, would carry a probability the forecast
     * would then weight, and would lose the deal's real position the moment it was moved. As a
     * status it drops out of every `where('status', 'open')` in the module — forecast, dashboard,
     * My Day, data hygiene — without one of them being edited, while `stage_id` stays exactly
     * where it was.
     */
    public function scopeOnHold(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'on_hold');
    }

    /** Held deals whose return date has arrived and that the wake-up has not yet handled. */
    public function scopeDueBack(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'on_hold')
            ->whereNotNull('hold_until')
            ->whereDate('hold_until', '<=', now()->toDateString())
            ->whereNull('hold_woken_at');
    }

    public function holdStage(): BelongsTo
    {
        return $this->belongsTo(CrmStage::class, 'hold_stage_id');
    }

    public function holdSequence(): BelongsTo
    {
        return $this->belongsTo(CrmSequence::class, 'hold_sequence_id');
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmStage::class, 'stage_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'related_id')->where('related_type', 'deal');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
