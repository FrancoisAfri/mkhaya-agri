<?php

namespace App\Core\UserApproval\Services;

use App\Core\Storage\StoredImage;
use App\Core\DocumentType\Model\EmployeeDocuments;
use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Core\UserApproval\Mails\ProfileChangeApprovedMail;
use App\Core\UserApproval\Mails\ProfileChangeRejectedMail;
use App\Core\UserApproval\Mails\ProfileChangeSubmittedMail;
use App\Core\UserApproval\Notifications\ProfileChangeApprovedNotification;
use App\Core\UserApproval\Notifications\ProfileChangeRejectedNotification;
use App\Core\UserApproval\Notifications\ProfileChangeSubmittedNotification;
use App\Modules\HR\Hr\Models\HrPeopleChange;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProfileUpdateService
{
    use FileStorage;

    private const COPYABLE_FIELDS = [
        'first_name', 'surname', 'middle_name', 'maiden_name', 'aka',
        'initial', 'cell_number', 'phone_number', 'id_number',
        'date_of_birth', 'passport_number', 'drivers_licence_number',
        'drivers_licence_code', 'proof_drive_permit', 'proof_drive_permit_exp_date',
        'drivers_licence_exp_date', 'gender', 'own_transport', 'marital_status',
        'ethnicity', 'profile_pic', 'status', 'division_level_1', 'division_level_2',
        'division_level_3', 'division_level_4', 'division_level_5', 'employee_number',
        'leave_profile', 'manager_id', 'second_manager_id', 'date_joined', 'date_left',
        'role_id', 'position_id', 'title', 'known_as', 'alternate_number', 'next_of_kin',
        'next_of_kin_number', 'next_of_kin_work_number',
        'complex_name', 'unit_number', 'income_tax_number',
        'tax_office', 'account_type', 'account_holder_name', 'bank_name', 'branch_name',
        'account_number', 'package_components', 'monthly_package', 'package_cash',
        'package_travel', 'package_incentive', 'package_cell_phone', 'package_med_aid',
        'med_start_date', 'med_split', 'med_plan_name', 'med_dep_spouse', 'med_dep_adult',
        'med_dep_kids', 'med_amount', 'provident_start_date', 'provident_name',
        'provident_amount', 'provident_fund_product',
        'disabled', 'nature_of_disability', 'citizenship', 'other',
        'employment_type', 'occupational_level', 'job_function',
        'start_time', 'end_time',
        'can_drive',
        'res_address', 'res_suburb', 'res_city', 'res_postal_code',
        'res_province_id', 'res_country_id',
        'email',
    ];

    private const DATE_FIELDS = [
        'date_of_birth',
        'date_joined',
        'date_left',
        'med_start_date',
        'provident_start_date',
        'drivers_licence_exp_date',
        'proof_drive_permit_exp_date',
    ];

    /*
    |--------------------------------------------------------------------------
    | PERMISSIONS
    |--------------------------------------------------------------------------
    */
    public function canUpdateDirectly(User $user): bool
    {
        $user->loadMissing('roles');

        $isManager = DB::table('company_user_pivots')
            ->where('user_id', $user->id)
            ->where('type', 1)
            ->exists();

        if ($isManager) return true;

        $highRoles = ['executive', 'super user', 'senior manager', 'administrator', 'supervisor'];

        $userRoles = $user->roles
            ->pluck('name')
            ->filter()
            ->map(fn($r) => strtolower(trim($r)))
            ->toArray();

        return count(array_intersect($highRoles, $userRoles)) > 0;
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE DIRECTLY
    |--------------------------------------------------------------------------
    */
    public function updateDirectly(User $user, Request $request): HRPerson
    {
        $person = $user->person;

        if (!$person) {
            throw new \Exception('User has no associated HR person record.');
        }

        $data = $this->extractPersonData($request);

        if ($request->hasFile('profile_pic')) {
            $data['profile_pic'] = null;
            $data['profile_pic_path'] = StoredImage::put($request->file('profile_pic'), 'people/photos');
        }

        if ($request->hasFile('proof_drive_permit')) {
            $fileData                    = $this->saveFileToDatabase($request->file('proof_drive_permit'));
            $data['proof_drive_permit']  = json_encode($fileData);
        }

        if ($request->has('company_ids')) {
            $companyIds = $request->company_ids ?? [];
            if (!is_array($companyIds)) {
                $companyIds = $companyIds ? [$companyIds] : [];
            }
            $user->companyUnits()->sync($companyIds);
        }

        $person->update($data);

        return $person->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | SUBMIT FOR APPROVAL
    |--------------------------------------------------------------------------
    */
    public function submitForApproval(User $user, Request $request): HrPeopleChange
    {
        $person = $user->person;

        if (!$person) {
            throw new \Exception('User has no associated HR person record.');
        }

        $data = $this->extractPersonData($request);

        if ($request->hasFile('profile_pic')) {
            $data['profile_pic'] = null;
            $data['profile_pic_path'] = StoredImage::put($request->file('profile_pic'), 'people/photos');
        }

        if ($request->has('company_ids')) {
            $companyIds = $request->company_ids ?? [];
            if (!is_array($companyIds)) {
                $companyIds = $companyIds ? [$companyIds] : [];
            }
            $data['company_unit_ids'] = json_encode($companyIds);
        }

        $userChanges = [];
        if ($request->filled('email') && $request->email !== $user->email) {
            $userChanges['email'] = $request->email;
        }
        if ($request->filled('role')) {
            $userChanges['role'] = $request->role;
        }
        if ($request->has('status')) {
            $userChanges['status'] = $request->status;
        }

        if (!empty($userChanges)) {
            $data['user_changes'] = json_encode($userChanges);
        }

        HrPeopleChange::where('hr_main_id', $person->id)
            ->where('is_approved', 0)
            ->delete();

        $data['hr_main_id']  = $person->id;
        $data['is_approved'] = 0;
        $data['uuid']        = (string) Str::uuid();

        $change = HrPeopleChange::create($data);

        activity()
            ->causedBy(Auth::user())
            ->performedOn($change)
            ->withProperties(['submitted' => $data])
            ->log("Profile change submitted for approval — user '{$user->email}'");

        $this->notifyOnSubmission($user, $change);

        return $change;
    }

    /*
    |--------------------------------------------------------------------------
    | DOCUMENTS — STORE
    |--------------------------------------------------------------------------
    */
public function storeDocument(HRPerson $person, Request $request): EmployeeDocuments
{
    $fileData      = $this->saveFileToDatabase($request->file('file'));
    $supportingDoc = json_encode($fileData);

    $canEdit = $this->canUpdateDirectly(Auth::user());

    // ✅ Convertir dates → timestamp Unix comme le reste de l'app
    $dateFrom   = $request->input('date_from')  ? strtotime($request->input('date_from'))  : null;
    $expiryDate = $request->input('expirydate') ? strtotime($request->input('expirydate')) : null;

    if ($canEdit) {
        return EmployeeDocuments::create([
            'hr_person_id'    => $person->id,
            'hr_change_id'    => null,
            'doc_description' => $request->input('doc_description'),
            'date_from'       => $dateFrom,
            'expirydate'      => $expiryDate,
            'supporting_docs' => $supportingDoc,
        ]);
    }

    $change = HrPeopleChange::create([
        'hr_main_id'  => $person->id,
        'is_approved' => 0,
        'uuid'        => (string) Str::uuid(),
    ]);

    return EmployeeDocuments::create([
        'hr_person_id'    => null,
        'hr_change_id'    => $change->id,
        'doc_description' => $request->input('doc_description'),
        'date_from'       => $dateFrom,
        'expirydate'      => $expiryDate,
        'supporting_docs' => $supportingDoc,
    ]);
}

    /*
    |--------------------------------------------------------------------------
    | DOCUMENTS — UPDATE
    |--------------------------------------------------------------------------
    */
public function updateDocument(EmployeeDocuments $doc, Request $request): EmployeeDocuments
{
    $data = [
        'doc_description' => $request->input('doc_description'),
        'date_from'       => $request->input('date_from')  ? strtotime($request->input('date_from'))  : null,
        'expirydate'      => $request->input('expirydate') ? strtotime($request->input('expirydate')) : null,
    ];

    if ($request->hasFile('file')) {
        $fileData                = $this->saveFileToDatabase($request->file('file'));
        $data['supporting_docs'] = json_encode($fileData);
    }

    $doc->update($data);

    return $doc->fresh();
}

    /*
    |--------------------------------------------------------------------------
    | DOCUMENTS — DELETE
    |--------------------------------------------------------------------------
    */
    public function deleteDocument(EmployeeDocuments $doc): void
    {
        $doc->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | DOCUMENTS — DOWNLOAD
    |--------------------------------------------------------------------------
    */
    public function getDocumentFileData(EmployeeDocuments $doc): array
    {
        if (!$doc->supporting_docs) {
            throw new \Exception('No file attached to this document.');
        }

        $parsed = is_string($doc->supporting_docs)
            ? json_decode($doc->supporting_docs, true)
            : (array) $doc->supporting_docs;

        if (!isset($parsed['data'])) {
            throw new \Exception('File data is missing.');
        }

        return $parsed;
    }

    /*
    |--------------------------------------------------------------------------
    | APPROVE
    |--------------------------------------------------------------------------
    */
    public function approveChange(HrPeopleChange $change, User $approver): HRPerson
    {
        $person = HRPerson::findOrFail($change->hr_main_id);
        $user   = $person->user;

        $toApply = collect(self::COPYABLE_FIELDS)
            ->filter(fn($f) => isset($change->$f) && $change->$f !== null)
            ->mapWithKeys(fn($f) => [$f => $change->$f])
            ->toArray();

        $person->update($toApply);

        if (!empty($change->user_changes)) {
            $userChanges = json_decode($change->user_changes, true);
            if (isset($userChanges['email']))  $user->update(['email'  => $userChanges['email']]);
            if (isset($userChanges['role']))   $user->syncRoles([$userChanges['role']]);
            if (isset($userChanges['status'])) $user->update(['status' => $userChanges['status']]);
        }

        if (!empty($change->company_unit_ids)) {
            $companyIds = json_decode($change->company_unit_ids, true) ?? [];
            if ($user) $user->companyUnits()->sync($companyIds);
        }

        // ── Approuver les documents en attente liés à ce change ──
        EmployeeDocuments::where('hr_change_id', $change->id)
            ->whereNull('hr_person_id')
            ->update(['hr_person_id' => $person->id]);

        $change->update(['is_approved' => 1]);

        activity()
            ->causedBy($approver)
            ->performedOn($person)
            ->withProperties(['approved' => $toApply, 'change_id' => $change->id])
            ->log("Profile change approved by '{$approver->email}'");

        $this->notifyOnApproval($person, $change, $approver);

        return $person->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | REJECT
    |--------------------------------------------------------------------------
    */
    public function rejectChange(HrPeopleChange $change, User $approver, string $reason = ''): void
    {
        $person = HRPerson::find($change->hr_main_id);

        // ── Supprimer les documents en attente liés à ce change ──
        EmployeeDocuments::where('hr_change_id', $change->id)
            ->whereNull('hr_person_id')
            ->delete();

        activity()
            ->causedBy($approver)
            ->performedOn($change)
            ->withProperties(['reason' => $reason, 'change_id' => $change->id])
            ->log("Profile change rejected by '{$approver->email}'");

        $this->notifyOnRejection($person, $change, $approver, $reason);

        $change->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | PRIVATE HELPERS
    |--------------------------------------------------------------------------
    */
    private function extractPersonData(Request $request): array
    {
        return collect(self::COPYABLE_FIELDS)
            ->filter(fn($f) => $f !== 'profile_pic' && $request->has($f) && $request->$f !== null)
            ->mapWithKeys(fn($f) => [$f => $this->castDateField($f, $request->$f)])
            ->toArray();
    }

    private function castDateField(string $field, mixed $value): mixed
    {
        if (!in_array($field, self::DATE_FIELDS, true)) {
            return $value;
        }

        if ($value === '' || $value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $timestamp = strtotime($value);
            return $timestamp === false ? null : $timestamp;
        }

        return $value;
    }

    private function notifyOnSubmission(User $user, HrPeopleChange $change): void
    {
        $this->sendMail($user->email, new ProfileChangeSubmittedMail($user, $change, isManager: false));
        $user->notify(new ProfileChangeSubmittedNotification($user, $change, isManager: false));

        $managerIds = DB::table('company_user_pivots')
            ->where('type', 1)
            ->pluck('user_id')
            ->unique()
            ->toArray();

        if (!empty($managerIds)) {
            $managers = User::whereIn('id', $managerIds)->get();
            foreach ($managers as $manager) {
                $this->sendMail($manager->email, new ProfileChangeSubmittedMail($user, $change, isManager: true));
                $manager->notify(new ProfileChangeSubmittedNotification($user, $change, isManager: true));
            }
        }
    }

    private function notifyOnApproval(HRPerson $person, HrPeopleChange $change, User $approver): void
    {
        $this->sendMail($person->email, new ProfileChangeApprovedMail($person, $change, $approver));
        $person->user?->notify(new ProfileChangeApprovedNotification($change, $approver));
    }

    private function notifyOnRejection(?HRPerson $person, HrPeopleChange $change, User $approver, string $reason): void
    {
        if (!$person?->email) return;
        $this->sendMail($person->email, new ProfileChangeRejectedMail($person, $change, $approver, $reason));
        $person->user?->notify(new ProfileChangeRejectedNotification($change, $approver, $reason));
    }

    private function sendMail(?string $to, $mailable): void
    {
        if (!$to) return;
        try {
            Mail::to($to)->send($mailable);
        } catch (\Exception $e) {
            Log::warning("Mail failed to {$to}: " . $e->getMessage());
        }
    }
}
