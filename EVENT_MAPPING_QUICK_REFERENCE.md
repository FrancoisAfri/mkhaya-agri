## Event Mapping Quick Reference

This document provides a quick lookup for event mapping operations.

### Architecture Layers

```
┌─────────────────────────────────────────┐
│ EVENT MAPPING LAYERS                    │
├─────────────────────────────────────────┤
│ Layer 3: Controllers                    │
│  EventMappingController (API)           │
│  ├── Create/Update/Delete mappings      │
│  ├── Query reports & summaries          │
│  └── Test event logic                   │
├─────────────────────────────────────────┤
│ Layer 2: Services                       │
│  EventPolicyMapper                      │
│  ├── High-level query interface         │
│  ├── Manage event↔rule mappings         │
│  └── Generate reports                   │
│                                         │
│  RuleDiscoveryEngine                    │
│  ├── High-performance discovery         │
│  ├── Caching (1 hour TTL)               │
│  ├── Pattern matching (wildcards)       │
│  └── Batch operations                   │
├─────────────────────────────────────────┤
│ Layer 1: Models                         │
│  EventRuleMap (join table)              │
│  ├── Links events to rules              │
│  ├── Stores action patterns             │
│  ├── Supports payload filters           │
│  └── Can be active/inactive             │
│                                         │
│  PolicyRule (enforcement rules)         │
│  PolicyEvent (audit records)            │
└─────────────────────────────────────────┘
```

### 1-Minute Setup

```php
// 1. Create mapping
EventRuleMap::create([
    'rule_id' => $rule->id,
    'module' => 'Leave',
    'action_pattern' => 'leave_request.submitted',
]);

// 2. Test it works
POST /api/event-mappings/test
{
    "module": "Leave",
    "action": "leave_request.submitted",
    "payload": {"user_id": 1}
}

// 3. Activate policy
$policy->update(['status' => 'active']);

// 4. Done! Rules now enforced
```

### Common Tasks

**Task: Map a rule to an event**
```php
$mapper->mapRuleToEvent(42, 'Leave', 'leave_request.submitted');
```

**Task: Get all rules for an event**
```php
$rules = $engine->queryRulesForEvent(new PolicyEventDTO(
    module: 'Leave',
    action: 'leave_request.submitted',
    payload: $data
));
```

**Task: Get all events for a policy**
```php
$events = $mapper->getEventsForPolicy($policyId);
```

**Task: Apply rule to multiple events**
```php
// Use wildcard patterns:
$mapper->mapRuleToEvent(42, 'Leave', 'leave_request.*');
$mapper->mapRuleToEvent(42, 'Leave', 'leave.*');
```

**Task: Apply rule only to specific scenarios**
```php
// Use payload filters:
$mapper->mapRuleToEvent(
    42, 'Leave', 'leave_request.submitted',
    ['leave_type_id' => 1, 'days' => 5]
);
```

**Task: Test if event triggers any rules**
```bash
POST /api/event-mappings/test
{
    "module": "Leave",
    "action": "leave_request.submitted",
    "payload": {"user_id": 1, "days": 20}
}
```

**Task: Get module summary**
```bash
GET /api/event-mappings/summary
```

**Task: Get detailed module report**
```bash
GET /api/event-mappings/report/module/Leave
```

**Task: Clear cache after rules change**
```php
app(RuleDiscoveryEngine::class)->clearCacheForEvent('Leave', 'leave_request.submitted');
```

### Database Queries

**Find unmapped rules (no events trigger them)**
```sql
SELECT r.id, r.name, p.name as policy
FROM policy_rules r
JOIN policy p ON r.policy_id = p.id
LEFT JOIN event_rule_maps e ON r.id = e.rule_id
WHERE e.id IS NULL AND r.active = 1;
```

**Find overly broad mappings (rules applied to many events)**
```sql
SELECT e.rule_id, r.name, COUNT(DISTINCT e.action_pattern) as event_count
FROM event_rule_maps e
JOIN policy_rules r ON e.rule_id = r.id
WHERE e.active = 1
GROUP BY e.rule_id
ORDER BY event_count DESC
LIMIT 10;
```

**Find modules with no mappings**
```sql
SELECT DISTINCT p.module
FROM policy p
WHERE p.status = 'active'
AND p.id NOT IN (
    SELECT DISTINCT p2.id
    FROM policy p2
    JOIN policy_rules pr ON p2.id = pr.policy_id
    JOIN event_rule_maps e ON pr.id = e.rule_id
    WHERE e.active = 1
);
```

### API Cheat Sheet

| Operation | Endpoint | Method |
|-----------|----------|--------|
| List mappings | `/api/event-mappings` | GET |
| Create mapping | `/api/event-mappings` | POST |
| Get mapping | `/api/event-mappings/{id}` | GET |
| Update mapping | `/api/event-mappings/{id}` | PUT |
| Deactivate | `/api/event-mappings/{id}/deactivate` | PATCH |
| Activate | `/api/event-mappings/{id}/activate` | PATCH |
| Delete | `/api/event-mappings/{id}` | DELETE |
| Get summary | `/api/event-mappings/summary` | GET |
| Get modules | `/api/event-mappings/modules` | GET |
| Get module's actions | `/api/event-mappings/modules/{module}/actions` | GET |
| Get module report | `/api/event-mappings/report/module/{module}` | GET |
| Test mapping | `/api/event-mappings/test` | POST |
| Get policy's rules | `/api/event-mappings/policy/{id}/rules` | GET |
| Get policy's events | `/api/event-mappings/policy/{id}/events` | GET |
| Get dependencies | `/api/event-mappings/dependencies` | GET |

### Key Classes & Methods

**EventPolicyMapper:**
```
getPoliciesForModule(string): Collection
getRulesForEvent(PolicyEventDTO): Collection
getPoliciesForEvent(PolicyEventDTO): Collection
getEventsForPolicy(int): Collection
mapRuleToEvent(int, string, string, ?array): EventRuleMap
unmapRuleFromEvent(int, string, string): bool
testEventMapping(PolicyEventDTO): array
getMappingReportForModule(string): array
getGlobalMappingSummary(): array
getDependencyMap(): array
```

**RuleDiscoveryEngine:**
```
discoverRulesForEvent(PolicyEventDTO): Collection          [CACHED]
queryRulesForEvent(PolicyEventDTO): Collection             [FRESH]
getRulesForModuleAction(string, string): Collection
getActionPatternsForModule(string): array
getModulesWithMappings(): array
clearCacheForEvent(string, string): void
clearCacheForModule(string): void
clearAllCaches(): void
```

### Troubleshooting Matrix

| Issue | Check | Solution |
|-------|-------|----------|
| Rules not triggering | EventRuleMap exists? | Create mapping via API |
| | Module matches? | Verify exact spelling |
| | Action matches? | Check action pattern (wildcards) |
| | Rule active? | Rule.active = 1 |
| | Policy active? | Policy.status = 'active' |
| | Payload matches filter? | Check payload_filter column |
| Old rules still trigger | Cache stale? | Clear cache |
| Multiple rules for same event | Expected | Will all evaluate |
| Performance slow | Many rules? | Use caching (default) |
| | Payloads large? | Check payload_filter complexity |
| | Module large? | Partition mappings by pattern |

### Files Reference

| File | Location | Purpose |
|------|----------|---------|
| Service | `app/Modules/Policies/Services/Mapping/EventPolicyMapper.php` | Query interface |
| Engine | `app/Modules/Policies/Services/Mapping/RuleDiscoveryEngine.php` | Rule discovery |
| Controller | `app/Modules/Policies/Http/Controllers/EventMappingController.php` | Admin API |
| Model | `app/Modules/Policies/Models/EventRuleMap.php` | Event↔rule link |
| Routes | `routes/modules/policies.php` | API endpoints |
| Guide | `EVENT_MAPPING_COMPLETE_GUIDE.md` | Full documentation |
| This file | `EVENT_MAPPING_QUICK_REFERENCE.md` | Quick lookup |

### Export/Import

**Export all mappings for backup:**
```php
$mappings = EventRuleMap::all();
Storage::put('mappings_backup.json', json_encode($mappings));
```

**Import mappings:**
```php
$data = json_decode(Storage::get('mappings_backup.json'));
foreach ($data as $mapping) {
    EventRuleMap::create((array) $mapping);
}
```

### Testing Helpers

```php
// Test if event would be blocked
$mapper = app(EventPolicyMapper::class);
$test = $mapper->testEventMapping($event);
if ($test['is_blocked']) {
    dd($test['block_reason']);
}

// Get just blocking rules
$rules = $mapper->getRulesForEvent($event);
$blocking = $rules->where('action_on_breach', 'block');

// Dry-run evaluation
$engine = app(RuleDiscoveryEngine::class);
$applicable = $engine->queryRulesForEvent($event);
echo "This event triggers " . $applicable->count() . " rules";
```

---

For complete details, see `EVENT_MAPPING_COMPLETE_GUIDE.md`
