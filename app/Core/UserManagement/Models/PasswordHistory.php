<?php

namespace App\Core\UserManagement\Models;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Support\Facades\Auth;

class PasswordHistory extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'password',
    ];

    // ----------------------------
    // RELATIONSHIPS
    // ----------------------------
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ----------------------------
    // CREATE PASSWORD ENTRY
    // ----------------------------
    public static function createPassword(int $userId, string $hashedPassword): self
    {
        return self::create([
            'user_id' => $userId,
            'password' => $hashedPassword,
        ]);
    }

    // ----------------------------
    // SPATIE ACTIVITY LOG
    // ----------------------------
    protected static $logName = 'Password History';
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;
    protected static $logAttributes = ['user_id', 'password'];
    protected static $ignoreChangedAttributes = ['updated_at'];

    public function getDescriptionForEvent(string $eventName): string
    {
        $actor = Auth::check() ? Auth::user()->name : 'System';
        return "Password for user ID {$this->user_id} was {$eventName} by {$actor}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::$logName)
            ->logOnly(self::$logAttributes)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {
                $actor = Auth::check() ? Auth::user()->name : 'System';
                return "Password for user ID {$this->user_id} was {$eventName} by {$actor}";
            });
    }
}
