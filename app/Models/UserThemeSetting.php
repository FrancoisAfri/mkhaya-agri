<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\Models\User;

class UserThemeSetting extends Model
{
    protected $table = 'user_theme_settings';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $casts = ['settings' => 'array'];
    protected $fillable = ['user_id', 'settings'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
