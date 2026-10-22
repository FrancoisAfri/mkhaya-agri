<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\SchemeInstall;
use App\Modules\Agri\Models\SelfAssessment;
use App\Modules\Agri\Support\CertificationTemplates;
use App\Modules\Agri\Support\UiLabels;
use App\Modules\Operations\Sheq\Services\ComplianceSchemes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Ready-made certification checklists (GLOBALG.A.P. IFA, SIZA, organic) on SHEQ's compliance schemes.
 *
 * Installing a template creates the organisation's own SHEQ scheme — clause per control point, the
 * level as its category and the section as its instrument — published and editable in SHEQ like any
 * scheme it wrote itself. Adopting it at a farm puts its clauses in the farm site's legal register.
 *
 * The self-assessment per farm reads every control point with its evidence: the farm records that
 * judge it automatically, documents linked to its register line, and the assessor's own answer.
 * A point is compliant, non-compliant, not applicable or without evidence, and the scheme's
 * readiness is the share compliant per level (GLOBALG.A.P.: all Majors, 95% of Minors).
 */
class CertificationSchemes
{
    public function __construct(private readonly ComplianceSchemes $schemes, private readonly CertificationEvidence $evidence) {}

    /** The templates, with whether each is installed here. */
    public function templates(int $companyId): array
    {
        $installed = SchemeInstall::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('template_code');

        return collect(CertificationTemplates::all())->map(fn ($t, $code) => ['code' => $code, 'name' => $t['name'], 'scheme' => $t['scheme'], 'version' => $t['version'],
            'summary' => $t['summary'], 'points' => count($t['points']), 'levels' => collect($t['points'])->countBy(fn ($p) => $p[2])->all(),
            'automatic' => collect($t['points'])->filter(fn ($p) => array_intersect($p[4], array_keys(CertificationEvidence::types())))->count(),
            'installed' => isset($installed[$code]), 'scheme_id' => $installed[$code]->scheme_id ?? null])->values()->all();
    }

    /** Install a template as the organisation's own published SHEQ scheme. */
    public function install(int $companyId, string $code, User $actor): object
    {
        $t = CertificationTemplates::get($code) ?? throw ValidationException::withMessages(['template' => 'There is no such checklist.']);
        if (SchemeInstall::withoutGlobalScopes()->where('company_id', $companyId)->where('template_code', $code)->exists()) {
            throw ValidationException::withMessages(['template' => "{$t['name']} is already installed; edit your copy in SHEQ › Compliance schemes."]);
        }

        try {
            return DB::transaction(function () use ($companyId, $code, $t, $actor) {
                $scheme = $this->schemes->create($companyId, ['code' => $code, 'name' => $t['name'], 'jurisdiction' => $t['jurisdiction'], 'scheme' => $t['scheme']], $actor->id);
                foreach ($t['points'] as [$ref, $section, $level, $summary, $types, $renew, $role]) {
                    $this->schemes->clause($companyId, $scheme, ['clause' => $ref, 'instrument' => $section, 'requirement' => $summary, 'category' => $level,
                        'evidence_types' => $types, 'review_frequency' => $renew, 'responsible_role' => $role]);
                }
                $this->schemes->publish($companyId, $scheme, $actor->id);
                SchemeInstall::withoutGlobalScopes()->create(['company_id' => $companyId, 'template_code' => $code, 'template_version' => $t['version'],
                    'scheme_id' => $scheme->id, 'installed_by' => $actor->id]);

                return DB::table('sheq_compliance_schemes')->find($scheme->id);
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['template' => $e->getMessage()]);
        }
    }

    /** The organisation's schemes the assessment can be run on (installed templates and its own). */
    public function schemesFor(int $companyId)
    {
        return DB::table('sheq_compliance_schemes')->where('company_id', $companyId)->where('status', 'published')->orderBy('name')->get();
    }

    public function scheme(int $companyId, int $id): object
    {
        return DB::table('sheq_compliance_schemes')->where('company_id', $companyId)->where('id', $id)->first() ?? abort(404);
    }

    /** Adopt the scheme at the farm's SHEQ site, so its clauses are register lines there. */
    public function adopt(Farm $farm, object $scheme, User $actor): array
    {
        $site = app(FarmSafety::class)->site($farm, $actor);
        try {
            return $this->schemes->adopt((int) $farm->company_id, $scheme, $site, $actor->id);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scheme' => $e->getMessage()]);
        }
    }

    public function adopted(Farm $farm, object $scheme): bool
    {
        return $farm->sheq_site_id && DB::table('sheq_compliance_scheme_adoptions')->where('scheme_id', $scheme->id)->where('site_id', $farm->sheq_site_id)->exists();
    }

    /** Record the assessor's answer to a control point. */
    public function answer(Farm $farm, object $scheme, string $clause, string $answer, ?string $comment, User $actor): SelfAssessment
    {
        if (! DB::table('sheq_compliance_scheme_clauses')->where('scheme_id', $scheme->id)->where('clause', $clause)->exists()) {
            throw ValidationException::withMessages(['clause' => 'That control point is not in this scheme.']);
        }
        if ($answer !== 'compliant' && blank($comment)) {
            throw ValidationException::withMessages(['comment' => $answer === 'not_applicable' ? 'Say why the control point does not apply.' : 'Say what is missing and what will be done.']);
        }

        return SelfAssessment::withoutGlobalScopes()->updateOrCreate(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'scheme_id' => $scheme->id, 'clause' => $clause],
            ['answer' => $answer, 'comment' => $comment, 'answered_by' => $actor->id, 'answered_at' => now()]);
    }

    /**
     * The farm's self-assessment of a scheme: every control point with its evidence and status, and
     * the share compliant per level.
     *
     * @return array{points: list<array<string, mixed>>, levels: array<string, array<string, mixed>>, ready: bool, adopted: bool}
     */
    public function assessment(Farm $farm, object $scheme): array
    {
        $install = SchemeInstall::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('scheme_id', $scheme->id)->first();
        $thresholds = $install ? (CertificationTemplates::get($install->template_code)['thresholds'] ?? []) : ['major' => 100, 'minor' => 95];
        $clauses = DB::table('sheq_compliance_scheme_clauses')->where('scheme_id', $scheme->id)->orderBy('clause')->get();
        $answers = SelfAssessment::withoutGlobalScopes()->where('farm_id', $farm->id)->where('scheme_id', $scheme->id)->get()->keyBy('clause');
        $lines = $farm->sheq_site_id ? DB::table('sheq_legal_register_lines')->where('company_id', $farm->company_id)->where('site_id', $farm->sheq_site_id)
            ->where('regulatory_pack_id', ComplianceSchemes::packId($scheme))->whereNull('deleted_at')->get()->keyBy('clause') : collect();
        $docs = $lines->isEmpty() ? collect() : DB::table('sheq_legal_register_evidence as e')->leftJoin('documents as d', 'd.id', '=', 'e.evidence_id')
            ->whereIn('e.line_id', $lines->pluck('id'))->whereNull('e.deleted_at')->where('e.evidence_type', 'DM_DOCUMENT')
            ->get(['e.line_id', 'e.status', 'e.status_detail', 'd.title', 'd.uuid'])->groupBy('line_id');
        $auto = CertificationEvidence::types();
        $cache = [];
        $users = \App\Core\User\Models\User::query()->whereIn('id', $answers->pluck('answered_by')->filter())->get()->mapWithKeys(fn ($u) => [$u->id => $u->name]);

        $points = $clauses->map(function ($c) use ($farm, $answers, $lines, $docs, $auto, &$cache, $users) {
            $types = json_decode($c->evidence_types, true) ?: [];
            $evidence = [];
            foreach (array_intersect($types, array_keys($auto)) as $type) {
                $cache[$type] ??= $this->evidence->evaluate($type, $farm);
                $evidence[] = ['type' => $type, 'label' => $auto[$type], 'auto' => true] + $cache[$type];
            }
            $line = $lines[$c->clause] ?? null;
            foreach ($line ? ($docs[$line->id] ?? []) : [] as $d) {
                $evidence[] = ['type' => 'DM_DOCUMENT', 'label' => $d->title ?? 'Document', 'auto' => false, 'url' => $d->uuid ? "/document-management/documents/{$d->uuid}" : null,
                    'status' => $d->status === 'COMPLIANT' ? 'compliant' : ($d->status === 'NON_COMPLIANT' ? 'non_compliant' : 'no_evidence'), 'detail' => (string) $d->status_detail];
            }
            $a = $answers[$c->clause] ?? null;
            $ev = collect($evidence)->pluck('status');
            $status = match (true) {
                $a?->answer === 'not_applicable' => 'not_applicable',
                $ev->contains('non_compliant') => 'non_compliant',
                $a !== null => $a->answer,
                $ev->isNotEmpty() && $ev->every(fn ($s) => $s === 'compliant') => 'compliant',
                default => 'no_evidence',
            };
            $level = in_array(strtolower((string) $c->category), CertificationTemplates::LEVELS, true) ? strtolower($c->category) : 'other';

            return ['clause' => $c->clause, 'section' => $c->instrument, 'level' => $level, 'requirement' => $c->requirement, 'responsible' => $c->responsible_role,
                'status' => $status, 'evidence' => $evidence, 'needs_document' => in_array('DM_DOCUMENT', $types, true), 'line_uuid' => $line?->uuid,
                'answer' => $a ? ['answer' => $a->answer, 'comment' => $a->comment, 'by' => $users[$a->answered_by] ?? null, 'at' => $a->answered_at?->format('Y-m-d')] : null];
        })->values();

        $levels = [];
        foreach ($points->groupBy('level') as $level => $rows) {
            $applicable = $rows->where('status', '!=', 'not_applicable');
            $ok = $applicable->where('status', 'compliant')->count();
            $pct = $applicable->count() ? round($ok / $applicable->count() * 100, 1) : 100.0;
            $levels[$level] = ['total' => $rows->count(), 'applicable' => $applicable->count(), 'compliant' => $ok,
                'non_compliant' => $applicable->where('status', 'non_compliant')->count(), 'no_evidence' => $applicable->where('status', 'no_evidence')->count(),
                'not_applicable' => $rows->count() - $applicable->count(), 'pct' => $pct, 'required' => $thresholds[$level] ?? null,
                'met' => ! isset($thresholds[$level]) || $pct >= $thresholds[$level]];
        }
        uksort($levels, fn ($a, $b) => array_search($a, [...CertificationTemplates::LEVELS, 'other']) <=> array_search($b, [...CertificationTemplates::LEVELS, 'other']));

        return ['points' => $points->all(), 'levels' => $levels, 'ready' => collect($levels)->every(fn ($l) => $l['met']), 'adopted' => $this->adopted($farm, $scheme)];
    }

    /** The self-assessment as a PDF for the auditor. */
    public function pdf(Farm $farm, object $scheme, User $actor): string
    {
        $a = $this->assessment($farm, $scheme);
        $l = fn (string $k, array $p = []) => e(UiLabels::get("agri.certification.pdf.{$k}", $p));
        $st = fn (string $s) => e(UiLabels::get("agri.certification.status.{$s}"));
        $colour = ['compliant' => '#2e7d32', 'non_compliant' => '#c62828', 'no_evidence' => '#ef6c00', 'not_applicable' => '#757575'];
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#222}h1{font-size:15px;margin:0}h2{font-size:11px;margin:14px 0 4px}'
            .'table{width:100%;border-collapse:collapse}td,th{border:1px solid #bbb;padding:3px;vertical-align:top;text-align:left}th{background:#eee}.muted{color:#666}.s{font-weight:bold}</style></head><body>';
        $html .= '<h1>'.e($scheme->name).'</h1><div class="muted">'.$l('subtitle', ['farm' => $farm->name, 'date' => today()->toDateString(), 'by' => $actor->name]).'</div>';
        $html .= '<p class="muted">'.$l('disclaimer').'</p>';
        $html .= '<h2>'.$l('summary').'</h2><table><tr><th>'.$l('level').'</th><th>'.$l('applicable').'</th><th>'.$l('compliant').'</th><th>'.$l('non_compliant').'</th><th>'
            .$l('no_evidence').'</th><th>'.$l('pct').'</th><th>'.$l('required').'</th></tr>';
        foreach ($a['levels'] as $level => $v) {
            $html .= '<tr><td>'.e(UiLabels::get("agri.certification.levels.{$level}")).'</td><td>'.$v['applicable'].'</td><td>'.$v['compliant'].'</td><td>'.$v['non_compliant'].'</td><td>'
                .$v['no_evidence'].'</td><td class="s" style="color:'.($v['met'] ? '#2e7d32' : '#c62828').'">'.$v['pct'].'%</td><td>'.($v['required'] !== null ? $v['required'].'%' : '—').'</td></tr>';
        }
        $html .= '</table><h2>'.$l('points').'</h2><table><tr><th style="width:8%">'.$l('ref').'</th><th style="width:8%">'.$l('level').'</th><th style="width:32%">'.$l('requirement')
            .'</th><th style="width:10%">'.$l('status').'</th><th>'.$l('evidence').'</th></tr>';
        $section = null;
        foreach ($a['points'] as $p) {
            if ($p['section'] !== $section) {
                $section = $p['section'];
                $html .= '<tr><td colspan="5" style="background:#f6f6f6" class="s">'.e($section).'</td></tr>';
            }
            $ev = collect($p['evidence'])->map(fn ($x) => '<div>• '.e($x['label']).': '.e($x['detail']).'</div>')->implode('');
            if ($p['answer']) {
                $ev .= '<div class="muted">'.$l('assessor', ['by' => $p['answer']['by'] ?? '—', 'at' => $p['answer']['at'] ?? '']).' '.e((string) $p['answer']['comment']).'</div>';
            }
            $html .= '<tr><td>'.e($p['clause']).'</td><td>'.e(UiLabels::get("agri.certification.levels.{$p['level']}")).'</td><td>'.e($p['requirement']).'</td><td class="s" style="color:'
                .($colour[$p['status']] ?? '#222').'">'.$st($p['status']).'</td><td>'.($ev ?: '<span class="muted">—</span>').'</td></tr>';
        }
        $html .= '</table></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }
}
