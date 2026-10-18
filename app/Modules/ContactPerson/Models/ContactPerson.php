<?php

namespace App\Modules\ContactPerson\Models;

use App\Core\User\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ContactPerson extends Model
{
    use LogsActivity;
    //Specify the table
    public $table = 'contacts_contacts';

    // Mass assignable fields
    protected $fillable = [
        'first_name', 'surname', 'middle_name', 'maiden_name', 'aka', 'initial', 'email'
        , 'cell_number', 'phone_number', 'id_number', 'date_of_birth', 'passport_number'
        , 'drivers_licence_number', 'drivers_licence_code', 'proof_drive_permit'
        , 'proof_drive_permit_exp_date', 'drivers_licence_exp_date', 'gender', 'own_transport'
        , 'marital_status', 'ethnicity', 'profile_pic', 'status', 'contact_type'
        , 'organization_type', 'office_number', 'str_position', 'res_province_id'
        , 'res_postal_code', 'res_city', 'res_suburb', 'res_address', 'division_level_1'
        , 'division_level_2', 'division_level_3', 'division_level_4', 'division_level_5', 'company_id'
    ];

    //  9449985196

    //status values
    private $statusValues = ['' => '', 0 => 'Inactive', 1 => 'Active'];

    //Relationship contacts_contacts and user

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    //Relationship contacts_contacts and contact_companies
    public function company()
    {
        return $this->belongsTo(ContactCompany::class, 'company_id');
    }

//    /**
//     * Relationship between Contact Person (contacts_contacts) and Quotations
//     *
//     * @return  \Illuminate\Database\Eloquent\Relations\HasMany
//     */
//    public function quotations()
//    {
//        return $this->hasMany(Quotation::class, 'client_id');
//    }
//
//    /**
//     * Relationship between Contact Person and Account
//     *
//     * @return \Illuminate\Database\Eloquent\Relations\HasMany
//     */
//    public function accounts()
//    {
//        return $this->hasMany(CRMAccount::class, 'client_id');
//    }
//
//    /**
//     * Relationship between Contact Person and MeetingAttendees
//     *
//     * @return \Illuminate\Database\Eloquent\Relations\HasMany
//     */
//    public function meetings()
//    {
//        return $this->hasMany(MeetingAttendees::class, 'client_id');
//    }
//
//    /**
//     * Relationship between Contact Person and MeetingsMinutes
//     *
//     * @return \Illuminate\Database\Eloquent\Relations\HasMany
//     */
//    public function minutes()
//    {
//        return $this->hasMany(MeetingsMinutes::class, 'client_id');
//    }

    //Full Name accessor
    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->surname;
    }

    //Status string accessor
    public function getStrStatusAttribute()
    {
        return $this->statusValues[$this->status];
    }

    //Full Profile picture url accessor
    public function getProfilePicUrlAttribute()
    {
        $m_silhouette = Storage::disk('local')->url('avatars/m-silhouette.jpg');
        $f_silhouette = Storage::disk('local')->url('avatars/f-silhouette.jpg');
        return (!empty($this->profile_pic)) ? Storage::disk('local')->url("avatars/$this->profile_pic") : (($this->gender === 0) ? $f_silhouette : $m_silhouette);
    }

    //function to get contact people from a specific company
    public static function peopleFromCompany($whereField, $whereValue, $incInactive)
    {
        return ContactPerson::where(function ($query) use ($whereValue, $whereField) {
            if ($whereValue == 0) $query->whereNull($whereField);
            else $query->where($whereField, $whereValue);
        })
            ->where(function ($query) use ($incInactive) {
                if ($incInactive == -1) {
                    $query->where('status', 1);
                }
            })->get()
            ->sortBy('full_name')
            ->pluck('id', 'full_name');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ContactPerson')
            ->logOnly(['first_name', 'surname', 'email', 'cell_number', 'status', 'contact_type', 'company_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor = Auth::user()?->name ?? 'System';
        $name  = trim(($this->first_name ?? '') . ' ' . ($this->surname ?? '')) ?: 'Unknown contact';
        return match ($eventName) {
            'created' => "{$actor} added contact \"{$name}\".",
            'updated' => "{$actor} updated contact \"{$name}\".",
            'deleted' => "{$actor} removed contact \"{$name}\".",
            default   => "{$actor} triggered \"{$eventName}\" on contact \"{$name}\".",
        };
    }
}
