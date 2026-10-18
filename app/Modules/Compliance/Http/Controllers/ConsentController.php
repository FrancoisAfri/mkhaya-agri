<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Core\Compliance\Models\PhiAccessLog;
use App\Core\Compliance\Models\PopiaConsent;
use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConsentController extends Controller
{
    public function index(Request $request): Response
    {
        $search  = trim((string) $request->input('search', ''));
        $type    = $request->input('type', 'employee'); // employee | candidate

        $subject  = null;
        $consents = [];
        $phiLog   = [];

        if ($search) {
            $model = $type === 'candidate' ? Candidate::class : HRPerson::class;

            $record = $model::query()
                ->where(fn ($q) => $q
                    ->where('uuid', $search)
                    ->orWhere('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                )
                ->first();

            if ($record) {
                $subject = [
                    'uuid'  => $record->uuid,
                    'name'  => trim("{$record->first_name} {$record->last_name}"),
                    'type'  => $type,
                    'id'    => $record->id,
                ];

                $consents = PopiaConsent::where('subject_type', $model)
                    ->where('subject_id', $record->id)
                    ->orderByDesc('granted_at')
                    ->get()
                    ->map(fn ($c) => [
                        'uuid'             => $c->uuid,
                        'purpose'          => $c->purpose,
                        'status'           => $c->status,
                        'consent_version'  => $c->consent_version,
                        'granted_at'       => $c->granted_at?->toISOString(),
                        'expires_at'       => $c->expires_at?->toISOString(),
                        'revoked_at'       => $c->revoked_at?->toISOString(),
                        'is_active'        => $c->isActive(),
                        'ip_address'       => $c->ip_address,
                        'notes'            => $c->notes,
                    ])
                    ->toArray();

                $phiLog = PhiAccessLog::with('accessor.person')
                    ->where('subject_type', $model)
                    ->where('subject_id', $record->id)
                    ->orderByDesc('accessed_at')
                    ->limit(50)
                    ->get()
                    ->map(fn ($l) => [
                        'id'          => $l->id,
                        'field'       => $l->field,
                        'accessed_by' => $l->accessor?->name ?? 'System',
                        'ip_address'  => $l->ip_address,
                        'accessed_at' => $l->accessed_at->toISOString(),
                    ])
                    ->toArray();
            }
        }

        return Inertia::render('Modules/Compliance/Consents/Index', [
            'subject'  => $subject,
            'consents' => $consents,
            'phiLog'   => $phiLog,
            'filters'  => ['search' => $search, 'type' => $type],
        ]);
    }
}
