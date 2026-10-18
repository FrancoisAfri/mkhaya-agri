<?php
namespace App\Modules\Policies\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class PolicyRule extends Model {
    use HasFactory, SoftDeletes;

    protected static function newFactory(): \Database\Factories\PolicyRuleFactory
    {
        return \Database\Factories\PolicyRuleFactory::new();
    }
    protected $fillable = ['policy_id','clause_id','name','description','natural_language_input',
        'severity','action_on_breach','requires_exception_approval','active','created_by'];
    protected $casts = ['active'=>'boolean','requires_exception_approval'=>'boolean'];
    public function policy(): BelongsTo { return $this->belongsTo(Policy::class); }
    public function clause(): BelongsTo { return $this->belongsTo(PolicyClause::class,'clause_id'); }
    public function conditions(): HasMany { return $this->hasMany(RuleCondition::class,'rule_id'); }
    public function actions(): HasMany { return $this->hasMany(RuleAction::class,'rule_id'); }
    public function eventMaps(): HasMany { return $this->hasMany(EventRuleMap::class,'rule_id'); }
    public function violations(): HasMany { return $this->hasMany(PolicyViolation::class,'rule_id'); }
}
