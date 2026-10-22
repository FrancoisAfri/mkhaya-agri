<?php

namespace App\Modules\Agri\Models;

/** One generated version of an export document, kept in Document Management. */
class ExportDocument extends FarmOwnedModel
{
    protected $table = 'agri_export_documents';

    protected $casts = ['generated_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
