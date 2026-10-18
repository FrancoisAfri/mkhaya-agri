<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\Qcto\LmsRegisteredPerson;
use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class LmsAssessorController extends Controller
{
    public function index()
    {
        $persons = LmsRegisteredPerson::with('seta')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($p) => [
                'id'                  => $p->id,
                'first_name'          => $p->first_name,
                'last_name'           => $p->last_name,
                'id_number'           => $p->id_number,
                'email'               => $p->email,
                'person_type'         => $p->person_type,
                'registration_number' => $p->registration_number,
                'seta_id'             => $p->seta_id,
                'seta'                => $p->seta ? ['id' => $p->seta->id, 'acronym' => $p->seta->acronym] : null,
                'registered_at'       => $p->registered_at?->toDateString(),   // → "YYYY-MM-DD"
                'expires_at'          => $p->expires_at?->toDateString(),
                'nqf_levels'          => $p->nqf_levels ?? [],
                'fields_of_study'     => $p->fields_of_study ?? [],
                'status'              => $p->status,
                'notes'               => $p->notes,
            ]);

        $setas = LmsSeta::active()->orderBy('acronym')->get(['id','name','acronym']);

        return Inertia::render('Modules/LMS/Admin/RegisteredPersons', [
            'persons' => $persons,
            'setas'   => $setas,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'          => 'required|string|max:80',
            'last_name'           => 'required|string|max:80',
            'id_number'           => 'nullable|string|max:20',
            'email'               => 'nullable|email|max:120',
            'person_type'         => 'required|in:assessor,moderator,assessor_moderator',
            'registration_number' => 'nullable|string|max:60',
            'seta_id'             => 'nullable|exists:lms_setas,id',
            'registered_at'       => 'nullable|date',
            'expires_at'          => 'nullable|date|after:today',
            'nqf_levels'          => 'nullable|array',
            'fields_of_study'     => 'nullable|array',
            'status'              => 'nullable|in:active,lapsed,suspended,cancelled',
            'notes'               => 'nullable|string',
        ]);

        if (!empty($data['id_number']) && !$this->isValidSaId($data['id_number'])) {
            return back()->withErrors([
                'id_number' => 'The SA ID number is invalid. Must be exactly 13 digits (IDs starting with 0 are valid — enter the full number including leading zeros).',
            ]);
        }

        $data['created_by'] = Auth::id();

        LmsRegisteredPerson::create($data);

        return back()->with('success', 'Registered person saved.');
    }

    public function update(Request $request, LmsRegisteredPerson $person)
    {
        $data = $request->validate([
            'first_name'          => 'required|string|max:80',
            'last_name'           => 'required|string|max:80',
            'id_number'           => 'nullable|string|max:20',
            'email'               => 'nullable|email|max:120',
            'person_type'         => 'required|in:assessor,moderator,assessor_moderator',
            'registration_number' => 'nullable|string|max:60',
            'seta_id'             => 'nullable|exists:lms_setas,id',
            'registered_at'       => 'nullable|date',
            'expires_at'          => 'nullable|date|after:today',
            'nqf_levels'          => 'nullable|array',
            'fields_of_study'     => 'nullable|array',
            'status'              => 'nullable|in:active,lapsed,suspended,cancelled',
            'notes'               => 'nullable|string',
        ]);

        if (!empty($data['id_number']) && !$this->isValidSaId($data['id_number'])) {
            return back()->withErrors([
                'id_number' => 'The SA ID number is invalid. Must be exactly 13 digits (IDs starting with 0 are valid — enter the full number including leading zeros).',
            ]);
        }

        $person->update($data);

        return back()->with('success', 'Registered person updated.');
    }

    public function destroy(LmsRegisteredPerson $person)
    {
        $person->delete();

        return back()->with('success', 'Removed.');
    }

    private function isValidSaId(string $id): bool
    {
        $id = preg_replace('/\s+/', '', $id);
        if (!preg_match('/^\d{13}$/', $id)) return false;

        $month = (int) substr($id, 2, 2);
        $day   = (int) substr($id, 4, 2);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) return false;

        $citizenDigit = (int) $id[10];
        if (!in_array($citizenDigit, [0, 1], true)) return false;

        // Luhn checksum
        $sum    = 0;
        $nDigits = 13;
        $parity  = $nDigits % 2;
        for ($i = 0; $i < $nDigits; $i++) {
            $digit = (int) $id[$i];
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) $digit -= 9;
            }
            $sum += $digit;
        }
        return ($sum % 10) === 0;
    }
}
