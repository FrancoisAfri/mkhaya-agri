<?php
namespace App\Modules\Policies\Models;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PolicyViolation extends Model {
    use SoftDeletes;
    use HasFactory, LogsActivity;

    protected static function newFactory(): \Database\Factories\PolicyViolationFactory
    {
        return \Database\Factories\PolicyViolationFactory::new();
    }

    protected $fillable = ['policy_id','rule_id','clause_id','event_id','user_id','department_id',
        'severity','outcome','event_snapshot','explanation','status','investigator_id','resolved_at'];
    protected $casts = ['event_snapshot'=>'array','resolved_at'=>'datetime'];
    public function policy(): BelongsTo { return $this->belongsTo(Policy::class); }
    public function rule(): BelongsTo { return $this->belongsTo(PolicyRule::class); }
    public function clause(): BelongsTo { return $this->belongsTo(PolicyClause::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function investigator(): BelongsTo { return $this->belongsTo(User::class, 'investigator_id'); }
    public function notes(): HasMany { return $this->hasMany(ViolationNote::class,'violation_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('PolicyViolation')
            ->logOnly(['policy_id', 'severity', 'outcome', 'status', 'user_id', 'investigator_id', 'resolved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor = Auth::user()?->name ?? 'System';
        return match ($eventName) {
            'created' => "{$actor} recorded a new policy violation (severity: {$this->severity}).",
            'updated' => "{$actor} updated violation #{$this->id} — status: {$this->status}.",
            'deleted' => "{$actor} deleted violation #{$this->id}.",
            default   => "{$actor} triggered \"{$eventName}\" on violation #{$this->id}.",
        };
    }
}
