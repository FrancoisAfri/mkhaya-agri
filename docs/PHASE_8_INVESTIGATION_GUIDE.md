# PHASE 8: Violation Investigation Guide

## Overview

The investigation phase provides a structured workflow for analyzing policy violations, collecting evidence, documenting findings, and implementing corrective actions. Investigators can track investigations from open to closed with audit trails at every step.

## Problem Solved

**Challenge:** When violations occur, there's no mechanism to investigate root cause, document findings, or track investigator actions. Violations are logged but never thoroughly analyzed.

**Solution:** Investigation workflow that:
- Creates investigations for high-severity violations
- Assigns trained investigators to cases
- Captures investigation notes with visibility levels (internal/user/manager visible)
- Stores evidence files with metadata
- Documents resolution findings and recommended actions
- Provides investigation statistics and trend analysis

## Architecture

### Database Schema

```sql
CREATE TABLE policy_violation_investigations (
    id BIGINT PRIMARY KEY,
    policy_violation_id BIGINT,     -- Links to violation being investigated
    assigned_to BIGINT,             -- Investigator user ID
    status ENUM,                    -- open | in_progress | reviewing | resolved | closed
    severity_level ENUM,            -- low | medium | high | critical
    investigation_started_at TIMESTAMP,
    investigation_ended_at TIMESTAMP,
    resolution_type ENUM,           -- resolved_satisfactorily | requires_training 
                                    -- | requires_action | requires_escalation
    resolution_notes TEXT,
    findings JSON,
    assigned_at TIMESTAMP,
    resolved_by BIGINT,
    closed_by BIGINT,
    closed_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE TABLE policy_investigation_notes (
    id BIGINT PRIMARY KEY,
    policy_violation_investigation_id BIGINT,
    added_by BIGINT,                -- User who added note
    content TEXT,
    visibility ENUM,               -- internal | user | manager
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE TABLE policy_investigation_evidence (
    id BIGINT PRIMARY KEY,
    policy_violation_investigation_id BIGINT,
    file_path TEXT,
    file_name TEXT,
    file_size INT,
    mime_type VARCHAR(100),
    uploaded_by BIGINT,
    description TEXT,
    evidence_type ENUM,            -- document | audio | video | screenshot | other
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Core Models

#### ViolationInvestigation Model

```php
class ViolationInvestigation extends Model {
    protected $fillable = [
        'policy_violation_id', 'assigned_to', 'status',
        'severity_level', 'investigation_started_at',
        'resolution_type', 'resolution_notes', 'findings',
        'assigned_at', 'resolved_by', 'closed_by'
    ];

    // Relationships
    public function violation(): BelongsTo { 
        return $this->belongsTo(PolicyViolation::class, 'policy_violation_id'); 
    }
    public function investigator(): BelongsTo { 
        return $this->belongsTo(User::class, 'assigned_to'); 
    }
    public function notes(): HasMany { 
        return $this->hasMany(InvestigationNote::class); 
    }
    public function evidence(): HasMany { 
        return $this->hasMany(InvestigationEvidence::class); 
    }

    // Helper methods
    public function durationDays(): int {
        $start = $this->investigation_started_at;
        $end = $this->investigation_ended_at ?? now();
        return $start->diffInDays($end);
    }

    public function isOverdue(): bool {
        return $this->status !== 'closed' 
            && $this->investigation_started_at->diffInDays(now()) > 30;
    }
}
```

#### InvestigationNote Model

```php
class InvestigationNote extends Model {
    protected $fillable = [
        'policy_violation_investigation_id', 'added_by', 
        'content', 'visibility'
    ];

    // Relationships
    public function investigation(): BelongsTo { 
        return $this->belongsTo(ViolationInvestigation::class); 
    }
    public function author(): BelongsTo { 
        return $this->belongsTo(User::class, 'added_by'); 
    }
}
```

#### InvestigationEvidence Model

```php
class InvestigationEvidence extends Model {
    protected $fillable = [
        'policy_violation_investigation_id', 'file_path',
        'file_name', 'file_size', 'mime_type', 'uploaded_by',
        'description', 'evidence_type'
    ];

    // Relationships
    public function investigation(): BelongsTo { 
        return $this->belongsTo(ViolationInvestigation::class); 
    }
    public function uploadedByUser(): BelongsTo { 
        return $this->belongsTo(User::class, 'uploaded_by'); 
    }
}
```

### Service Layer

#### ViolationInvestigationService

```php
class ViolationInvestigationService {
    // Create investigation for violation
    public function createInvestigation(
        int $violationId,
        ?int $assignedToId = null,
        ?string $initialNotes = null
    ): ViolationInvestigation

    // Assign investigator to investigation
    public function assignInvestigator(
        int $investigationId,
        int $userId,
        ?string $notes = null
    ): ViolationInvestigation

    // Update investigation status
    public function updateStatus(
        int $investigationId,
        string $status,
        ?int $updatedBy = null
    ): ViolationInvestigation

    // Add investigation note
    public function addNote(
        int $investigationId,
        int $userId,
        string $content,
        string $visibility = 'internal'
    ): InvestigationNote

    // Add investigation evidence (file)
    public function addEvidence(
        int $investigationId,
        int $uploadedBy,
        $file,
        string $description,
        ?string $evidenceType = null
    ): InvestigationEvidence

    // Resolve investigation with findings
    public function resolveInvestigation(
        int $investigationId,
        string $resolution,
        ?string $resolutionNotes = null,
        ?int $resolvedBy = null,
        ?array $findings = null
    ): ViolationInvestigation

    // Close investigation
    public function closeInvestigation(
        int $investigationId,
        ?int $closedBy = null,
        ?string $reason = null
    ): ViolationInvestigation

    // Get pending investigations for user
    public function getPendingInvestigations(?int $assignedToId = null)

    // Get overdue investigations (> 30 days open)
    public function getOverdueInvestigations()

    // Get investigation statistics
    public function getStatistics(): array
}
```

## Workflow Diagram

```
Policy violation created
         ↓
Create investigation record
         ↓
Status: OPEN (notification sent to compliance officer)
         ↓
Assign investigator
         ↓
Status: IN_PROGRESS
         ↓
Investigator reviews evidence
├─→ Add notes with visibility level
│   - Internal: Only visible to investigators
│   - User visible: User can see findings
│   - Manager visible: Manager notified
└─→ Upload evidence files
         ↓
Investigator documents findings
         ↓
Status: REVIEWING (peer review)
         ↓
Determine resolution
├─→ Resolved satisfactorily: No further action needed
├─→ Requires training: User needs policy training
├─→ Requires action: Corrective action plan needed
└─→ Requires escalation: Escalate to compliance/legal
         ↓
Status: RESOLVED
         ↓
Close investigation
         ↓
Status: CLOSED
         ↓
System generates archive record
```

## Investigation Note Visibility Levels

### Internal
- Only visible to investigators and compliance officers
- Used for sensitive discussions about user performance
- Example: "User's behavior suggests intentional policy violation"

### User Visible
- Visible to user and their manager
- Professional, factual findings
- Example: "Three similar violations in past 30 days indicate gap in training"

### Manager Visible
- Visible to user's manager
- Findings and recommended actions
- Example: "Recommend one-on-one coaching on leave policy"

## API Endpoints

### Create Investigation
```
POST /api/investigations
{
    "violation_id": 123,
    "assigned_to": 45,
    "notes": "Initial review - checking documentation"
}
```

### Add Investigation Note
```
POST /api/investigations/{id}/notes
{
    "content": "User provided email showing business justification",
    "visibility": "user"
}
```

### Add Evidence
```
POST /api/investigations/{id}/evidence
Content-Type: multipart/form-data
{
    "file": [file],
    "description": "Email chain approving overtime",
    "evidence_type": "document"
}
```

### Update Investigation Status
```
PATCH /api/investigations/{id}/status
{
    "status": "in_progress"
}
```

### Resolve Investigation
```
POST /api/investigations/{id}/resolve
{
    "resolution_type": "requires_training",
    "resolution_notes": "User violated leave notice policy. Will complete refresher training.",
    "findings": {
        "root_cause": "User unaware of 5-day notice requirement",
        "frequency": "First violation",
        "recommend_action": "Policy training"
    }
}
```

### Close Investigation
```
POST /api/investigations/{id}/close
{
    "reason": "User completed training, case closed"
}
```

### Get Pending Investigations (for investigator)
```
GET /api/investigations/pending/mine
Returns all investigations assigned to current user with status in (open, in_progress)
```

### Get Overdue Investigations
```
GET /api/investigations/overdue/all
Returns investigations open > 30 days (compliance officer only)
```

### Get Investigation Statistics
```
GET /api/investigations/statistics/all
Returns:
{
    "total_investigations": 150,
    "open": 12,
    "in_progress": 35,
    "resolved": 89,
    "closed": 14,
    "avg_investigation_duration": 8,
    "resolution_breakdown": {
        "resolved_satisfactorily": 45,
        "requires_training": 30,
        "requires_action": 10,
        "requires_escalation": 4
    }
}
```

## Integration Points

### With Violation Creation (PHASE 4-5)

When high-severity violation is created:

```php
// In EnforcementActionHandler.php
if ($severity >= 'high') {
    $investigation = $investigationService->createInvestigation(
        violationId: $violation->id,
        assignedToId: $complianceOfficer->id,
        initialNotes: "High severity violation - auto-created for investigation"
    );
}
```

### With Notification System

Investigations trigger notifications:
- **Investigation created**: Notify assigned investigator
- **Note added (user visible)**: Notify user with findings
- **Resolved**: Notify user of resolution and required actions
- **Overdue**: Weekly notifications for investigations > 30 days

### With Training System

When resolution is "requires_training":

```php
// After investigation resolved
if ($investigation->resolution_type === 'requires_training') {
    $trainingService->enrollUserInCourse(
        userId: $investigation->violation->user_id,
        courseId: $investigation->violation->policy->training_course_id,
        reason: 'Policy violation investigation outcome'
    );
}
```

## Database Queries

### Get pending investigations for investigator
```php
ViolationInvestigation::where('assigned_to', $investigatorId)
    ->where('status', '!=', 'closed')
    ->get();
```

### Get overdue investigations
```php
ViolationInvestigation::where('status', '!=', 'closed')
    ->where('investigation_started_at', '<', now()->subDays(30))
    ->get();
```

### Get investigation statistics
```php
[
    'open' => ViolationInvestigation::where('status', 'open')->count(),
    'in_progress' => ViolationInvestigation::where('status', 'in_progress')->count(),
    'avg_duration' => ViolationInvestigation::whereNotNull('investigation_ended_at')
        ->avg(DB::raw('DATEDIFF(investigation_ended_at, investigation_started_at)'))
]
```

### Get evidence for investigation
```php
InvestigationEvidence::where('policy_violation_investigation_id', $investigationId)
    ->orderByDesc('created_at')
    ->get();
```

## Investigator Checklist

When investigating a violation:

1. **Initial Review**
   - [ ] Read violation details and context
   - [ ] Review relevant policy clause
   - [ ] Check user's history with this policy
   - [ ] Add internal note with initial assessment

2. **Evidence Collection**
   - [ ] Request documentation from user
   - [ ] Collect relevant emails/approvals
   - [ ] Interview stakeholders if needed
   - [ ] Upload evidence with descriptions

3. **Root Cause Analysis**
   - [ ] Was violation intentional or accidental?
   - [ ] Did user understand the policy?
   - [ ] Were there system/process barriers?
   - [ ] Does this indicate training gap?

4. **Finding Documentation**
   - [ ] Add user-visible notes with findings
   - [ ] Document analysis and reasoning
   - [ ] Determine appropriate resolution

5. **Resolution**
   - [ ] Select resolution type
   - [ ] Document recommended actions
   - [ ] Assign corrective actions if needed
   - [ ] Mark as resolved

6. **Closure**
   - [ ] Verify all actions completed
   - [ ] Add final notes
   - [ ] Close investigation
   - [ ] Archive case records

## Resolution Types

| Type | Meaning | Action |
|------|---------|--------|
| Resolved Satisfactorily | User or system at fault, not user behavior | No action needed |
| Requires Training | User unaware of policy requirements | Enroll in training |
| Requires Action | User aware but made mistake | 1-on-1 coaching |
| Requires Escalation | Repeated violations or intentional breach | Escalate to HR/Legal |

## Testing

```php
// Create investigation
$investigation = $service->createInvestigation($violation->id, $investigator->id);
assert($investigation->status === 'open');

// Add note
$note = $service->addNote($investigation->id, $investigator->id, 'Initial assessment', 'internal');
assert($note->visibility === 'internal');

// Add evidence
$evidence = $service->addEvidence(
    $investigation->id,
    $investigator->id,
    $file,
    'Email confirmation',
    'document'
);
assert($evidence->evidence_type === 'document');

// Resolve investigation
$resolved = $service->resolveInvestigation(
    $investigation->id,
    'requires_training',
    'User needs leave policy training'
);
assert($resolved->resolution_type === 'requires_training');

// Close investigation
$closed = $service->closeInvestigation($investigation->id);
assert($closed->status === 'closed');
```

## Performance Considerations

1. **Evidence Storage**: Store files on S3/private disk, not database
2. **Investigation Queries**: Index on `(assigned_to, status)` and `(investigation_started_at, status)`
3. **Statistics**: Cache investigation stats (updated daily)
4. **Note Pagination**: Load notes with limit + offset for large investigations

## Security

- Only assigned investigator and compliance officers can view investigation
- Evidence files encrypted at rest
- Note visibility enforced at service layer
- Audit log: Track all investigation changes
- Users cannot modify investigations assigned to them

## Migration

```php
Schema::create('policy_violation_investigations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('policy_violation_id')->constrained('policy_violations');
    $table->foreignId('assigned_to')->nullable()->constrained('users');
    $table->enum('status', ['open', 'in_progress', 'reviewing', 'resolved', 'closed']);
    $table->enum('severity_level', ['low', 'medium', 'high', 'critical']);
    $table->timestamp('investigation_started_at');
    $table->timestamp('investigation_ended_at')->nullable();
    $table->enum('resolution_type', ['resolved_satisfactorily', 'requires_training', 'requires_action', 'requires_escalation'])->nullable();
    $table->text('resolution_notes')->nullable();
    $table->json('findings')->nullable();
    $table->timestamp('assigned_at')->nullable();
    $table->foreignId('resolved_by')->nullable()->constrained('users');
    $table->foreignId('closed_by')->nullable()->constrained('users');
    $table->timestamp('closed_at')->nullable();
    $table->timestamps();
    
    $table->index(['assigned_to', 'status']);
    $table->index(['investigation_started_at', 'status']);
});

Schema::create('policy_investigation_notes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('policy_violation_investigation_id')->constrained('policy_violation_investigations');
    $table->foreignId('added_by')->constrained('users');
    $table->text('content');
    $table->enum('visibility', ['internal', 'user', 'manager']);
    $table->timestamps();
});

Schema::create('policy_investigation_evidence', function (Blueprint $table) {
    $table->id();
    $table->foreignId('policy_violation_investigation_id')->constrained('policy_violation_investigations');
    $table->string('file_path');
    $table->string('file_name');
    $table->integer('file_size');
    $table->string('mime_type');
    $table->foreignId('uploaded_by')->constrained('users');
    $table->text('description');
    $table->enum('evidence_type', ['document', 'audio', 'video', 'screenshot', 'other']);
    $table->timestamps();
});
```
