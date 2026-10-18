<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Beneficiary;
use App\Modules\HR\Payroll\Services\BargainingCouncilService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bargaining councils, their levy rates, and who they cover.
 *
 * The levy is calculated on every run now, but the councils and rates behind it could only be set
 * up with a SQL client — so in practice nobody could turn it on.
 *
 * Two things this screen has to make obvious, because getting either wrong is expensive and quiet:
 * membership is per person rather than per company, and a rate has a date, so changing one creates
 * a new determination rather than editing history.
 */
class BargainingCouncilController extends Controller
{
    public function __construct(private readonly BargainingCouncilService $councils)
    {
    }

    public function index(): Response
    {
        $companyId = app(CurrentCompany::class)->id();

        $councils = DB::table('bargaining_councils')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'code', 'sector', 'region', 'beneficiary_id', 'active', 'company_id']);

        return Inertia::render('Modules/HR/Payroll/BargainingCouncils/Index', [
            'councils' => $councils->map(fn ($c) => [
                'uuid'     => $c->uuid,
                'name'     => $c->name,
                'code'     => $c->code,
                'sector'   => $c->sector,
                'region'   => $c->region,
                'active'   => (bool) $c->active,
                // A shipped row belongs to everybody and is not this tenant's to edit; the first
                // edit copies it, which is the same rule the contract templates follow.
                'shared'   => $c->company_id === null,
                'beneficiary' => $c->beneficiary_id
                    ? Beneficiary::where('id', $c->beneficiary_id)->value('name')
                    : null,
                'members'  => DB::table('bargaining_council_members')
                    ->where('council_id', $c->id)
                    ->whereNull('left_on')
                    ->count(),
                'rates'    => DB::table('bargaining_council_rates')
                    ->where('council_id', $c->id)
                    ->orderByDesc('effective_from')
                    ->get(['id', 'effective_from', 'effective_to', 'basis', 'employee_amount',
                        'employer_amount', 'employee_percent', 'employer_percent',
                        'employee_ceiling', 'employer_ceiling', 'source']),
                'current'  => $this->councils->rateOn((int) $c->id, now()),
            ]),
            'beneficiaries' => Beneficiary::where('active', true)->orderBy('name')
                ->get(['uuid', 'name', 'type']),
            // Everybody who could be covered, so membership is a picker rather than an id.
            'employees' => DB::table('hr_people as p')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->when($companyId, fn ($q) => $q->where('p.company_id', $companyId))
                ->whereNull('u.deleted_at')
                ->orderBy('p.first_name')
                ->limit(2000)
                ->get(['p.user_id as id', 'p.first_name', 'p.surname', 'p.employee_number'])
                ->map(fn ($p) => [
                    'id'    => $p->id,
                    'label' => trim($p->first_name . ' ' . $p->surname) . ($p->employee_number ? " ({$p->employee_number})" : ''),
                ]),
            'membership' => DB::table('bargaining_council_members as m')
                ->join('bargaining_councils as c', 'c.id', '=', 'm.council_id')
                ->get(['m.id', 'm.council_id', 'm.employee_id', 'm.joined_on', 'm.left_on', 'c.uuid as council_uuid']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:150'],
            'code'   => ['nullable', 'string', 'max:30'],
            'sector' => ['nullable', 'string', 'max:150'],
            'region' => ['nullable', 'string', 'max:150'],
            'beneficiary_uuid' => ['nullable', 'string'],
        ]);

        DB::table('bargaining_councils')->insert([
            'uuid'       => (string) Str::uuid(),
            'company_id' => app(CurrentCompany::class)->id(),
            'name'       => $data['name'],
            'code'       => $data['code'] ?? null,
            'sector'     => $data['sector'] ?? null,
            'region'     => $data['region'] ?? null,
            'beneficiary_id' => $this->beneficiaryId($data['beneficiary_uuid'] ?? null),
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Council added. Add a rate and its members before the next run.');
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $council = $this->ownCouncil($uuid);

        DB::table('bargaining_councils')->where('id', $council->id)->update(array_filter([
            'name'   => $request->input('name'),
            'code'   => $request->input('code'),
            'sector' => $request->input('sector'),
            'region' => $request->input('region'),
            'beneficiary_id' => $this->beneficiaryId($request->input('beneficiary_uuid')),
            'active' => $request->has('active') ? (bool) $request->boolean('active') : null,
            'updated_at' => now(),
        ], fn ($v) => $v !== null));

        return back()->with('success', 'Council updated.');
    }

    /**
     * Adds a rate, closing the one it replaces the day before it starts.
     *
     * The same rule the labour rates follow: no date may be covered by two determinations, or
     * which levy applies becomes a matter of sort order.
     */
    public function storeRate(Request $request, string $uuid): RedirectResponse
    {
        $council = $this->ownCouncil($uuid);

        $data = $request->validate([
            'effective_from'   => ['required', 'date'],
            'basis'            => ['required', 'in:fixed,percentage'],
            'employee_amount'  => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'employer_amount'  => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'employee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'employer_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'employee_ceiling' => ['nullable', 'numeric', 'min:0'],
            'employer_ceiling' => ['nullable', 'numeric', 'min:0'],
            'source'           => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['basis'] === 'percentage'
            && (float) ($data['employee_percent'] ?? 0) + (float) ($data['employer_percent'] ?? 0) === 0.0) {
            return back()->with('error', 'A percentage rate needs a percentage on at least one side.');
        }

        DB::table('bargaining_council_rates')
            ->where('council_id', $council->id)
            ->whereNull('effective_to')
            ->whereDate('effective_from', '<', $data['effective_from'])
            ->update(['effective_to' => date('Y-m-d', strtotime($data['effective_from'] . ' -1 day'))]);

        DB::table('bargaining_council_rates')->insert($data + [
            'council_id' => $council->id,
            'company_id' => app(CurrentCompany::class)->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Rate saved. It applies from ' . $data['effective_from'] . '.');
    }

    /** Adds employees to a council. Per person, because one employer has covered and uncovered staff. */
    public function addMembers(Request $request, string $uuid): RedirectResponse
    {
        $council = $this->ownCouncil($uuid);

        $data = $request->validate([
            'employee_ids'   => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
            'joined_on'      => ['nullable', 'date'],
        ]);

        $added = 0;
        foreach ($data['employee_ids'] as $employeeId) {
            $exists = DB::table('bargaining_council_members')
                ->where('council_id', $council->id)->where('employee_id', $employeeId)->exists();

            if ($exists) {
                continue;
            }

            DB::table('bargaining_council_members')->insert([
                'council_id'  => $council->id,
                'company_id'  => app(CurrentCompany::class)->id(),
                'employee_id' => $employeeId,
                'joined_on'   => $data['joined_on'] ?? null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $added++;
        }

        return back()->with('success', "{$added} employee(s) added to this council.");
    }

    /**
     * Ends somebody's membership rather than deleting it.
     *
     * A levy already deducted for a past period has to stay explicable, and deleting the row makes
     * the payslip that carried it unaccountable.
     */
    public function endMembership(Request $request, string $uuid, int $memberId): RedirectResponse
    {
        $council = $this->ownCouncil($uuid);
        $data = $request->validate(['left_on' => ['nullable', 'date']]);

        DB::table('bargaining_council_members')
            ->where('council_id', $council->id)->where('id', $memberId)
            ->update(['left_on' => $data['left_on'] ?? now()->toDateString(), 'updated_at' => now()]);

        return back()->with('success', 'Membership ended. Past levies are left as they were.');
    }

    /**
     * A council this tenant may change.
     *
     * A shipped row is shared with every tenant, so the first edit copies it here — otherwise one
     * employer's correction would change the levy for everybody on the platform.
     */
    private function ownCouncil(string $uuid): object
    {
        $council = DB::table('bargaining_councils')->where('uuid', $uuid)->first();
        abort_if($council === null, 404);

        $companyId = app(CurrentCompany::class)->id();
        if ($council->company_id !== null || $companyId === null) {
            return $council;
        }

        $copyId = DB::table('bargaining_councils')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
            'name' => $council->name, 'code' => $council->code, 'sector' => $council->sector,
            'region' => $council->region, 'beneficiary_id' => $council->beneficiary_id,
            'active' => $council->active, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (DB::table('bargaining_council_rates')->where('council_id', $council->id)->get() as $rate) {
            $row = (array) $rate;
            unset($row['id']);
            DB::table('bargaining_council_rates')->insert(array_merge($row, [
                'council_id' => $copyId, 'company_id' => $companyId,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        return DB::table('bargaining_councils')->where('id', $copyId)->first();
    }

    private function beneficiaryId(?string $uuid): ?int
    {
        return blank($uuid) ? null : Beneficiary::where('uuid', $uuid)->value('id');
    }
}
