<?php

namespace App\Modules\HR\LMS\Services;

use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\User;
use App\Modules\HR\LMS\Models\LmsCourseSkill;
use App\Modules\HR\LMS\Models\LmsEmployeeSkill;
use App\Modules\HR\LMS\Models\LmsEmployeeSkillGap;
use App\Modules\HR\LMS\Models\LmsJobTitleSkill;
use App\Modules\HR\LMS\Models\LmsQuestionSkillMapping;
use App\Modules\HR\LMS\Models\LmsQuestionResponse;
use App\Modules\HR\LMS\Models\LmsSkill;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LmsSkillsService
{
    // ── Skills library ─────────────────────────────────────────────────────

    public function createSkill(array $data, int $createdBy): LmsSkill
    {
        return LmsSkill::create([
            'name'        => $data['name'],
            'slug'        => Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'category'    => $data['category'] ?? null,
            'max_level'   => $data['max_level'] ?? 5,
            'level_labels'=> $data['level_labels'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
            'created_by'  => $createdBy,
        ]);
    }

    // ── Mappings ───────────────────────────────────────────────────────────

    public function mapSkillToJobTitle(int $skillId, int $positionId, int $requiredLevel = 3, bool $mandatory = true): LmsJobTitleSkill
    {
        return LmsJobTitleSkill::updateOrCreate(
            ['skill_id' => $skillId, 'position_id' => $positionId],
            ['required_level' => $requiredLevel, 'is_mandatory' => $mandatory]
        );
    }

    public function unmapSkillFromJobTitle(int $skillId, int $positionId): void
    {
        LmsJobTitleSkill::where('skill_id', $skillId)->where('position_id', $positionId)->delete();
    }

    public function mapSkillToCourse(int $skillId, int $courseId, int $levelGained = 1): LmsCourseSkill
    {
        return LmsCourseSkill::updateOrCreate(
            ['skill_id' => $skillId, 'course_id' => $courseId],
            ['skill_level_gained' => $levelGained]
        );
    }

    public function unmapSkillFromCourse(int $skillId, int $courseId): void
    {
        LmsCourseSkill::where('skill_id', $skillId)->where('course_id', $courseId)->delete();
    }

    public function mapQuestionToSkill(int $questionId, int $skillId): LmsQuestionSkillMapping
    {
        return LmsQuestionSkillMapping::firstOrCreate(
            ['question_id' => $questionId, 'skill_id' => $skillId]
        );
    }

    public function unmapQuestionFromSkill(int $questionId, int $skillId): void
    {
        LmsQuestionSkillMapping::where('question_id', $questionId)->where('skill_id', $skillId)->delete();
    }

    // ── Skill gap analysis ─────────────────────────────────────────────────

    public function calculateSkillGap(int $userId, int $positionId): array
    {
        $required  = LmsJobTitleSkill::with('skill')
            ->where('position_id', $positionId)
            ->get();

        if ($required->isEmpty()) {
            return ['gaps' => [], 'readiness_pct' => 100, 'gap_count' => 0];
        }

        $empSkills = LmsEmployeeSkill::where('user_id', $userId)
            ->pluck('current_level', 'skill_id');

        $gaps      = [];
        $metCount  = 0;

        foreach ($required as $req) {
            $current  = $empSkills[$req->skill_id] ?? 0;
            $gap      = max(0, $req->required_level - $current);
            $meets    = $current >= $req->required_level;

            if ($meets) {
                $metCount++;
            }

            $gaps[] = [
                'skill_id'       => $req->skill_id,
                'skill_name'     => $req->skill?->name,
                'required_level' => $req->required_level,
                'current_level'  => $current,
                'gap'            => $gap,
                'meets'          => $meets,
                'is_mandatory'   => $req->is_mandatory,
            ];
        }

        $readinessPct = round(($metCount / count($required)) * 100, 1);

        // Persist gaps (upsert)
        $this->persistSkillGaps($userId, $positionId, $gaps);

        return [
            'gaps'          => $gaps,
            'readiness_pct' => $readinessPct,
            'gap_count'     => count($required) - $metCount,
            'total_required'=> count($required),
        ];
    }

    public function calculateSkillGapsForPosition(int $positionId): array
    {
        // All employees with this position
        $users = User::whereHas('person', fn ($q) => $q->where('position_id', $positionId))
            ->with('person')
            ->get();

        $results = [];
        foreach ($users as $user) {
            $result  = $this->calculateSkillGap($user->id, $positionId);
            $person  = $user->person;
            $results[] = [
                'user_id'       => $user->id,
                'name'          => $person ? trim("{$person->first_name} {$person->surname}") : $user->email,
                'readiness_pct' => $result['readiness_pct'],
                'gap_count'     => $result['gap_count'],
                'gaps'          => $result['gaps'],
            ];
        }

        // Sort by readiness desc
        usort($results, fn ($a, $b) => $b['readiness_pct'] <=> $a['readiness_pct']);

        return $results;
    }

    // ── Skill update after training ────────────────────────────────────────

    public function updateEmployeeSkillAfterPass(int $userId, int $courseId): void
    {
        $courseSkills = LmsCourseSkill::where('course_id', $courseId)->get();

        foreach ($courseSkills as $cs) {
            $existing = LmsEmployeeSkill::where('user_id', $userId)
                ->where('skill_id', $cs->skill_id)
                ->first();

            if (!$existing || $existing->current_level < $cs->skill_level_gained) {
                LmsEmployeeSkill::updateOrCreate(
                    ['user_id' => $userId, 'skill_id' => $cs->skill_id],
                    [
                        'current_level'    => max($existing?->current_level ?? 0, $cs->skill_level_gained),
                        'source'           => 'training',
                        'last_assessed_at' => now(),
                    ]
                );
            }
        }

        // Re-close any now-resolved gaps
        $this->refreshGapStatuses($userId);
    }

    public function updateEmployeeSkillAfterAssessment(int $userId, int $questionnaireId): void
    {
        // Find correctly-answered responses for this attempt's questionnaire
        $correctResponses = LmsQuestionResponse::whereHas(
            'attempt',
            fn ($q) => $q->where('questionnaire_id', $questionnaireId)->where('user_id', $userId)
        )
            ->where('is_correct', true)
            ->pluck('question_id')
            ->unique();

        // Find skills linked to those questions
        $skillUpdates = LmsQuestionSkillMapping::whereIn('question_id', $correctResponses)
            ->with('skill')
            ->get()
            ->groupBy('skill_id');

        foreach ($skillUpdates as $skillId => $mappings) {
            LmsEmployeeSkill::updateOrCreate(
                ['user_id' => $userId, 'skill_id' => $skillId],
                [
                    'current_level'    => max(1, $mappings->count()),
                    'source'           => 'assessment',
                    'last_assessed_at' => now(),
                ]
            );
        }

        $this->refreshGapStatuses($userId);
    }

    // ── Matrix generation ──────────────────────────────────────────────────

    public function generateSkillsMatrix(array $filters = []): array
    {
        // Get skills to show (active, optionally filtered by category)
        $skillsQuery = LmsSkill::active()->orderBy('category')->orderBy('name');
        if (!empty($filters['category'])) {
            $skillsQuery->where('category', $filters['category']);
        }
        if (!empty($filters['skill_ids'])) {
            $skillsQuery->whereIn('id', $filters['skill_ids']);
        }
        $skills = $skillsQuery->get();

        if ($skills->isEmpty()) {
            return ['skills' => [], 'employees' => [], 'summary' => []];
        }

        // Get employees (optionally filtered by position)
        $userQuery = User::with('person.hrPosition')
            ->whereHas('person');

        if (!empty($filters['position_id'])) {
            $userQuery->whereHas('person', fn ($q) => $q->where('position_id', $filters['position_id']));
        }

        $users = $userQuery->limit(200)->get();

        if ($users->isEmpty()) {
            return ['skills' => $skills->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'category' => $s->category])->all(), 'employees' => [], 'summary' => []];
        }

        $userIds    = $users->pluck('id');
        $skillIds   = $skills->pluck('id');

        // Load all employee skill levels in one query
        $empSkills = LmsEmployeeSkill::whereIn('user_id', $userIds)
            ->whereIn('skill_id', $skillIds)
            ->get()
            ->groupBy('user_id');

        // Load required levels per position for each user
        $positionSkills = LmsJobTitleSkill::whereIn('position_id',
            $users->pluck('person.position_id')->filter()->unique()
        )
            ->whereIn('skill_id', $skillIds)
            ->get()
            ->groupBy(fn ($r) => "{$r->position_id}_{$r->skill_id}");

        $employees = [];
        foreach ($users as $user) {
            $person     = $user->person;
            $positionId = $person?->position_id;
            $userSkillMap = $empSkills->get($user->id, collect())->keyBy('skill_id');

            $skillRow = [];
            foreach ($skills as $skill) {
                $empSkill    = $userSkillMap->get($skill->id);
                $current     = $empSkill?->current_level ?? 0;
                $reqKey      = "{$positionId}_{$skill->id}";
                $required    = $positionSkills->has($reqKey) ? $positionSkills->get($reqKey)->first()?->required_level : null;

                $status = 'na';
                if ($required !== null) {
                    $status = match (true) {
                        $current >= $required              => 'meets',
                        $current > 0 && $current < $required => 'partial',
                        default                            => 'gap',
                    };
                } elseif ($current > 0) {
                    $status = 'has';
                }

                $skillRow[$skill->id] = [
                    'current'  => $current,
                    'required' => $required,
                    'status'   => $status,
                    'verified' => $empSkill?->verified ?? false,
                ];
            }

            $employees[] = [
                'user_id'      => $user->id,
                'name'         => $person ? trim("{$person->first_name} {$person->surname}") : $user->email,
                'job_title'    => $person?->hrPosition?->name ?? '—',
                'position_id'  => $positionId,
                'skills'       => $skillRow,
                'meets_count'  => collect($skillRow)->where('status', 'meets')->count(),
                'gap_count'    => collect($skillRow)->where('status', 'gap')->count(),
            ];
        }

        // Summary per skill
        $summary = [];
        foreach ($skills as $skill) {
            $all    = collect($employees)->pluck("skills.{$skill->id}");
            $meets  = $all->where('status', 'meets')->count();
            $gaps   = $all->where('status', 'gap')->count();
            $total  = $all->whereNotIn('status', ['na'])->count();

            $summary[$skill->id] = [
                'meets'     => $meets,
                'gaps'      => $gaps,
                'total'     => $total,
                'gap_rate'  => $total > 0 ? round(($gaps / $total) * 100, 0) : 0,
            ];
        }

        return [
            'skills'    => $skills->map(fn ($s) => [
                'id'       => $s->id,
                'name'     => $s->name,
                'category' => $s->category,
                'max_level'=> $s->max_level,
            ])->all(),
            'employees' => $employees,
            'summary'   => $summary,
        ];
    }

    public function generateSuccessionReadiness(int $targetPositionId): array
    {
        $position = HrPosition::findOrFail($targetPositionId);
        $required = LmsJobTitleSkill::with('skill')
            ->where('position_id', $targetPositionId)
            ->get();

        if ($required->isEmpty()) {
            return ['position' => $position->name, 'required_skills' => [], 'candidates' => []];
        }

        // All active users (not already in this position)
        $users = User::with('person.hrPosition')
            ->whereHas('person', fn ($q) => $q->where('position_id', '!=', $targetPositionId))
            ->limit(200)
            ->get();

        $skillIds  = $required->pluck('skill_id');
        $userIds   = $users->pluck('id');

        $empSkills = LmsEmployeeSkill::whereIn('user_id', $userIds)
            ->whereIn('skill_id', $skillIds)
            ->get()
            ->groupBy('user_id');

        $candidates = [];
        foreach ($users as $user) {
            $person       = $user->person;
            $userSkillMap = $empSkills->get($user->id, collect())->keyBy('skill_id');
            $gapDetails   = [];
            $metCount     = 0;

            foreach ($required as $req) {
                $current = $userSkillMap->get($req->skill_id)?->current_level ?? 0;
                $gap     = max(0, $req->required_level - $current);
                if ($current >= $req->required_level) $metCount++;

                $gapDetails[] = [
                    'skill_id'       => $req->skill_id,
                    'skill_name'     => $req->skill?->name,
                    'required_level' => $req->required_level,
                    'current_level'  => $current,
                    'gap'            => $gap,
                    'is_mandatory'   => $req->is_mandatory,
                ];
            }

            $readiness = round(($metCount / $required->count()) * 100, 0);

            $candidates[] = [
                'user_id'       => $user->id,
                'name'          => $person ? trim("{$person->first_name} {$person->surname}") : $user->email,
                'current_title' => $person?->hrPosition?->name ?? '—',
                'readiness_pct' => $readiness,
                'gap_count'     => $required->count() - $metCount,
                'gaps'          => $gapDetails,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['readiness_pct'] <=> $a['readiness_pct']);

        return [
            'position'        => $position->name,
            'position_id'     => $position->id,
            'required_skills' => $required->map(fn ($r) => [
                'skill_id'       => $r->skill_id,
                'skill_name'     => $r->skill?->name,
                'required_level' => $r->required_level,
                'is_mandatory'   => $r->is_mandatory,
            ])->all(),
            'candidates' => $candidates,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function persistSkillGaps(int $userId, int $positionId, array $gaps): void
    {
        foreach ($gaps as $gap) {
            if ($gap['meets']) {
                // Close any open gap
                LmsEmployeeSkillGap::where('user_id', $userId)
                    ->where('skill_id', $gap['skill_id'])
                    ->where('position_id', $positionId)
                    ->where('status', '!=', 'closed')
                    ->update(['status' => 'closed', 'closed_at' => now()]);
            } else {
                // Find a recommended course
                $recommendedCourse = LmsCourseSkill::where('skill_id', $gap['skill_id'])
                    ->where('skill_level_gained', '>=', $gap['required_level'])
                    ->value('course_id');

                LmsEmployeeSkillGap::updateOrCreate(
                    ['user_id' => $userId, 'skill_id' => $gap['skill_id'], 'position_id' => $positionId],
                    [
                        'current_level'        => $gap['current_level'],
                        'required_level'       => $gap['required_level'],
                        'gap'                  => $gap['gap'],
                        'recommended_course_id'=> $recommendedCourse,
                        'status'               => 'open',
                        'identified_at'        => now(),
                        'closed_at'            => null,
                    ]
                );
            }
        }
    }

    private function refreshGapStatuses(int $userId): void
    {
        $openGaps = LmsEmployeeSkillGap::where('user_id', $userId)
            ->where('status', '!=', 'closed')
            ->with('skill')
            ->get();

        $empSkills = LmsEmployeeSkill::where('user_id', $userId)
            ->pluck('current_level', 'skill_id');

        foreach ($openGaps as $gapRecord) {
            $current = $empSkills[$gapRecord->skill_id] ?? 0;
            if ($current >= $gapRecord->required_level) {
                $gapRecord->update(['status' => 'closed', 'closed_at' => now(), 'current_level' => $current]);
            } else {
                $gapRecord->update(['current_level' => $current, 'gap' => $gapRecord->required_level - $current]);
            }
        }
    }
}
