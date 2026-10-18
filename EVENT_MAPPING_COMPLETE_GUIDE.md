# EVENT MAPPING GUIDE
## Linking Events → Policies → Rules

This guide explains how the event mapping system works and how to configure it for your modules.

---

## 1. CORE CONCEPTS

### Event
An action that happens in a module.
- **Format:** `module.action` or wildcard patterns like `leave.*`
- **Example:** `leave_request.submitted`, `claim.created`
- **Triggers:** Policy rule evaluation

### Policy
A business rule document with extracted clauses and derived rules.
- **Linked to one module:** Attendance policies, Leave policies, etc.
- **Contains:** Clauses (from document), Rules (enforcement logic)
- **Status:** `draft`, `active`, `under_review`, `archived`

### Rule
Specific enforcement logic with conditions and actions.
- **Linked to policy:** At least one policy
- **Triggered by:** Events matching EventRuleMap
- **Enforcement actions:** `block`, `warn`, `escalate`, `log_only`
- **Can have:** Conditions (if/when), Actions (what happens)

### EventRuleMap
The mapping that connects events to rules.
- **Links:** Event (module + action pattern) ↔ Rule
- **Supports:** Wildcard patterns, payload filtering
- **Can be:** Active/deactivated without deletion

---

## 2. MAPPING ARCHITECTURE

```
┌─────────────────────────────────────────┐
│  Module Action Triggered                │
│  (leave_request.submitted)              │
└──────────────────┬──────────────────────┘
                   │
                   ↓
    ┌──────────────────────────────┐
    │  RuleDiscoveryEngine.discover │
    │      Rules For Event()        │
    └──────────────────┬────────────┘
                       │
                       ↓
            ┌──────────────────────┐
            │  Query EventRuleMap  │
            │   WHERE module =     │
            │   Leave             │
            │   AND action_pattern │
            │   matches action    │
            └──────────┬───────────┘
                       │
                       ↓
        ┌─────────────────────────────────┐
        │  For each matching EventRuleMap │
        │  - Load associated Rule         │
        │  - Check Rule.active = true     │
        │  - Check Rule.policy.status     │
        │    = 'active'                   │
        │  - Verify payload_filter matches│
        └──────────────┬──────────────────┘
                       │
                       ↓
        ┌────────────────────────────────┐
        │  Return Collection of Rules    │
        │  Applicable to this event      │
        └────────────────────────────────┘
                       │
                       ↓
        ┌────────────────────────────────┐
        │  RuleEvaluationEngine evaluates│
        │  each rule against event       │
        └────────────────────────────────┘
                       │
                       ↓
        ┌────────────────────────────────┐
        │  If any rules violated:        │
        │  - Record violation            │
        │  - Execute enforcement action: │
        │    • block: throw exception    │
        │    • warn: log warning         │
        │    • escalate: notify manager  │
        │    • log_only: audit trail     │
        └────────────────────────────────┘
```

---

## 3. MAIN COMPONENTS

### A. EventPolicyMapper Service
**Location:** `app/Modules/Policies/Services/Mapping/EventPolicyMapper.php`

**Purpose:** High-level mapping queries and management.

**Main Methods:**
```php
// Query methods
getPoliciesForModule(string $module): Collection
getRulesForEvent(PolicyEventDTO $event): Collection
getPoliciesForEvent(PolicyEventDTO $event): Collection
getEventsForPolicy(int $policyId): Collection
getRulesForPolicy(int $policyId): Collection

// Mapping management
mapRuleToEvent(int $ruleId, string $module, string $action, ?array $filter): EventRuleMap
unmapRuleFromEvent(int $ruleId, string $module, string $action): bool
deactivateMapping(int $mappingId): bool
reactivateMapping(int $mappingId): bool

// Reporting
getMappingReportForModule(string $module): array
getGlobalMappingSummary(): array
testEventMapping(PolicyEventDTO $event): array
getDependencyMap(): array
```

**Example Usage:**
```php
$mapper = app(EventPolicyMapper::class);

// Get all rules applicable to a leave request
$rules = $mapper->getRulesForEvent(new PolicyEventDTO(
    module: 'Leave',
    action: 'leave_request.submitted',
    payload: ['user_id' => 42, 'days' => 5]
));

// Map a rule to an event
$mapper->mapRuleToEvent(
    ruleId: 15,
    module: 'Leave',
    actionPattern: 'leave_request.*',
    payloadFilter: null
);

// Get all events that trigger a policy
$events = $mapper->getEventsForPolicy($policyId);
```

### B. RuleDiscoveryEngine
**Location:** `app/Modules/Policies/Services/Mapping/RuleDiscoveryEngine.php`

**Purpose:** High-performance rule discovery with caching.

**Key Features:**
- **Caching** - Results cached for 1 hour by default
- **Pattern matching** - Supports fnmatch() wildcards
- **Batch operations** - Discover rules for multiple events efficiently
- **Cache invalidation** - Automatically clears when rules change

**Main Methods:**
```php
// Cached discovery
discoverRulesForEvent(PolicyEventDTO $event): Collection

// Fresh database queries
queryRulesForEvent(PolicyEventDTO $event): Collection

// Batch operations
discoverRulesForMultipleEvents($events): array

// Pattern queries
getRulesForModuleAction(string $module, string $pattern): Collection
getActionPatternsForModule(string $module): array
getModulesWithMappings(): array

// Cache management
clearCacheForEvent(string $module, string $action): void
clearCacheForModule(string $module): void
clearAllCaches(): void
```

**Example Usage:**
```php
$engine = app(RuleDiscoveryEngine::class);

// Get rules (with caching)
$rules = $engine->discoverRulesForEvent(new PolicyEventDTO(
    module: 'Leave',
    action: 'leave_request.submitted',
    payload: ['days' => 10]
));

// Clear cache after rules are modified
$engine->clearCacheForEvent('Leave', 'leave_request.submitted');

// Get all action patterns for Leave module
$actions = $engine->getActionPatternsForModule('Leave');
// Returns: ['leave_request.*', 'leave.approved', ...]
```

### C. EventMappingController
**Location:** `app/Modules/Policies/Http/Controllers/EventMappingController.php`

**Purpose:** Admin API for managing event mappings.

**Routes:**
```
GET    /api/event-mappings                    - List mappings
POST   /api/event-mappings                    - Create mapping
GET    /api/event-mappings/{id}               - Get mapping
PUT    /api/event-mappings/{id}               - Update mapping
DELETE /api/event-mappings/{id}               - Delete mapping
PATCH  /api/event-mappings/{id}/activate      - Activate mapping
PATCH  /api/event-mappings/{id}/deactivate    - Deactivate mapping

GET    /api/event-mappings/summary            - Global summary
GET    /api/event-mappings/modules            - Available modules
GET    /api/event-mappings/modules/{module}/actions - Actions in module
GET    /api/event-mappings/report/module/{module}   - Detailed module report
GET    /api/event-mappings/dependencies       - Dependency map
POST   /api/event-mappings/test               - Test mapping logic
GET    /api/event-mappings/event-rules        - Applicable rules for event
GET    /api/event-mappings/policy/{id}/rules  - Rules in a policy
GET    /api/event-mappings/policy/{id}/events - Events for a policy
```

---

## 4. HOW TO CONFIGURE EVENT MAPPINGS

### Step 1: Create Policy with Document

```php
// Create policy
$policy = Policy::create([
    'name' => 'Leave Policy 2026',
    'module' => 'Leave',
    'status' => 'draft',
    'owner_id' => auth()->id(),
    ...
]);

// Upload document
$document = $policy->documents()->create([
    'filename' => 'leave-policy.pdf',
    'path' => base64_encode($fileContent),
    'extraction_status' => 'pending',
]);

// Extract clauses & rules (automatic or manual)
dispatch(new ExtractPolicyClausesJob($document));
```

### Step 2: Approve Clauses

```php
// Review extracted clauses
$clauses = $policy->clauses()->get();

foreach ($clauses as $clause) {
    // Review and approve
    $clause->update(['status' => 'approved']);
}
```

### Step 3: Create Rules from Clauses

```php
// Create a rule from approved clause
$rule = $policy->rules()->create([
    'clause_id' => $clause->id,
    'name' => 'Maximum 20 leaves per year',
    'description' => 'Employees cannot take more than 20 leaves in a calendar year',
    'action_on_breach' => 'block', // or 'warn', 'escalate', 'log_only'
    'active' => true,
]);

// Add conditions to rule
$rule->conditions()->create([
    'field' => 'days_requested',
    'operator' => '>',
    'value' => 20,
]);
```

### Step 4: Map Events to Rules

**Option A: Via API**

```bash
POST /api/event-mappings

{
  "rule_id": 42,
  "module": "Leave",
  "action_pattern": "leave_request.submitted",
  "payload_filter": null
}
```

**Option B: Via Code**

```php
$mapper = app(EventPolicyMapper::class);

$mapper->mapRuleToEvent(
    ruleId: 42,
    module: 'Leave',
    actionPattern: 'leave_request.submitted'
);

// Or with payload filter
$mapper->mapRuleToEvent(
    ruleId: 42,
    module: 'Leave',
    actionPattern: 'leave_request.submitted',
    payloadFilter: [
        'leave_type_id' => 1, // Only for annual leave type
    ]
);
```

### Step 5: Activate Policy

```php
$policy->update(['status' => 'active']);
```

### Step 6: Test Event Mapping

```bash
POST /api/event-mappings/test

{
  "module": "Leave",
  "action": "leave_request.submitted",
  "payload": {
    "user_id": 42,
    "days_requested": 25,
    "leave_type_id": 1
  }
}

Response:
{
  "event": {
    "module": "Leave",
    "action": "leave_request.submitted"
  },
  "matches": {
    "policies": [
      {
        "id": 1,
        "name": "Leave Policy 2026",
        "module": "Leave"
      }
    ],
    "rules": [
      {
        "id": 42,
        "name": "Maximum 20 leaves per year",
        "enforcement_action": "block",
        "conditions_count": 1
      }
    ]
  },
  "summary": {
    "policies_matching": 1,
    "rules_matching": 1,
    "will_trigger_enforcement": true
  }
}
```

---

## 5. ACTION PATTERNS (Wildcards)

EventRuleMap supports fnmatch() wildcards for flexible event matching.

| Pattern | Matches | Example |
|---------|---------|---------|
| `leave_request.submitted` | Exact | Only this event |
| `leave_request.*` | Prefix + wildcard | `leave_request.submitted`, `leave_request.approved`, etc. |
| `leave.*` | Broader wildcard | `leave_request.*`, `leave_approval.*`, etc. |
| `*` | All events in module | Any event in Leave module |
| `claim.*` | Claims module events | `claim.submitted`, `claim.approved` |

**Example:**
```php
// Match all leave-related events
$mapper->mapRuleToEvent(
    ruleId: 42,
    module: 'Leave',
    actionPattern: 'leave.*'  // Matches leave_request.*, leave_approval.*, etc.
);

// Match only submitted events
$mapper->mapRuleToEvent(
    ruleId: 43,
    module: 'Leave',
    actionPattern: '*.submitted'  // Matches any .submitted event
);
```

---

## 6. PAYLOAD FILTERS

Filter rules to apply only when certain payload conditions are met.

**Use For:**
- Apply rules only to specific leave types
- Different enforcement based on department
- Escalation based on amount/days threshold

**Example:**

```php
// Rule applies only to annual leave requests > 5 days
$mapper->mapRuleToEvent(
    ruleId: 42,
    module: 'Leave',
    actionPattern: 'leave_request.submitted',
    payloadFilter: [
        'leave_type_id' => 1,           // Annual leave only
        'days_requested' => 5,          // Only when > 5 days
    ]
);

// Event structure must match filter keys
$event = new PolicyEventDTO(
    module: 'Leave',
    action: 'leave_request.submitted',
    payload: [
        'user_id' => 42,
        'leave_type_id' => 1,
        'days_requested' => 10,      // This matches filter!
        'reason' => 'Vacation',
    ]
);

// This rule will be triggered because:
// - payload['leave_type_id'] == 1 ↔ filter['leave_type_id'] == 1 ✓
// - payload['days_requested'] == 10 ≠ filter['days_requested'] == 5 ✗ 
// Actually won't match because != comparison. Filters are exact matches.
```

---

## 7. QUERY EXAMPLES

### Example 1: Get All Rules for a Leave Request

```php
$mapper = app(EventPolicyMapper::class);

$event = new PolicyEventDTO(
    module: 'Leave',
    action: 'leave_request.submitted',
    payload: ['user_id' => 1, 'days' => 5]
);

$rules = $mapper->getRulesForEvent($event);

foreach ($rules as $rule) {
    echo $rule->name;
    echo $rule->policy->name;
    echo $rule->action_on_breach; // block, warn, escalate, log_only
}
```

### Example 2: Get Applicable Policies for Module

```php
$policies = $mapper->getPoliciesForModule('Leave');

foreach ($policies as $policy) {
    $rules = $policy->rules()->where('active', true)->count();
    echo "$policy->name has $rules active rules";
}
```

### Example 3: Get All Events That Trigger a Policy

```php
$events = $mapper->getEventsForPolicy($policyId);

// Results:
// [
//   ['module' => 'Leave', 'action_pattern' => 'leave_request.submitted', ...],
//   ['module' => 'Leave', 'action_pattern' => 'leave_request.approved', ...],
// ]

foreach ($events as $event) {
    echo "Policy triggered by: $event['module'].$event['action_pattern']";
}
```

### Example 4: Mapping Report for Module

```php
$report = $mapper->getMappingReportForModule('Leave');

// Returns:
// [
//   [
//     'action_pattern' => 'leave_request.submitted',
//     'rules_count' => 3,
//     'rules' => [
//       ['rule_name' => 'Max 20 days/year', 'enforcement_action' => 'block'],
//       ['rule_name' => 'Requires manager approval', 'enforcement_action' => 'block'],
//     ]
//   ],
//   [
//     'action_pattern' => 'leave_request.approved',
//     'rules_count' => 1,
//     'rules' => [...]
//   ]
// ]

foreach ($report as $action) {
    echo "$action['action_pattern']: {$action['rules_count']} rules";
}
```

### Example 5: Test Event Before Triggering

```php
$test = $mapper->testEventMapping(new PolicyEventDTO(
    module: 'Leave',
    action: 'leave_request.submitted',
    payload: ['user_id' => 1, 'days' => 30]
));

if ($test['summary']['will_trigger_enforcement']) {
    echo "This event will trigger " . count($test['matches']['rules']) . " rules";
}

if (!empty($test['matches']['blocking_violations'])) {
    echo "This event will be BLOCKED";
}
```

---

## 8. MANAGING MAPPINGS

### Create Mapping via API

```bash
curl -X POST http://localhost/api/event-mappings \
  -H "Content-Type: application/json" \
  -d '{
    "rule_id": 42,
    "module": "Leave",
    "action_pattern": "leave_request.submitted",
    "payload_filter": null
  }'
```

### Update Mapping

```bash
curl -X PUT http://localhost/api/event-mappings/15 \
  -d '{
    "action_pattern": "leave_request.*",
    "payload_filter": {"leave_type_id": 1}
  }'
```

### Deactivate (without deletion)

```bash
curl -X PATCH http://localhost/api/event-mappings/15/deactivate
```

This keeps the mapping for audit purposes but disables it.

### Reactivate

```bash
curl -X PATCH http://localhost/api/event-mappings/15/activate
```

### Delete

```bash
curl -X DELETE http://localhost/api/event-mappings/15
```

---

## 9. PERFORMANCE & CACHING

### How Caching Works

- **Cache key:** `policy:rules:{module}:{action}` (hashed)
- **TTL:** 3600 seconds (1 hour)
- **Driver:** Database cache (or Redis if configured)

### When Cache Clears

Automatically cleared when:
1. EventRuleMap created/updated/deleted
2. PolicyRule status changed
3. Policy status changed

### Manual Cache Management

```php
$engine = app(RuleDiscoveryEngine::class);

// Clear specific event
$engine->clearCacheForEvent('Leave', 'leave_request.submitted');

// Clear module
$engine->clearCacheForModule('Leave');

// Clear all
$engine->clearAllCaches();
```

### Performance tip:
Use `RuleDiscoveryEngine::discoverRulesForEvent()` (cached) instead of `EventPolicyMapper::queryRulesForEvent()` (database) for repeated queries on the same event.

---

## 10. TESTING MAPPINGS

### Postman Collection

```json
{
  "info": {
    "name": "Event Mappings",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "Get Summary",
      "request": {
        "method": "GET",
        "url": "{{base_url}}/api/event-mappings/summary"
      }
    },
    {
      "name": "Test Mapping",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/event-mappings/test",
        "body": {
          "module": "Leave",
          "action": "leave_request.submitted",
          "payload": {"user_id": 1, "days": 5}
        }
      }
    }
  ]
}
```

### Unit Test Example

```php
class EventMappingTest extends TestCase
{
    public function test_rules_discovered_for_event()
    {
        // Create policy + rule
        $policy = Policy::factory()->create(['module' => 'Leave']);
        $rule = PolicyRule::factory()->create(['policy_id' => $policy->id]);

        // Create mapping
        EventRuleMap::create([
            'rule_id' => $rule->id,
            'module' => 'Leave',
            'action_pattern' => 'leave_request.submitted',
        ]);

        // Test discovery
        $engine = app(RuleDiscoveryEngine::class);
        $discovered = $engine->queryRulesForEvent(new PolicyEventDTO(
            module: 'Leave',
            action: 'leave_request.submitted',
            payload: [],
        ));

        $this->assertCount(1, $discovered);
        $this->assertEquals($rule->id, $discovered->first()->id);
    }
}
```

---

## 11. INTEGRATION CHECKLIST

- [ ] Create policy with document
- [ ] Extract clauses from document (auto or manual)
- [ ] Approve clauses
- [ ] Create rules from clauses
- [ ] Add conditions to rules
- [ ] Set enforcement actions (block/warn/escalate)
- [ ] Map events to rules
- [ ] Test mappings via API
- [ ] Activate policy
- [ ] Test module action (should trigger policy)
- [ ] Vefiry audit trail in policy_events table

---

## 12. TROUBLESHOOTING

**Q: Rules not being discovered**
A: Check:
- [ ] EventRuleMap entries exist
- [ ] Rule.active = true
- [ ] Policy.status = 'active'
- [ ] Module and action pattern match exactly
- [ ] Payload filter matches (if specified)

**Q: Old rules still triggering after update**
A: Cache may be stale. Clear it:
```php
app(RuleDiscoveryEngine::class)->clearCacheForEvent('Leave', 'leave_request.submitted');
```

**Q: How to apply rule to multiple events?**
A: Use wildcard patterns:
```php
'action_pattern' => 'leave_request.*'  // All leave_request events
'action_pattern' => 'leave.*'          // All leave events
```

**Q: How to narrow rule to specific scenarios?**
A: Use payload filters:
```php
'payload_filter' => ['leave_type_id' => 1, 'days' => 5]
```

---

## 13. API ENDPOINT REFERENCE

### Querying

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/event-mappings` | GET | List all mappings |
| `/api/event-mappings?module=Leave` | GET | Filter by module |
| `/api/event-mappings/summary` | GET | Global summary |
| `/api/event-mappings/modules` | GET | Available modules |
| `/api/event-mappings/modules/{id}/actions` | GET | Actions in module |
| `/api/event-mappings/report/module/{id}` | GET | Detailed module report |
| `/api/event-mappings/dependencies` | GET | Dependency map |
| `/api/event-mappings/event-rules` | GET | Rules for event (POST payload) |
| `/api/event-mappings/policy/{id}/rules` | GET | Rules in policy |
| `/api/event-mappings/policy/{id}/events` | GET | Events for policy |

### Management

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/event-mappings` | POST | Create mapping |
| `/api/event-mappings/{id}` | GET | Get mapping |
| `/api/event-mappings/{id}` | PUT | Update mapping |
| `/api/event-mappings/{id}` | DELETE | Delete mapping |
| `/api/event-mappings/{id}/activate` | PATCH | Reactivate |
| `/api/event-mappings/{id}/deactivate` | PATCH | Deactivate |

### Testing

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/event-mappings/test` | POST | Test event mapping |

---

## 14. DATABASE SCHEMA

### event_rule_maps table

```sql
CREATE TABLE event_rule_maps (
  id BIGINT PRIMARY KEY,
  rule_id BIGINT NOT NULL (FK → policy_rules),
  module VARCHAR(255),           -- 'Leave', 'Claims', 'Fleet'
  action_pattern VARCHAR(255),   -- 'leave.*', 'claim.submitted'
  payload_filter JSON,           -- {'leave_type_id': 1}
  active BOOLEAN DEFAULT true,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  
  INDEX idx_module_action (module, action_pattern),
  FOREIGN KEY (rule_id) REFERENCES policy_rules(id) ON DELETE CASCADE
);
```

---

End of Guide
