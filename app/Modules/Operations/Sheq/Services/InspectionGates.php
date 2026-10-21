<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Inspections that gate work.
 *
 * A published inspection template is named as a gate for a kind of work in another module — a
 * category of job (spraying, working at height, hot work). That work may not start until an
 * inspection on the template, carried out for that particular job, has passed. The gate names the
 * template's code, so a new version of the checklist keeps gating without being set up again.
 */
class InspectionGates
{
    public const SUBJECT_WORK_ORDER = 'WORK_ORDER';

    /** @var array<string, array{label: string, categories: array<string, string>}> kinds of work other modules let SHEQ gate */
    private static array $gateable = [];

    /** A module offers its kinds of work for gating: subject type, a label, and category => label. */
    public static function offer(string $subjectType, string $label, array $categories): void
    {
        self::$gateable[$subjectType] = ['label' => $label, 'categories' => $categories];
    }

    /** @return array<string, array{label: string, categories: array<string, string>}> */
    public static function gateables(): array
    {
        return self::$gateable;
    }

    public function gate(int $companyId, string $templateCode, string $subjectType, string $category, ?int $actorId): void
    {
        if (! DB::table('sheq_inspection_templates')->where('company_id', $companyId)->where('code', $templateCode)->where('status', 'PUBLISHED')->exists()) {
            throw new InvalidArgumentException('Only a published inspection template can gate work.');
        }
        DB::table('sheq_inspection_gates')->updateOrInsert(['company_id' => $companyId, 'template_code' => $templateCode, 'subject_type' => $subjectType, 'category' => $category],
            ['uuid' => (string) Str::uuid(), 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function ungate(int $companyId, string $templateCode, string $subjectType, string $category): void
    {
        DB::table('sheq_inspection_gates')->where(['company_id' => $companyId, 'template_code' => $templateCode, 'subject_type' => $subjectType, 'category' => $category])->delete();
    }

    /** @return list<object{code: string, title: string, id: int}> the published templates this work must pass */
    public function required(int $companyId, string $subjectType, string $category): array
    {
        $codes = DB::table('sheq_inspection_gates')->where('company_id', $companyId)->where('subject_type', $subjectType)->where('category', $category)->pluck('template_code');

        return DB::table('sheq_inspection_templates')->where('company_id', $companyId)->whereIn('code', $codes)->where('status', 'PUBLISHED')->whereNull('deleted_at')
            ->get(['id', 'code', 'title'])->all();
    }

    /** The most recent inspection on any version of the template, for this piece of work. */
    public function latest(int $companyId, string $templateCode, string $subjectType, int $subjectId): ?object
    {
        return DB::table('sheq_inspections as i')->join('sheq_inspection_templates as t', 't.id', '=', 'i.template_id')
            ->where('i.company_id', $companyId)->where('t.code', $templateCode)->where('i.subject_type', $subjectType)->where('i.subject_id', $subjectId)
            ->whereNull('i.deleted_at')->orderByDesc('i.completed_at')->orderByDesc('i.id')->first(['i.uuid', 'i.reference', 'i.passed', 'i.completed_at']);
    }

    /**
     * The one-off schedule an inspector opens to carry out a gating inspection for a piece of work —
     * SHEQ's own inspection form, with the work as its subject. Made once per job and template.
     */
    public function scheduleFor(int $companyId, string $templateCode, string $subjectType, int $subjectId, ?int $siteId): string
    {
        $template = DB::table('sheq_inspection_templates')->where('company_id', $companyId)->where('code', $templateCode)->where('status', 'PUBLISHED')
            ->whereNull('deleted_at')->orderByDesc('version')->first(['id']);
        if (! $template) {
            throw new InvalidArgumentException('That inspection template is not published.');
        }
        $existing = DB::table('sheq_inspection_schedules')->where('company_id', $companyId)->where('template_id', $template->id)
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)->whereNull('deleted_at')->value('uuid');
        if ($existing) {
            return $existing;
        }
        $uuid = (string) Str::uuid();
        DB::table('sheq_inspection_schedules')->insert(['uuid' => $uuid, 'company_id' => $companyId, 'template_id' => $template->id, 'site_id' => $siteId,
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'trigger_type' => 'EVENT', 'next_due' => today()->toDateString(),
            'qr_code' => 'INS'.substr(hash('sha256', $companyId.'|'.$uuid), 0, 14), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $uuid;
    }

    /** What still stands between this work and starting: a gate with no inspection, or a failed one. */
    public function blocking(int $companyId, string $subjectType, string $category, int $subjectId): array
    {
        $out = [];
        foreach ($this->required($companyId, $subjectType, $category) as $t) {
            $i = $this->latest($companyId, $t->code, $subjectType, $subjectId);
            if (! $i) {
                $out[] = "Complete the \"{$t->title}\" inspection first.";
            } elseif (! $i->passed) {
                $out[] = "The \"{$t->title}\" inspection ({$i->reference}) did not pass; fix what failed and inspect again.";
            }
        }

        return $out;
    }
}
