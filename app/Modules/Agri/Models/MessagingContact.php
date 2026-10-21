<?php

namespace App\Modules\Agri\Models;

/** A verified number a person uses the farm assistant from (§16C). */
class MessagingContact extends AgriModel
{
    public const CHANNELS = ['whatsapp', 'sms', 'voice'];
    public const LANGUAGES = ['en', 'af'];

    protected $table = 'agri_messaging_contacts';

    protected $hidden = ['code_hash'];

    protected $casts = ['code_expires_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'verified_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'alerts' => 'boolean'];
}
