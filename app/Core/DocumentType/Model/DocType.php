<?php

namespace App\Core\DocumentType\Model;

use Illuminate\Database\Eloquent\Model;

class DocType extends Model
{
    //
    protected $table = 'doc_type';

    protected $fillable = ['name', 'description', 'active'];


    // public function docType()
    // {
    //     return $this->belongsTo(Categories::class, 'category_id');

    // }

}




