<?php
namespace App\Modules\Policies\Models;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PolicyVersion extends Model {
    use SoftDeletes;

    protected $fillable = ['policy_id','version_number','change_summary','snapshot','created_by','published_at'];
    protected $casts = ['snapshot'=>'array','published_at'=>'datetime'];
    public function policy(): BelongsTo { return $this->belongsTo(Policy::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
