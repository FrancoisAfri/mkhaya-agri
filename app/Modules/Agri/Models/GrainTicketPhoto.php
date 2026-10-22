<?php

namespace App\Modules\Agri\Models;

/** A photo of a load (the sample, the moisture meter, the weighbridge slip), kept in the database. */
class GrainTicketPhoto extends FarmOwnedModel
{
    protected $table = 'agri_grain_ticket_photos';

    protected $hidden = ['data'];
}
