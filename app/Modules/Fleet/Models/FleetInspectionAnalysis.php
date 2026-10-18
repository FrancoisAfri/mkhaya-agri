<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetInspectionAnalysis extends Model
{
    protected $table = 'fleet_inspection_analyses';

    protected $fillable = [
        'inspection_id', 'booking_id', 'photo_path', 'analysis_type',
        'reference_photo_path', 'damage_detected', 'damage_type', 'confidence',
        'bounding_box', 'new_damage_detected', 'ai_description',
        'confirmed_by', 'confirmed_at', 'is_confirmed_correct', 'guard_label',
    ];

    protected $casts = [
        'damage_detected'     => 'boolean',
        'new_damage_detected' => 'boolean',
        'is_confirmed_correct'=> 'boolean',
        'confidence'          => 'decimal:3',
        'bounding_box'        => 'array',
        'confirmed_at'        => 'datetime',
    ];

    public function booking(): BelongsTo       { return $this->belongsTo(FleetBooking::class); }
    public function confirmedBy(): BelongsTo   { return $this->belongsTo(User::class, 'confirmed_by'); }

    public function isPendingReview(): bool
    {
        return $this->damage_detected && is_null($this->confirmed_by);
    }
}
