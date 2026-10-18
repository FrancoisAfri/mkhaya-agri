# PHASE 9: AI Copilot & Decision Advisor Guide

## Overview

The AI Copilot is an intelligent policy assistant that uses Retrieval-Augmented Generation (RAG) to answer policy questions with accurate context. The Decision Advisor predicts compliance outcomes and recommends actions to maximize approval probability.

## Problem Solved

**Challenge:** Users lack quick access to policy information and struggle to predict if proposed actions will be approved or violate policy. They need intelligent assistance to navigate complex compliance requirements.

**Solution:** AI-powered assistant that:
- Answers policy questions in natural language
- Retrieves relevant policy clauses with citations
- Predicts approval/rejection probability
- Suggests alternatives when actions have high rejection risk
- Recommends compliance improvements
- Provides decision-making context before submitting requests

## Architecture

### Tech Stack

- **LLM**: OpenAI GPT-4 Turbo (or gpt-3.5-turbo for faster responses)
- **Vector Store**: Can use OpenAI embeddings + database, or Pinecone/Weaviate
- **Context Window**: 8,000 tokens for policy context
- **Response Format**: JSON with references to specific policy clauses

### Core Services

#### PolicyCopilot Service

RAG-based policy question answering engine.

```php
class PolicyCopilot {
    private Client $openai;
    private const MAX_CONTEXT_LENGTH = 8000; // tokens
    private const MODEL = 'gpt-4-turbo-preview'; // or 'gpt-3.5-turbo'

    /**
     * Answer question about policies with context
     */
    public function askQuestion(
        string $question,
        ?int $policyId = null
    ): array {
        // Retrieve relevant policies (RAG retrieval)
        $context = $this->retrieveContext($question, $policyId);
        
        // Build prompt with policy context
        $prompt = $this->buildPrompt($question, $context);
        
        // Call OpenAI API
        $response = $this->openai->chat()->create([
            'model' => self::MODEL,
            'messages' => [[
                'role' => 'system',
                'content' => $this->getSystemPrompt(),
            ], [
                'role' => 'user',
                'content' => $prompt,
            ]],
        ]);
        
        return [
            'answer' => $response->choices[0]->message->content,
            'confidence' => $this->calculateConfidence(...),
            'references' => $context['references'],
        ];
    }

    /**
     * Search policies by keyword
     */
    public function searchPolicies(string $query): array

    /**
     * Get policies by topic/category
     */
    public function getPoliciesByTopic(string $topic): array

    /**
     * Retrieve relevant context for question
     */
    private function retrieveContext(
        string $question,
        ?int $policyId = null
    ): array {
        // 1. Extract keywords from question
        $keywords = $this->extractKeywords($question);
        
        // 2. Search database for relevant policies
        $policies = Policy::whereIn('keyword', $keywords)
            ->with('clauses', 'rules')
            ->limit(5)
            ->get();
        
        // 3. Build context string from policies + clauses
        $context = $this->buildContextString($policies);
        
        // 4. Return context + references for citation
        return [
            'context' => $context,
            'policies' => $policies,
            'references' => [...],
        ];
    }

    /**
     * Build prompt with system instructions and context
     */
    private function buildPrompt(string $question, array $context): string {
        return <<<PROMPT
You are a helpful policy advisor. Answer the user's question using ONLY 
the provided policy context.

{$context['context']}

USER QUESTION: {$question}

Provide a clear, concise answer. If the answer is not in the provided policies, 
say so. Include relevant policy references.
PROMPT;
    }
}
```

#### DecisionAdvisor Service

Decision intelligence and outcome prediction engine.

```php
class DecisionAdvisor {
    /**
     * Advise on proposed action
     * 
     * Returns:
     * - Approval probability (0-1)
     * - Risk factors
     * - Conditions for approval
     * - Suggested alternatives
     */
    public function adviseOnAction(
        int $userId,
        int $policyId,
        array $actionData
    ): array {
        $user = User::findOrFail($userId);
        $policy = Policy::findOrFail($policyId);
        $riskProfile = $this->riskProfiler->getProfile($userId);
        $violations = $this->getRecentViolations($userId, $policyId, days: 90);

        $approval_probability = $this->calculateApprovalProbability(
            $user, $policy, $violations, $riskProfile, $actionData
        );

        return [
            'approval_probability' => $approval_probability,  // 0.75 = 75% likely to approve
            'recommendation' => $this->generateRecommendation(...),
            'risk_factors' => $this->identifyRiskFactors(...),
            'approval_conditions' => $this->getApprovalConditions(...),
            'alternatives' => $this->suggestAlternatives(...),
        ];
    }

    /**
     * Predict outcome of policy submission
     */
    public function predictOutcome(
        int $userId,
        int $policyId,
        array $submissionData
    ): array {
        // Analyze submission data
        // Return: approved | rejected | pending
        // With: Expected timeline, required documentation, next steps
    }

    /**
     * Get compliance recommendations
     */
    public function getComplianceRecommendations(
        int $userId,
        int $policyId
    ): array {
        // Based on user's history and risk profile
        // Return: Array of recommended actions
    }

    /**
     * Get top rejection reasons for policy
     */
    public function getRejectionReasons(
        int $policyId,
        int $limit = 5
    ): array {
        // Analyze violation patterns
        // Return: Most common rejection reasons
    }

    /**
     * Calculate approval probability
     * 
     * Algorithm:
     * - 30% user compliance history
     * - 25% user risk profile
     * - 20% policy leniency
     * - 15% data quality
     * - 10% timing/context
     */
    private function calculateApprovalProbability(
        User $user,
        Policy $policy,
        $violations,
        array $riskProfile,
        array $actionData
    ): float {
        $score = 0.5; // Base score

        // Factor 1: Compliance history (30%)
        $compliance = max(0, 1 - ($violations->count() / 10));
        $score += $compliance * 0.3;

        // Factor 2: Risk profile (25%)
        $risk = 1 - ($riskProfile['risk_score'] / 100);
        $score += $risk * 0.25;

        // Factor 3: Policy leniency (20%)
        $policy_leniency = 1 - ($policy->approval_strictness ?? 0.5);
        $score += $policy_leniency * 0.2;

        // Factor 4: Data quality (15%)
        $data_quality = $this->assessDataQuality($actionData);
        $score += $data_quality * 0.15;

        // Factor 5: Timing (10%)
        $timing = $this->assessTiming($actionData);
        $score += $timing * 0.1;

        return min(max($score, 0), 1.0);
    }
}
```

## Conversation Flows

### Flow 1: "Can I..." Question

```
User: "Can I take leave for 3 weeks?"

Copilot:
1. Extract question intent: Leave policy compliance check
2. Retrieve: Leave policy + annual leave clause + absence rules
3. Analyze user context: User has taken 2 weeks already this year
4. Query: getRejectionReasons(leave_policy_id)
   → Most common rejection: "Exceeds annual allocation"
5. Answer with context:
   "Based on our annual leave policy, you can take up to X weeks per year.
   You've already taken 2 weeks, so you have Z weeks remaining.
   Your request for 3 weeks exceeds your available balance.
   
   Instead, consider:
   - Unpaid leave (requires 30-day notice)
   - Combine with other leave types
   - Stagger across multiple months
   
   Policy Reference: Section 2.1 - Annual Leave Entitlement"

6. System also notes risk factors:
   - User at 67% risk for leave violations
   - Similar requests rejected 3 times
   - Timing could be improved (earlier submission = better)
```

### Flow 2: "What is..." Question

```
User: "What is the overtime policy?"

Copilot:
1. Extract intent: Policy explanation
2. Retrieve: Overtime policy full details
3. Answer structure:
   - Policy purpose
   - Key requirements (approval process, limits)
   - Eligibility criteria
   - Compensation rules
   - Exceptions available
   - Common mistakes
   
Example output:
"OVERTIME POLICY SUMMARY

Purpose: Ensure fair compensation and work-life balance while 
allowing business flexibility.

KEY REQUIREMENTS:
- Must be approved by direct manager BEFORE work begins
- Cannot exceed 10 hours/week without director approval
- Compensation: Time off in lieu (1:1) OR overtime pay

ELIGIBILITY:
- Only non-exempt employees
- Business-critical work only
- No scheduled comp time available

EXCEPTIONS:
- Emergency incidents: No pre-approval needed (report within 24h)
- On-call duties: Different rules apply

COMMON MISTAKES:
- 47% of violators didn't get manager approval first
- 23% exceeded 10-hour limit
- 30% didn't track time accurately

Policy References: Section 5.2, 5.3, 5.4"
```

### Flow 3: Decision Advisor

```
User: "I want to submit overtime for 12 hours this Saturday"

System calls adviseOnAction()
→ Retrieves user profile, violation history, policy

DECISION ADVICE:

Approval Probability: 45% (Medium Risk)

WHY 45%?
- Your overtime history: 2 violations in past 3 months (-20%)
- Manager typically approves: 60% (+15%)
- Policy allows: 10hrs/week limit, you're requesting 12 (-10%)
- Your data quality: Complete submission (+15%)

RISK FACTORS:
1. HIGH: Exceeds 10-hour weekly limit (policy violation)
2. MEDIUM: Recent violation pattern (2 in 3 months)
3. LOW: Timing good (submitted in advance)

APPROVAL CONDITIONS:
- Manager must submit override for >10 hours
- Weekend premium may apply
- Time tracking must be accurate

RECOMMENDED ALTERNATIVES:
1. Request 8 hours (within 10-hour limit) → 78% approval
2. Request for weekday instead → 65% approval
3. Split across two weeks → 72% approval
4. Request as exception → 40% approval (business case needed)

NEXT STEPS:
1. Discuss with manager about override
2. Prepare business case for exception
3. Consider alternative timing
```

## API Endpoints

### Question & Answer

```
POST /api/copilot/chat
{
    "message": "Can I work overtime on Saturday?",
    "context": { "policy_id": 1 }
}

Response:
{
    "data": {
        "answer": "Based on our overtime policy...",
        "type": "compliance_question",
        "confidence": 0.85,
        "references": [
            { "policy": "Overtime", "clause": "5.2", "policy_id": 1 }
        ]
    }
}
```

### Get Policy Details

```
GET /api/copilot/policies/{policy_id}

Response:
{
    "data": {
        "id": 1,
        "name": "Overtime Policy",
        "description": "...",
        "clauses": [
            { "title": "5.1 Approval Process", "content": "..." },
            { "title": "5.2 Limits", "content": "..." }
        ],
        "rules": [...]
    }
}
```

### Search Policies

```
POST /api/copilot/search
{
    "query": "leave"
}

Response:
{
    "data": {
        "policies": [
            { "id": 2, "name": "Annual Leave Policy", "description": "..." }
        ],
        "clauses": [
            { "id": 10, "title": "2.1 Entitlement", "policy": "Annual Leave Policy" }
        ],
        "total": 15
    }
}
```

### Decision Advice

```
POST /api/copilot/advice
{
    "policy_id": 1,
    "action_data": {
        "type": "overtime",
        "hours": 12,
        "date": "2024-12-21",
        "reason": "Project deadline critical"
    }
}

Response:
{
    "data": {
        "approval_probability": 0.45,
        "recommendation": "Medium risk - ensure manager override",
        "risk_factors": [
            { "factor": "exceeds_limit", "severity": "high" },
            { "factor": "recent_violations", "severity": "medium" }
        ],
        "alternatives": [
            { "suggestion": "Request 8 hours", "probability": 0.78 }
        ]
    }
}
```

### Get Rejection Reasons

```
GET /api/copilot/rejections/{policy_id}?limit=5

Response:
{
    "data": {
        "lack_of_approval": 23,
        "exceeds_limit": 18,
        "timing_conflict": 12,
        "incomplete_documentation": 8,
        "previous_violation": 5
    },
    "count": 5
}
```

## Prompt Engineering

### System Prompt

```
You are an intelligent policy advisor assistant. Your role is to help 
employees understand company policies clearly and accurately.

GUIDELINES:
1. Only use information provided in policy context
2. Be clear and concise
3. Cite specific policy clauses
4. If information is not available, acknowledge this
5. For compliance questions, be cautious - recommend HR if unsure
6. Be friendly and professional
7. Provide actionable guidance when possible

DO:
- Explain WITH EXAMPLES
- Be specific about requirements
- Mention exceptions clearly
- Suggest who to contact for edge cases

DON'T:
- Make up policies or rules
- Compromise on compliance
- Give legal advice
- Speculate about unstated policies
```

### Context Building

```
POLICY CONTEXT:

## Annual Leave Policy

This policy governs the use of annual leave...

### 2.1 Entitlement
Each employee receives X days per year based on tenure:
- Years 0-2: 15 days
- Years 2-5: 20 days
- Years 5+: 25 days

### 2.2 Notice Requirements
Annual leave requests must be submitted:
- 30 days in advance for 1+ week
- 5 days in advance for less than 1 week
- Emergency: 24 hours with manager approval

### 2.3 Blackout Dates
Annual leave is not approved during:
- Month-end financial close (last 5 days)
- Year-end holiday period (Dec 20 - Jan 2)
- Critical project phases (requires exception)

[Continue with full policy context...]
```

## Integration Points

### With Risk Profiler (PHASE 6)

```php
// In DecisionAdvisor.php
$riskProfile = $this->riskProfiler->getProfile($userId);

if ($riskProfile['risk_score'] > 75) {
    $recommendation = "High compliance risk - consider exception request";
}
```

### With Exception Management (PHASE 7)

```php
// When user asks "Can I override this?"
if ($approval_probability < 0.4) {
    $alternative = [
        'suggestion' => 'Request exception',
        'path' => '/exceptions/create',
        'reason' => 'Normal approval unlikely due to risk factors'
    ];
}
```

### With Notification System

- Pre-submit warning: User alerted to high-risk actions
- Post-submit prediction: "Your request has 65% approval probability"
- Training recommendation: "Consider completing leave policy training"

## Configuration

### Environment Variables

```env
# OpenAI Configuration
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4-turbo-preview
OPENAI_MAX_TOKENS=1500

# Policy Copilot Settings
COPILOT_MAX_CONTEXT_LENGTH=8000
COPILOT_MIN_CONFIDENCE=0.5
COPILOT_CACHE_TTL=3600
```

### Service Provider Registration

```php
// In AppServiceProvider.php
$this->app->singleton(PolicyCopilot::class, function ($app) {
    return new PolicyCopilot();
});

$this->app->singleton(DecisionAdvisor::class, function ($app) {
    return new DecisionAdvisor($app->make(RiskProfiler::class));
});
```

## Testing

```php
// Test question answering
$answer = $copilot->askQuestion("What is the leave policy?");
assert(!empty($answer['answer']));
assert($answer['confidence'] > 0.5);
assert(count($answer['references']) > 0);

// Test probability calculation
$advice = $advisor->adviseOnAction($userId, $policyId, [
    'type' => 'overtime',
    'hours' => 5
]);
assert($advice['approval_probability'] > 0.7);

// Test rejection reasons
$reasons = $advisor->getRejectionReasons($policyId);
assert(count($reasons) > 0);

// Test compliance recommendations
$recommendations = $advisor->getComplianceRecommendations($userId, $policyId);
if ($violations->count() > 3) {
    assert(collect($recommendations)
        ->pluck('type')
        ->contains('compliance_training'));
}
```

## Performance Optimization

1. **Cache Policy Context**: Store vectorized policy content
2. **Batch LLM Calls**: Group similar questions for API efficiency
3. **Rate Limiting**: Limit API calls per user/minute
4. **Token Counting**: Calculate tokens before sending to avoid overage
5. **Response Caching**: Cache common questions (30 min TTL)

## Cost Optimization

- Use gpt-3.5-turbo for simple queries (10x cheaper)
- Use gpt-4 only for complex analysis
- Cache frequent questions
- Batch similar requests

Est. cost per question:
- Simple: $0.001-0.005 (gpt-3.5)
- Complex: $0.01-0.05 (gpt-4)

## Error Handling

```php
try {
    $answer = $copilot->askQuestion($question);
} catch (\OpenAI\Exceptions\TransportException $e) {
    return [
        'answer' => 'Service temporarily unavailable. Please try again later.',
        'fallback' => true,
    ];
}
```

## Security & Privacy

- API key stored in .env only (not committed)
- Questions not logged verbatim (privacy)
- Response not stored by OpenAI (enterprise)
- User policy access controlled by authorization layer
- Rate limiting to prevent API abuse

## Future Enhancements

1. **Multi-language Support**: Translate policies to user's language
2. **Custom Training**: Fine-tune model on company policies
3. **Feedback Loop**: Learn from user reactions to recommendations
4. **Video Explanations**: Generate explainer videos for policies
5. **Mobile Integration**: Mobile chatbot interface
6. **Voice Interface**: Speak questions, hear answers
