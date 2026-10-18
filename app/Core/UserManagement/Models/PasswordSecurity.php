<?php

namespace App\Core\UserManagement\Models;

use App\Core\User\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Support\Facades\Auth;

class PasswordSecurity extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'password_expiry_at',
        'force_change',
        'expires_at',
    ];

    protected $casts = [
        'password_expiry_at' => 'datetime',
        'force_change'       => 'boolean',
        'expires_at'         => 'datetime',
    ];

    // ----------------------------
    // RELATIONSHIPS
    // ----------------------------
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ----------------------------
    // CREATE OR UPDATE EXPIRY DATE
    // ----------------------------
    public static function addExpiryDate(int $userId, int $days = 90): self
    {
        return self::updateOrCreate(
            ['user_id' => $userId],
            ['password_expiry_at' => Carbon::now()->addDays($days), 'force_change' => false]
        );
    }

    public static function addExpiryDateFromMonths(int $userId, int $months): self
    {
        return self::updateOrCreate(
            ['user_id' => $userId],
            ['password_expiry_at' => Carbon::now()->addMonths($months), 'force_change' => false]
        );
    }

    public static function applyCompanyPolicy(int $months): void
    {
        \App\Core\User\Models\User::whereNull('deleted_at')
            ->get()
            ->each(function ($user) use ($months) {
                self::updateOrCreate(
                    ['user_id' => $user->id],
                    ['password_expiry_at' => Carbon::now()->addMonths($months)]
                );
            });
    }

    // ----------------------------
    // SPATIE ACTIVITY LOG
    // ----------------------------
    protected static $logName = 'Password Security';
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;
    protected static $logAttributes = ['user_id', 'password_expiry_at'];
    protected static $ignoreChangedAttributes = ['updated_at'];

    public function getDescriptionForEvent(string $eventName): string
    {
        $actor = Auth::check() ? Auth::user()->name : 'System';
        $expiry = $this->password_expiry_at ? $this->password_expiry_at->format('Y-m-d H:i:s') : 'N/A';
        return "Password expiry for user ID {$this->user_id} was {$eventName} by {$actor}, new expiry: {$expiry}";
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
                $expiry = $this->password_expiry_at ? $this->password_expiry_at->format('Y-m-d H:i:s') : 'N/A';
                return "Password expiry for user ID {$this->user_id} was {$eventName} by {$actor}, new expiry: {$expiry}";
            });
    }
}
