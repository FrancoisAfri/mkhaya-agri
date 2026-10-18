# PHASE 7: Policy Exception Management Guide

## Overview

Policy exceptions allow authorized users to request approval for actions that would otherwise violate policy. This phase implements a complete exception request workflow with approval routing, time-limited exceptions, and automatic expiry management.

## Problem Solved

**Challenge:** When a policy violation is inevitable due to business requirements, there's no mechanism to bypass strict policy enforcement with proper oversight.

**Solution:** Exception request workflow that:
- Allows users to request exceptions with business justification
- Routes requests to appropriate approvers (managers, policy owners)
- Grants time-limited exceptions (7 days, 30 days, etc.)
- Auto-expires exceptions and triggers compliance re-evaluation
- Tracks exception usage to prevent abuse

## Architecture

### Database Schema

```sql
CREATE TABLE policy_exceptions (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,           -- User requesting exception
    policy_id BIGINT,         -- Policy to be excepted
    rule_id BIGINT,           -- Specific rule being excepted
    reason TEXT,              -- Business justification
    start_date DATE,          -- Exception effective date
    end_date DATE,            -- Exception expiry date
    duration_days INT,        -- Calculated days (cached)
    evidence JSON,            -- Supporting documentation
    status ENUM,              -- draft | submitted | approved | rejected | escalated | expired | revoked
    approved_by BIGINT,       -- Approver user ID
    approved_at TIMESTAMP,    -- Approval timestamp
    approval_notes TEXT,      -- Approver comments
    rejection_reason TEXT,    -- Why rejected
    expired_at TIMESTAMP,     -- Auto-expiry timestamp
    expiry_notified_at TIMESTAMP, -- When user notified of expiry
    auto_notify_before_expiry BOOL, -- Send 7-day warning
    usage_count INT,          -- How many times used
    violation_count INT,      -- Violations while active
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Core Models

#### PolicyException Model

```php
class PolicyException extends Model {
    protected $fillable = [
        'user_id', 'policy_id', 'rule_id', 'reason',
        'start_date', 'end_date', 'evidence', 'status',
        'approved_by', 'approved_at', 'approval_notes',
        'rejection_reason', 'expired_at', 'usage_count'
    ];

    // Relationships
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function policy(): BelongsTo { return $this->belongsTo(Policy::class); }
    public function rule(): BelongsTo { return $this->belongsTo(PolicyRule::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    // Helper methods
    public function isValid(): bool {
        return $this->status === 'approved' 
            && today() >= $this->start_date 
            && (!$this->end_date || today() <= $this->end_date);
    }

    public function isExpired(): bool {
        return $this->status === 'expired' 
            || ($this->end_date && today() > $this->end_date);
    }

    public function daysRemaining(): int {
        if ($this->isExpired()) return 0;
        return $this->end_date?->diffInDays(today()) ?? PHP_INT_MAX;
    }
}
```

### Service Layer

#### ExceptionRequestService

```php
class ExceptionRequestService {
    // Create exception request (draft)
    public function createRequest(
        int $userId,
        int $policyId,
        int $ruleId,
        string $reason,
        Carbon $startDate,
        ?Carbon $endDate = null
    ): PolicyException

    // Submit for approval (change status to submitted)
    public function submitForApproval(int $exceptionId): PolicyException

    // Manager approval workflow
    public function approve(
        int $exceptionId,
        int $approverId,
        ?string $notes = null
    ): PolicyException

    // Manager rejection
    public function reject(
        int $exceptionId,
        int $rejectorId,
        string $reason
    ): PolicyException

    // Escalate to policy owner
    public function escalate(int $exceptionId, ?string $reason = null): PolicyException

    // Check if valid exception exists for user/policy/rule
    public function hasValidException(
        int $userId,
        int $policyId,
        int $ruleId
    ): bool

    // Auto-expire past-end-date exceptions (daily job)
    public function autoExpireExceptions(): int

    // Send 7-day expiry reminders
    public function sendExpiryNotifications(): int

    // Re-evaluate compliance after expiry
    public function reEvaluateAfterExpiry(int $exceptionId): array
}
```

## Workflow Diagram

```
User creates exception request
         ↓
    Status: DRAFT
         ↓
  User submits exception
         ↓
Status: SUBMITTED → Routes to Manager
         ↓
  Manager reviews
         ↓
         ├─→ Approve → Status: APPROVED → Exception active from start_date to end_date
         │
         └─→ Reject → Status: REJECTED → Notify user of rejection reason
         
  Manager can also escalate
         ↓
Status: ESCALATED → Routes to Policy Owner
         ↓
  Policy Owner reviews
         ↓
         ├─→ Approve → Status: APPROVED
         └─→ Reject → Status: REJECTED

  Daily job:
         ↓
  Check for end_date < TODAY
         ↓
Status: EXPIRED → Via notification system
         ↓
Re-evaluate user compliance after expiry
```

## API Endpoints

### Create Exception Request
```
POST /api/exceptions
{
    "policy_id": 1,
    "rule_id": 5,
    "reason": "Annual conference requires presence on weekend",
    "start_date": "2024-12-21",
    "end_date": "2024-12-22",
    "evidence": {
        "conference_approval": "url/to/document"
    }
}
```

### Submit for Approval
```
POST /api/exceptions/{id}/submit
Response: Exception now in submitted status, manager notified
```

### Manager Approval
```
POST /api/exceptions/{id}/approve
{
    "notes": "Approved for annual conference"
}
```

### Manager Rejection
```
POST /api/exceptions/{id}/reject
{
    "reason": "Conference already approved for different timeframe"
}
```

### Check Valid Exception
```
POST /api/exceptions/{id}/check-valid
{
    "policy_id": 1,
    "rule_id": 5
}
Returns: { has_valid_exception: true }
```

### Get User Pending Exceptions
```
GET /api/exceptions/pending
Returns all exceptions in draft/submitted/escalated status
```

### Get User Active Exceptions
```
GET /api/exceptions/active
Returns only approved exceptions within their date range
```

## Integration Points

### With Enforcement Engine (PHASE 5)

When `EnforcementActionHandler` detects a violation:

```php
// In EnforcementActionHandler.php
$exception = $exceptionRequestService->getValidException(
    userId: $userId,
    policyId: $policyId,
    ruleId: $ruleId
);

if ($exception) {
    // Record exception usage
    $exceptionRequestService->recordUsage($exception->id, $violationId);
    // Skip enforcement action
    return EnforcementResult::bypassed('Exception granted');
}

// Proceed with enforcement
```

### With Notification System

Exceptions trigger notifications at each stage:
- **Approval pending**: Notify manager of new request
- **Approved**: Notify user exception granted
- **Rejected**: Notify user with rejection reason
- **Expiry warning**: 7 days before end_date
- **Expired**: Notify user when exception ends

## Database Queries

### Get pending approvals for manager
```php
PolicyException::where('assigned_manager', auth()->id())
    ->where('status', 'submitted')
    ->get();
```

### Check if user has valid exception
```php
PolicyException::where('user_id', $userId)
    ->where('policy_id', $policyId)
    ->where('rule_id', $ruleId)
    ->where('status', 'approved')
    ->where('start_date', '<=', today())
    ->where(function($q) {
        $q->whereNull('end_date')
          ->orWhere('end_date', '>=', today());
    })
    ->exists();
```

### Auto-expire stale exceptions
```php
PolicyException::where('status', 'approved')
    ->where('end_date', '<', today())
    ->update(['status' => 'expired', 'expired_at' => now()]);
```

## Approval Matrix

| User Type | Can Request | Can Approve | Can Escalate | Can Revoke |
|-----------|-------------|-------------|--------------|-----------|
| Employee | ✓ (own) | ✗ | ✗ | ✗ |
| Manager | ✓ (own/team) | ✓ (team) | ✓ | ✓ |
| Policy Owner | ✓ (own) | ✓ (all for policy) | ✓ | ✓ |
| Compliance | ✓ (any) | ✓ (all) | ✓ | ✓ |
| Admin | ✓ (all) | ✓ (all) | ✓ | ✓ |

## Common Scenarios

### Scenario 1: Weekend Work Exception

```
Employee: "I need to work Saturday for project deadline"
→ Create exception for overtime policy
→ Reason: "Project go-live scheduled for Sunday"
→ Duration: 1 day (Saturday)
→ Submit to manager

Manager: Reviews, sees legitimate business need
→ Approve exception
→ Add note: "Project go-live confirmed - approved"

Employee: Works Saturday without policy violation
→ Exception automatically expires Sunday
→ System re-evaluates compliance
```

### Scenario 2: Emergency Leave Exception

```
Employee: "Medical emergency requires same-day leave"
→ Create exception for advance notice policy
→ Reason: "Emergency root canal"
→ Duration: 1 day
→ Submit with medical documentation

Manager: Reviews urgent situation
→ Approve immediately
→ Add note: "Medical emergency - approved immediately"

Employee: Leave counted as compliant
→ Exception expires automatically next day
```

### Scenario 3: Escalated Exception

```
Employee: "Needs 2-week sabbatical"
→ Create exception for leave policy (2-week limit)
→ Reason: "Professional development course"
→ Duration: 14 days
→ Submit to manager

Manager: Sees this exceeds normal limit
→ Cannot approve (policy limit is 2 weeks max)
→ Escalates to policy owner

Policy Owner: Reviews
→ Approves with conditions
→ Note: "Approved 2-week sabbatical for professional development"

System: Tracks as escalation, requires higher review
```

## Testing

```php
// Test exception creation
$exception = $service->createRequest(
    userId: $user->id,
    policyId: $policy->id,
    ruleId: $rule->id,
    reason: "Test exception",
    startDate: today()->addDay(),
    endDate: today()->addDays(7)
);
assert($exception->status === 'draft');

// Test approval workflow
$approved = $service->approve($exception->id, $manager->id);
assert($approved->status === 'approved');
assert($approved->approved_by === $manager->id);

// Test validity check
$isValid = $service->hasValidException($user->id, $policy->id, $rule->id);
assert($isValid === true);

// Test auto-expiry
$expired = $service->autoExpireExceptions();
assert($expired > 0);
```

## Performance Considerations

1. **Exception Lookup**: Index on `(user_id, policy_id, rule_id, status, end_date)` for fast validation checks
2. **Expiry Detection**: Run auto-expiry as scheduled job, not on request
3. **Notification Queue**: Use queue for sending approval/rejection notifications
4. **Policy Lookup**: Cache policy/rule/user relationships

## Security

- Only user can create own exceptions (or managers for team)
- Only approvers can approve/reject exceptions
- Policy owners control their own policies
- Audit trail: Track who approved, when, with what notes
- Cannot backdate exceptions (start_date must be >= today)
- Cannot create overlapping exceptions (validation in service layer)

## Migration

```php
Schema::create('policy_exceptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users');
    $table->foreignId('policy_id')->constrained('policies');
    $table->foreignId('rule_id')->constrained('policy_rules');
    $table->text('reason');
    $table->date('start_date');
    $table->date('end_date')->nullable();
    $table->integer('duration_days')->nullable();
    $table->json('evidence')->nullable();
    $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'escalated', 'expired', 'revoked']);
    $table->foreignId('approved_by')->nullable()->constrained('users');
    $table->timestamp('approved_at')->nullable();
    $table->text('approval_notes')->nullable();
    $table->text('rejection_reason')->nullable();
    $table->timestamp('expired_at')->nullable();
    $table->timestamp('expiry_notified_at')->nullable();
    $table->boolean('auto_notify_before_expiry')->default(true);
    $table->integer('usage_count')->default(0);
    $table->integer('violation_count')->default(0);
    $table->timestamps();
    
    $table->index(['user_id', 'status']);
    $table->index(['policy_id', 'status']);
    $table->index(['end_date', 'status']);
});
```
