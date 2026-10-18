<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\Qcto\LmsSdf;
use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsSdfController extends Controller
{
    public function index(): Response
    {
        $sdfs = LmsSdf::with(['user.person', 'seta'])
            ->orderByDesc('is_primary')
            ->get()
            ->map(fn ($s) => [
                'id'                  => $s->id,
                'user_id'             => $s->user_id,
                'user'                => $s->user ? [
                    'id'     => $s->user->id,
                    'person' => $s->user->person ? [
                        'first_name' => $s->user->person->first_name,
                        'surname'    => $s->user->person->surname,
                    ] : null,
                ] : null,
                'seta_id'             => $s->seta_id,
                'seta'                => $s->seta ? ['id' => $s->seta->id, 'acronym' => $s->seta->acronym] : null,
                'registration_number' => $s->registration_number,
                'registered_at'       => $s->registered_at?->toDateString(),
                'expires_at'          => $s->expires_at?->toDateString(),
                'is_primary'          => (bool) $s->is_primary,
                'department'          => $s->department,
                'status'              => $s->status,
                'notes'               => $s->notes,
            ]);

        $setas = LmsSeta::active()->orderBy('acronym')->get(['id', 'name', 'acronym']);

        $users = User::with('person')
            ->orderBy('id')
            ->get()
            ->map(fn ($u) => [
                'id'   => $u->id,
                'name' => trim(optional($u->person)->first_name . ' ' . optional($u->person)->surname),
            ]);

        $departments = CompanyUnit::where('type', 'department')
            ->where('active', 1)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Modules/LMS/Admin/Sdfs', [
            'sdfs'        => $sdfs,
            'setas'       => $setas,
            'users'       => $users,
            'departments' => $departments,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id'             => 'required|exists:users,id',
            'seta_id'             => 'nullable|exists:lms_setas,id',
            'registration_number' => 'nullable|string|max:60',
            'registered_at'       => 'nullable|date',
            'expires_at'          => 'nullable|date|after:today',
            'is_primary'          => 'boolean',
            'department'          => 'nullable|string|max:120',
            'status'              => 'nullable|in:active,lapsed,suspended',
            'notes'               => 'nullable|string',
        ]);

        if (!empty($data['is_primary'])) {
            LmsSdf::where('is_primary', true)->update(['is_primary' => false]);
        }

        $data['created_by'] = Auth::id();
        LmsSdf::create($data);

        return back()->with('success', 'SDF saved.');
    }

    public function update(Request $request, LmsSdf $sdf): RedirectResponse
    {
        $data = $request->validate([
            'user_id'             => 'required|exists:users,id',
            'seta_id'             => 'nullable|exists:lms_setas,id',
            'registration_number' => 'nullable|string|max:60',
            'registered_at'       => 'nullable|date',
            'expires_at'          => 'nullable|date|after:today',
            'is_primary'          => 'boolean',
            'department'          => 'nullable|string|max:120',
            'status'              => 'nullable|in:active,lapsed,suspended',
            'notes'               => 'nullable|string',
        ]);

        if (!empty($data['is_primary'])) {
            LmsSdf::where('is_primary', true)
                ->where('id', '!=', $sdf->id)
                ->update(['is_primary' => false]);
        }

        $sdf->update($data);

        return back()->with('success', 'SDF updated.');
    }

    public function destroy(LmsSdf $sdf): RedirectResponse
    {
        $sdf->delete();
        return back()->with('success', 'SDF removed.');
    }
}
