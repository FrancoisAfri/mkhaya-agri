<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\AssignmentType;
use App\Shared\Traits\Uuids;
use Database\Factories\Assets\AssetAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetAssignment extends Model
{
    use SoftDeletes;
    use Uuids, HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetAssignmentFactory::new();
    }

    protected $table = 'asset_assignments';

    protected $fillable = [
        'uuid',
        'asset_id',
        'assigned_to',
        'assigned_by',
        'location_id',
        'assignment_type',
        'effective_date',
        'return_due_date',
        'returned_at',
        'is_current',
        'approval_id',
        'notes',
    ];

    protected $casts = [
        'assignment_type' => AssignmentType::class,
        'effective_date'  => 'date',
        'return_due_date' => 'date',
        'returned_at'     => 'datetime',
        'is_current'      => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(AssetApproval::class, 'approval_id');
    }

    public function custodyLogs(): HasMany
    {
        return $this->hasMany(AssetCustodyLog::class, 'assignment_id');
    }
}
