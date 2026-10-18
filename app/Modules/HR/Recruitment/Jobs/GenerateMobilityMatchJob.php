<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\HR\Recruitment\Enums\MobilityType;
use App\Modules\HR\Recruitment\Models\EmployeeSkillProfile;
use App\Modules\HR\Recruitment\Models\InternalMobilityMatch;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GenerateMobilityMatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * Named `vacancy`, not `job`, and it cannot go back.
     *
     * The queue's own Queueable trait declares `public $job` for the queue job instance. Promoting
     * a vacancy into a property of the same name made the two definitions collide, and PHP refuses
     * to compose the class at all — so this fatalled the moment anything loaded it, which means it
     * has never once run. The dispatch site passes positionally, so the name is free to change.
     */
    public function __construct(public readonly Job $vacancy)
    {
        // Set here rather than as a `public string $queue` property: Queueable already declares an
        // untyped $queue, and a typed redeclaration is the same composition conflict as above.
        $this->onQueue('ai-fast');
    }

    public function handle(): void
    {
        $requirements = strtolower($this->vacancy->requirements ?? '');

        if (empty($requirements)) {
            Log::info("GenerateMobilityMatchJob: job #{$this->vacancy->id} has no requirements, skipping.");
            return;
        }

        $candidateMap = HrPerson::whereNotNull('candidate_id')
            ->whereNotNull('user_id')
            ->pluck('candidate_id', 'user_id');

        if ($candidateMap->isEmpty()) {
            return;
        }

        $profilesByUser = EmployeeSkillProfile::whereIn('user_id', $candidateMap->keys())
            ->get()
            ->groupBy('user_id');

        $matched = 0;

        foreach ($profilesByUser as $userId => $skills) {
            $candidateId = $candidateMap[$userId] ?? null;
            if (! $candidateId) {
                continue;
            }

            $matchScore = $this->scoreSkills($skills, $requirements);
            $matchType  = $this->resolveMatchType($matchScore);

            InternalMobilityMatch::updateOrCreate(
                ['candidate_id' => $candidateId, 'job_id' => $this->vacancy->id],
                [
                    'match_score'  => $matchScore,
                    'match_type'   => $matchType->value,
                    'ai_generated' => false,
                    'generated_at' => now(),
                ]
            );

            $matched++;
        }

        Log::info("GenerateMobilityMatchJob: scored {$matched} internal candidate(s) for job #{$this->vacancy->id} ({$this->vacancy->title})");
    }

    private function scoreSkills(Collection $skills, string $requirements): float
    {
        if ($skills->isEmpty()) {
            return 0.0;
        }

        $keywordMatches = 0;
        $proficiencyBonus = 0.0;

        foreach ($skills as $skill) {
            $skillName = strtolower(trim($skill->skill));

            if ($skillName !== '' && str_contains($requirements, $skillName)) {
                $keywordMatches++;
            }

            $proficiencyBonus += match (strtolower($skill->proficiency_level ?? '')) {
                'expert'       => 5.0,
                'advanced'     => 3.0,
                'intermediate' => 1.0,
                default        => 0.0,
            };
        }

        $keywordScore = ($keywordMatches / $skills->count()) * 70;
        $bonus        = min($proficiencyBonus, 30.0);

        return min(100.0, round($keywordScore + $bonus, 2));
    }

    private function resolveMatchType(float $score): MobilityType
    {
        return match (true) {
            $score >= 85 => MobilityType::Promotion,
            $score >= 45 => MobilityType::Lateral,
            default      => MobilityType::Transfer,
        };
    }
}
