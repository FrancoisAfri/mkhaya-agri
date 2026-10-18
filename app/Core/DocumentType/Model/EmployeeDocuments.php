<?php

namespace App\Core\DocumentType\Model;

use App\Core\DocumentType\Model\DocType;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;

class EmployeeDocuments extends Model
{
    protected $table = 'employee_documents';

    protected $fillable = ['doc_description','category_id','division_level_2'
		,'division_level_1','hr_person_id'
	,'date_from','expirydate','supporting_docs','doc_type_id','qualification_type_id','hr_change_id'];

	public function docType()
    {
        return $this->belongsTo(DocType::class, 'doc_type_id');

    }

    	public function hrPerson()
    {
        return $this->belongsTo(HRPerson::class, 'hr_person_id');

    }
}
