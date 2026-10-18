<?php

namespace App\Modules\HR\Contracts\Models;

use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\ContractStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Job;
use Database\Factories\Contracts\ContractFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Contract extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'recruitment_contracts';

    protected $fillable = [
        'offer_id',
        'application_id',
        'job_id',
        'candidate_id',
        'created_by',
        'template_id',
        // NOT: status — set by Action classes only
        //      pdf_path — set by GenerateContractPdfJob
        //      sent_at, fully_signed_at, expires_at — lifecycle timestamps
    ];

    protected $hidden = [
        'content',  // Large HTML — use explicit endpoint to fetch full contract body
        'pdf_path', // Private S3 path — serve via signed URL only
    ];

    protected $casts = [
        'status'          => ContractStatus::class,
        'sent_at'         => 'datetime',
        'expires_at'      => 'datetime',
        'fully_signed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Contract $contract) {
            $contract->uuid = (string) Str::uuid();
        });
    }

    protected static function newFactory(): Factory
    {
        return ContractFactory::new();
    }

    // Relationships

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'created_by');
    }

    public function signatureRequests()
    {
        return $this->hasMany(SignatureRequest::class);
    }

    // Helpers

    public function isFullySigned(): bool
    {
        return $this->signatureRequests()
            ->whereNull('signed_at')
            ->doesntExist();
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Contract')
            ->logOnly(['status', 'sent_at', 'fully_signed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $event) {
                $actor  = \Illuminate\Support\Facades\Auth::user()?->name ?? 'System';
                $status = $this->status?->label() ?? 'unknown';
                return match($event) {
                    'created' => "{$actor} created contract for candidate #{$this->candidate_id}.",
                    'updated' => "{$actor} updated contract #{$this->id} — status: {$status}.",
                    default   => "{$actor} triggered \"{$event}\" on contract #{$this->id}.",
                };
            });
    }
}
