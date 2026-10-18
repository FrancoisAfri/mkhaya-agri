<?php

namespace App\Modules\CRM\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Services\CrmEventBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * REST API for CRM entities (spec section 5, "API-first").
 *
 * Token-authenticated (Sanctum) generic resource endpoints for leads, contacts,
 * accounts, deals and activities. Writes fire the same CRM event bus as the web
 * UI, so automations and webhooks trigger from API calls too.
 *
 *   GET    /api/crm/{entity}            list (paginated, ?q= search, ?per_page=)
 *   POST   /api/crm/{entity}            create
 *   GET    /api/crm/{entity}/{id}       read
 *   PATCH  /api/crm/{entity}/{id}       update
 *   DELETE /api/crm/{entity}/{id}       delete
 */
class CrmApiController extends Controller
{
    private const ENTITIES = [
        'leads'      => CrmLead::class,
        'contacts'   => CrmContact::class,
        'accounts'   => CrmAccount::class,
        'deals'      => CrmDeal::class,
        'activities' => CrmActivity::class,
    ];

    public function __construct(private CrmEventBus $events)
    {
    }

    public function index(Request $request, string $entity): JsonResponse
    {
        $model = $this->model($entity);

        $query = $model::query();
        if ($request->filled('q')) {
            $term = $request->q;
            $cols = $this->searchColumns($entity);
            $query->where(function ($w) use ($cols, $term) {
                foreach ($cols as $c) $w->orWhere($c, 'ilike', "%{$term}%");
            });
        }

        $perPage = min(100, (int) $request->input('per_page', 25));

        return response()->json($query->latest()->paginate($perPage));
    }

    public function show(string $entity, int $id): JsonResponse
    {
        return response()->json(['data' => $this->model($entity)::findOrFail($id)]);
    }

    public function store(Request $request, string $entity): JsonResponse
    {
        $model = $this->model($entity);
        $data  = $request->validate($this->rules($entity, false));

        $data['uuid']       = Str::uuid();
        $data['created_by'] = Auth::id();
        if (in_array('owner_id', (new $model)->getFillable(), true)) {
            $data['owner_id'] = $data['owner_id'] ?? Auth::id();
        }

        if ($entity === 'leads' && empty($data['status'])) $data['status'] = 'new';
        if ($entity === 'deals') $data = $this->prepareDeal($data);

        $record = $model::create($data);

        // Fire CRM events so automations + webhooks run from the API too.
        match ($entity) {
            'leads'      => $this->events->fire('lead.created', $record),
            'deals'      => $this->events->fire('deal.created', $record),
            default      => null,
        };

        return response()->json(['data' => $record], 201);
    }

    public function update(Request $request, string $entity, int $id): JsonResponse
    {
        $record = $this->model($entity)::findOrFail($id);
        $record->update($request->validate($this->rules($entity, true)));

        return response()->json(['data' => $record]);
    }

    public function destroy(string $entity, int $id): JsonResponse
    {
        $this->model($entity)::findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function model(string $entity): string
    {
        abort_unless(isset(self::ENTITIES[$entity]), 404, "Unknown CRM entity: {$entity}");
        return self::ENTITIES[$entity];
    }

    private function prepareDeal(array $data): array
    {
        if (empty($data['pipeline_id']) || empty($data['stage_id'])) {
            $pipeline = CrmPipeline::where('is_default', true)->with('stages')->first()
                ?? CrmPipeline::with('stages')->first();
            abort_if(! $pipeline || $pipeline->stages->isEmpty(), 422, 'No pipeline configured.');
            $data['pipeline_id'] = $pipeline->id;
            $data['stage_id']    = $pipeline->stages->first()->id;
            $data['probability'] = $pipeline->stages->first()->probability;
        }
        $data['status']           = 'open';
        $data['currency']         = $data['currency'] ?? 'ZAR';
        $data['stage_changed_at'] = now();

        return $data;
    }

    private function searchColumns(string $entity): array
    {
        return match ($entity) {
            'leads'      => ['first_name', 'last_name', 'company', 'email'],
            'contacts'   => ['first_name', 'last_name', 'email'],
            'accounts'   => ['name', 'industry', 'city'],
            'deals'      => ['title'],
            'activities' => ['subject'],
            default      => [],
        };
    }

    private function rules(string $entity, bool $update): array
    {
        $req = $update ? 'sometimes|required' : 'required';

        return match ($entity) {
            'leads' => [
                'first_name' => "{$req}|string|max:120",
                'last_name'  => 'nullable|string|max:120',
                'company'    => 'nullable|string|max:200',
                'email'      => 'nullable|email|max:200',
                'phone'      => 'nullable|string|max:50',
                'source'     => 'nullable|string|max:100',
                'status'     => 'nullable|in:new,contacted,qualified,unqualified,converted',
                'notes'      => 'nullable|string',
            ],
            'contacts' => [
                'first_name' => "{$req}|string|max:120",
                'last_name'  => 'nullable|string|max:120',
                'account_id' => 'nullable|exists:crm_accounts,id',
                'email'      => 'nullable|email|max:200',
                'phone'      => 'nullable|string|max:50',
                'job_title'  => 'nullable|string|max:120',
            ],
            'accounts' => [
                'name'     => "{$req}|string|max:200",
                'industry' => 'nullable|string|max:120',
                'website'  => 'nullable|string|max:200',
                'email'    => 'nullable|email|max:200',
                'phone'    => 'nullable|string|max:50',
                'city'     => 'nullable|string|max:120',
                'country'  => 'nullable|string|max:120',
            ],
            'deals' => [
                'title'               => "{$req}|string|max:200",
                'account_id'          => 'nullable|exists:crm_accounts,id',
                'contact_id'          => 'nullable|exists:crm_contacts,id',
                'pipeline_id'         => 'nullable|exists:crm_pipelines,id',
                'stage_id'            => 'nullable|exists:crm_stages,id',
                'amount'              => 'nullable|numeric|min:0',
                'currency'            => 'nullable|string|size:3',
                'expected_close_date' => 'nullable|date',
                'source'              => 'nullable|string|max:100',
            ],
            'activities' => [
                'type'         => "{$req}|in:call,email,meeting,task,note,whatsapp,sms",
                'subject'      => "{$req}|string|max:200",
                'description'  => 'nullable|string',
                'related_type' => "{$req}|in:lead,contact,account,deal",
                'related_id'   => "{$req}|integer",
                'due_at'       => 'nullable|date',
                'status'       => 'nullable|in:pending,done,overdue,cancelled',
            ],
            default => [],
        };
    }
}
