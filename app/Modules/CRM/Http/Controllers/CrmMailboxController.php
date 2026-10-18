<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmMailbox;
use App\Modules\CRM\Services\CrmMailboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CrmMailboxController extends Controller
{
    public function index(): Response
    {
        $mailboxes = CrmMailbox::with('defaultOwner.person')->where('purpose', 'lead')->latest()->get()->map(fn (CrmMailbox $m) => [
            'id'              => $m->id,
            'name'            => $m->name,
            'email'           => $m->email,
            'host'            => $m->host,
            'port'            => $m->port,
            'encryption'      => $m->encryption,
            'username'        => $m->username,
            'folder'          => $m->folder,
            'active'          => $m->active,
            'ai_filter'       => $m->ai_filter,
            'notify_roles'    => $m->notify_roles ?? [],
            'default_owner_id'=> $m->default_owner_id,
            'owner_name'      => $m->defaultOwner?->person
                ? trim($m->defaultOwner->person->first_name . ' ' . $m->defaultOwner->person->surname)
                : null,
            'last_checked_at' => optional($m->last_checked_at)->diffForHumans(),
            'last_status'     => $m->last_status,
            'has_password'    => ! empty($m->getAttributes()['password']),
        ]);

        return Inertia::render('Modules/CRM/Mailboxes/Index', [
            'mailboxes' => $mailboxes,
            'roles'     => $this->roleOptions(),
            'owners'    => $this->ownerOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request, true);

        $mailbox = new CrmMailbox();
        $mailbox->fill($this->fillable($data));
        $mailbox->password   = $data['password'];
        $mailbox->created_by = $request->user()->id;
        $mailbox->save();

        return back()->with('success', 'Mailbox added. It will be polled every 15 minutes.');
    }

    public function update(Request $request, CrmMailbox $mailbox): RedirectResponse
    {
        $data = $this->validateData($request, false);

        $mailbox->fill($this->fillable($data));
        // Only replace the password when a new one is supplied.
        if (! empty($data['password'])) {
            $mailbox->password = $data['password'];
        }
        $mailbox->save();

        return back()->with('success', 'Mailbox updated.');
    }

    public function toggle(CrmMailbox $mailbox): RedirectResponse
    {
        $mailbox->update(['active' => ! $mailbox->active]);
        return back()->with('success', $mailbox->active ? 'Mailbox enabled.' : 'Mailbox disabled.');
    }

    public function test(CrmMailbox $mailbox, CrmMailboxService $service): RedirectResponse
    {
        $result = $service->test($mailbox);
        $mailbox->update(['last_status' => $result['message'], 'last_checked_at' => now()]);
        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function pollNow(CrmMailbox $mailbox, CrmMailboxService $service): RedirectResponse
    {
        try {
            $result = $service->poll($mailbox);
            return back()->with('success', "Polled: {$result['processed']} email(s), {$result['leads']} lead(s), {$result['spam']} spam.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(CrmMailbox $mailbox): RedirectResponse
    {
        $mailbox->delete();
        return back()->with('success', 'Mailbox removed.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function fillable(array $data): array
    {
        return [
            'name'             => $data['name'],
            'purpose'          => $data['purpose'] ?? 'lead',
            'email'            => $data['email'],
            'host'             => $data['host'],
            'port'             => $data['port'] ?? 993,
            'encryption'       => $data['encryption'] ?? 'ssl',
            'username'         => $data['username'],
            'folder'           => $data['folder'] ?? 'INBOX',
            'active'           => $data['active'] ?? true,
            'ai_filter'        => $data['ai_filter'] ?? true,
            'notify_roles'     => $data['notify_roles'] ?? [],
            'default_owner_id' => $data['default_owner_id'] ?? null,
        ];
    }

    private function validateData(Request $request, bool $creating): array
    {
        return $request->validate([
            'name'             => 'required|string|max:120',
            'purpose'          => 'nullable|in:lead,complaint',
            'email'            => 'required|email|max:190',
            'host'             => 'required|string|max:190',
            'port'             => 'nullable|integer|min:1|max:65535',
            'encryption'       => 'nullable|in:ssl,tls,none',
            'username'         => 'required|string|max:190',
            'password'         => ($creating ? 'required' : 'nullable') . '|string|max:500',
            'folder'           => 'nullable|string|max:120',
            'active'           => 'boolean',
            'ai_filter'        => 'boolean',
            'notify_roles'     => 'array',
            'notify_roles.*'   => 'string|max:120',
            'default_owner_id' => 'nullable|exists:users,id',
        ]);
    }

    private function roleOptions(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('roles')) return [];
        return DB::table('roles')->orderBy('name')->pluck('name')->all();
    }

    private function ownerOptions(): array
    {
        return DB::table('users')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->select('users.id', DB::raw("CONCAT(hr_people.first_name, ' ', hr_people.surname) as name"))
            ->orderBy('hr_people.first_name')
            ->limit(500)
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }
}
