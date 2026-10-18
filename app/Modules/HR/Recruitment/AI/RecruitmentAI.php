<?php

namespace App\Modules\HR\Recruitment\AI;

use App\Core\AI\Base\BaseModuleAI;
use App\Shared\DTOs\AgentResponse;
use InvalidArgumentException;
use RuntimeException;

class RecruitmentAI extends BaseModuleAI
{
    protected function module(): string
    {
        return 'recruitment';
    }

    /**
     * Screen a candidate's resume against a job description.
     */
    public function screenResume(array $resume, array $jobDescription, int $maxTokens = 2048): AgentResponse
    {
        $this->validateResume($resume);
        $this->validateJobDescription($jobDescription);

        // Pre‑filter: check must‑have skills
        $missingMustHave = $this->checkMustHaveSkills($resume, $jobDescription);
        if (!empty($missingMustHave)) {
            return new AgentResponse([
                'match_score'     => 0,
                'recommendation'  => 'reject',
                'reasons'         => ['Missing must‑have skills: ' . implode(', ', $missingMustHave)],
                'strengths'       => [],
                'weaknesses'      => $missingMustHave,
                'skill_gaps'      => $missingMustHave,
                'cultural_fit_notes' => '',
            ]);
        }

        $systemPrompt = <<<'SYS'
        You are an experienced recruitment analyst. Screen the candidate's resume against the job description.
        Respond in valid JSON:
        {
          "match_score": 0,
          "recommendation": "reject|interview|strong_consider",
          "reasons": [],
          "strengths": [],
          "weaknesses": [],
          "skill_gaps": [],
          "cultural_fit_notes": ""
        }
        SYS;

        try {
            return $this->ask(
                prompt: 'Evaluate how well this candidate matches the job requirements. Be objective and thorough.',
                context: ['resume' => $resume, 'job_description' => $jobDescription],
                options: ['maxTokens' => $maxTokens],
                systemPrompt: $systemPrompt
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse('Resume screening failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate tailored interview questions.
     */
    public function generateInterviewQuestions(array $jobDescription, array $resume = [], int $maxTokens = 2048): AgentResponse
    {
        $this->validateJobDescription($jobDescription);
        if (!empty($resume)) {
            $this->validateResume($resume);
        }

        $systemPrompt = <<<'SYS'
        You are a hiring manager preparing a structured interview. Generate relevant questions.
        Respond in valid JSON:
        {
          "questions": [
            {
              "type": "technical|behavioral|cultural|situational",
              "question": "...",
              "expected_keywords": [],
              "difficulty": "easy|medium|hard"
            }
          ],
          "total_questions": 0
        }
        SYS;

        try {
            return $this->ask(
                prompt: 'Create interview questions that assess the candidate\'s suitability for this role. ' .
                'Tailor questions to the job description' . ($resume ? ' and the candidate\'s background.' : '.'),
                context: ['resume' => $resume, 'job_description' => $jobDescription],
                options: ['maxTokens' => $maxTokens],
                systemPrompt: $systemPrompt
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse('Question generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Evaluate a candidate's answers to interview questions.
     */
    public function evaluateInterviewAnswers(array $answers, array $jobDescription, array $idealAnswers = [], int $maxTokens = 2048): AgentResponse
    {
        $this->validateJobDescription($jobDescription);
        if (empty($answers)) {
            throw new InvalidArgumentException('Answers cannot be empty.');
        }

        $systemPrompt = <<<'SYS'
        You are an expert interviewer. Evaluate the candidate's answers against the job requirements.
        Respond in valid JSON:
        {
          "overall_score": 0,
          "question_scores": [
            {
              "question": "...",
              "score": 0,
              "feedback": "...",
              "strengths": [],
              "areas_for_improvement": []
            }
          ],
          "recommendation": "reject|proceed|strong_proceed",
          "summary": ""
        }
        SYS;

        try {
            return $this->ask(
                prompt: 'Assess these interview answers. Provide constructive feedback and an overall recommendation.',
                context: [
                    'answers'         => $answers,
                    'job_description' => $jobDescription,
                    'ideal_answers'   => $idealAnswers,
                ],
                options: ['maxTokens' => $maxTokens],
                systemPrompt: $systemPrompt
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse('Answer evaluation failed: ' . $e->getMessage());
        }
    }

    /**
     * Score the overall candidate fit.
     */
    public function scoreCandidateFit(array $candidateProfile, array $jobDescription, int $maxTokens = 2048): AgentResponse
    {
        $this->validateJobDescription($jobDescription);
        if (!isset($candidateProfile['resume'])) {
            throw new InvalidArgumentException('Candidate profile must contain resume data.');
        }

        $systemPrompt = <<<'SYS'
        You are a recruitment director. Synthesize all available information to give a final candidate fit score.
        Respond in valid JSON:
        {
          "final_score": 0,
          "fit_rating": "low|moderate|high|excellent",
          "strengths": [],
          "development_areas": [],
          "recommendation": "hire|no_hire|further_interview",
          "estimated_retention_risk": "low|medium|high",
          "justification": ""
        }
        SYS;

        try {
            return $this->ask(
                prompt: 'Provide a final assessment of the candidate\'s fit for the role, considering all data provided.',
                context: ['candidate' => $candidateProfile, 'job' => $jobDescription],
                options: ['maxTokens' => $maxTokens],
                systemPrompt: $systemPrompt
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse('Candidate fit scoring failed: ' . $e->getMessage());
        }
    }

    /**
     * Parse raw CV text and extract structured profile data.
     */
    public function parseCvText(string $cvText, int $maxTokens = 4096): AgentResponse
    {
        if (empty(trim($cvText))) {
            throw new \InvalidArgumentException('CV text cannot be empty.');
        }

        $systemPrompt = <<<'SYS'
    You are a CV parsing specialist. Extract structured data from the CV text.
    Respond ONLY in valid JSON with this exact structure (no markdown, no explanation):
    {
      "full_name": "",
      "email": "",
      "phone": "",
      "summary": "",
      "skills": [
        {"skill": "", "proficiency_level": "beginner|intermediate|advanced|expert", "years_experience": 0}
      ],
      "experience": [
        {"company_name": "", "job_title": "", "start_date": "YYYY-MM-DD or YYYY-MM", "end_date": "YYYY-MM-DD or YYYY-MM or null", "is_current": false, "description": ""}
      ],
      "qualifications": [
        {"institution": "", "qualification_type": "certificate|diploma|degree|honours|masters|doctoral|other", "field_of_study": "", "year_completed": 0}
      ],
      "certifications": [
        {"name": "", "issuing_body": "", "year_issued": 0}
      ],
      "flags": {
        "employment_gaps": false,
        "short_tenures": false,
        "concerns": []
      },
      "confidence_score": 0.95
    }
    SYS;

        try {
            return $this->ask(
                prompt: "Parse this CV and extract all structured information:\n\n" . substr($cvText, 0, 12000),
                context: [],
                options: ['maxTokens' => $maxTokens],
                systemPrompt: $systemPrompt
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse('CV parsing failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateResume(array $resume): void
    {
        if (empty($resume['text']) && empty($resume['skills'])) {
            throw new InvalidArgumentException('Resume must contain either text or skills array.');
        }
    }

    private function validateJobDescription(array $job): void
    {
        if (empty($job['required_skills']) && empty($job['description'])) {
            throw new InvalidArgumentException('Job description must contain required_skills or description.');
        }
    }

    private function checkMustHaveSkills(array $resume, array $job): array
    {
        $mustHave = $job['must_have_skills'] ?? [];
        if (empty($mustHave)) {
            return [];
        }

        $candidateSkills = array_map('strtolower', $resume['skills'] ?? []);
        $missing = [];
        foreach ($mustHave as $skill) {
            if (!in_array(strtolower($skill), $candidateSkills, true)) {
                $missing[] = $skill;
            }
        }
        return $missing;
    }

    private function errorResponse(string $message): AgentResponse
    {
        return new AgentResponse([
            'error'   => true,
            'message' => $message,
        ]);
    }
}
