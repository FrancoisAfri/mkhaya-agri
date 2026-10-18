<?php

namespace App\Modules\ESign\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEntry extends Model
{
    public $timestamps = false;

    protected $table = 'esign_audit_entries';

    protected $fillable = [
        'envelope_id',
        'signer_id',
        'event',
        'actor_name',
        'actor_email',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function envelope()
    {
        return $this->belongsTo(Envelope::class);
    }

    public function signer()
    {
        return $this->belongsTo(Signer::class);
    }
}
