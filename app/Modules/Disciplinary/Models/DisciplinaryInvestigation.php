<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DisciplinaryInvestigation extends Model
{
    use SoftDeletes;

    protected $table = 'disciplinary_investigations';

    protected $fillable = [
        'case_id', 'investigator_id', 'status', 'objective', 'scope',
        'checklist', 'start_date', 'due_date', 'completed_date',
        'findings', 'recommended_action', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'checklist'      => 'array',
        'start_date'     => 'date',
        'due_date'       => 'date',
        'completed_date' => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            $model->uuid = (string) Str::uuid();
            $model->investigation_number = static::generateNumber();
        });
    }

    private static function generateNumber(): string
    {
        $prefix = 'INV-' . date('Y') . '-';
        $last   = static::withTrashed()->where('investigation_number', 'like', $prefix . '%')
            ->orderByDesc('id')->value('investigation_number');
        $seq = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DisciplinaryInvestigationNote::class, 'investigation_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(DisciplinaryInvestigationReport::class, 'investigation_id');
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'completed'   => 'success',
            'in_progress' => 'primary',
            'suspended'   => 'warning',
            default       => 'grey',
        };
    }

    public function checklistProgress(): array
    {
        if (empty($this->checklist)) {
            return ['total' => 0, 'done' => 0, 'pct' => 0];
        }
        $total = count($this->checklist);
        $done  = collect($this->checklist)->filter(fn($i) => $i['done'] ?? false)->count();
        return ['total' => $total, 'done' => $done, 'pct' => $total ? round($done / $total * 100) : 0];
    }
}
