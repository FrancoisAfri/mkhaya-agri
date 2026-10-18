<?php
namespace App\Modules\Policies\Models;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class PolicyClause extends Model {
    use SoftDeletes;
    protected $fillable = ['policy_id','version_id','document_id','clause_reference','type','content',
        'ai_interpretation','extracted_entities','ai_extracted','approved','approved_by','approved_at','sort_order'];
    protected $casts = ['extracted_entities'=>'array','ai_extracted'=>'boolean','approved'=>'boolean','approved_at'=>'datetime'];
    public function policy(): BelongsTo { return $this->belongsTo(Policy::class); }
    public function version(): BelongsTo { return $this->belongsTo(PolicyVersion::class, 'version_id'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function rules(): HasMany { return $this->hasMany(PolicyRule::class,'clause_id'); }
}
