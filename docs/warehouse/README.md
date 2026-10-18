# Warehouse Management & Intelligence — handover

The developer handover for the WHMI module (§34 of the specification), plus the evidence the release gates ask for. Code: `app/Modules/Operations/WarehouseManagement`, pages: `resources/js/Modules/Warehouse`, migrations: `database/migrations/warehouse`, tests: `tests/Feature/Warehouse`.

| § 34 deliverable | Document |
|---|---|
| 1. Existing-system discovery report | [01-discovery-report.md](01-discovery-report.md) |
| 2. Domain and data model | [02-domain-and-data-model.md](02-domain-and-data-model.md), generated detail in [generated/data-model.md](generated/data-model.md) |
| 3. Workflow diagrams | [03-workflows.md](03-workflows.md) |
| 4. API contract | [04-api-contract.md](04-api-contract.md), machine-readable `resources/api/warehouse-v1.yaml` |
| 5. Permission matrix | [05-permission-matrix.md](05-permission-matrix.md), generated detail in [generated/permissions.md](generated/permissions.md) |
| 6. Accounting mapping | [06-accounting-mapping.md](06-accounting-mapping.md) — **needs Finance sign-off** |
| 7. UI specification | [07-ui-specification.md](07-ui-specification.md), every route in [generated/routes.md](generated/routes.md) |
| 8. Test plan | [08-test-plan.md](08-test-plan.md), acceptance evidence in [qa-matrix.md](qa-matrix.md) |
| 9. Migration and deployment plan | [09-migration-and-deployment.md](09-migration-and-deployment.md) |
| 10. Operations documentation | [10-operations.md](10-operations.md) |

Also: [security-and-privacy.md](security-and-privacy.md) (threat model, POPIA data map), [definition-of-done.md](definition-of-done.md) (§32 with status), [performance-results.md](performance-results.md) (benchmark), and [generated/](generated/) (events, alerts, accounting events, schedule and policies, KPI definitions).

## Keeping these true

The `generated/` folder and two evidence documents are produced from the code and database and must be regenerated rather than edited:

```bash
php artisan wms:handover-docs                     # generated/*.md
php artisan wms:qa-evidence                       # qa-matrix.md (runs the acceptance tests)
php artisan wms:benchmark --doc=docs/warehouse/performance-results.md   # on a disposable staging copy only
```

## Scope note

English only. Nothing here claims regulatory compliance (POPIA), security certification or EPCIS conformance: the controls and mappings are described, the assessments are not done.
