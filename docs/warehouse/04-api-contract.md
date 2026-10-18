# 4. API contract

The machine-readable contract is `resources/api/warehouse-v1.yaml` (OpenAPI 3), served at `/api/v1/warehouse/openapi.yaml`. Routes and the permission each checks: [generated/routes.md](generated/routes.md), [generated/permissions.md](generated/permissions.md). Event types and fields: [generated/events-and-alerts.md](generated/events-and-alerts.md).

## Version and base

`/api/v1/warehouse`. A field may be added within v1; removing or renaming one, or changing its meaning, makes v2 alongside v1.

## Authentication and authorisation

- Sanctum personal access tokens (`Authorization: Bearer …`) of a user who belongs to one company. A token without a company (a platform administrator) is refused.
- Every request is checked twice: the user's warehouse permission, and the token's ability (`wms:read`, `wms:orders`, `wms:webhooks`, `wms:tracking`, or `*`).
- Abilities `wms:warehouse:CODE` limit a token to those warehouses; it is also never wider than the warehouses its user may work in.
- A deactivated or locked account's tokens stop working at once.
- The module must be active for the company.

## Rate limits

300 requests a minute per token by default (`config('warehouse.api_per_minute')`); beyond that `429` with `Retry-After`.

## Idempotency

Commands (`POST`, `PATCH`, `DELETE`) honour `Idempotency-Key` (8–120 characters, per user, 24 hours):

| Situation | Answer |
|---|---|
| Same key, same body, finished | The first response, with `Idempotent-Replay: true` |
| Same key, different body | `422` |
| Same key, first still running | `409` |
| First was refused (4xx/5xx) | Not remembered: correct and resend with the same key |

Orders are also idempotent on `external_ref` (a resent order answers `200` with the existing order).

## Errors

JSON `{ "message": "…", "errors": { field: [..] } }`. `401` unauthenticated, `403` permission/ability/warehouse/inactive account, `404` not found or another company's (identical by design), `409` conflict, `422` validation or a business refusal, `429` rate limit. A malformed identifier answers `404`, never `500`.

## Events

Every event has `sequence` (monotonic), `event_id` (UUID), `type`, `schema_version`, `occurred_at`, `warehouse`, `subject` and `data`. Events are written in the same database transaction as the work (transactional outbox) and cannot be rewritten afterwards.

- **Feed:** `GET /events?after=<sequence>` — store `next_after` and poll.
- **Webhooks:** subscribe per event type (and optionally per warehouse); https and public addresses only. Each delivery is signed:

```
X-Mkhaya-Event: warehouse.shipment.dispatched
X-Mkhaya-Event-Id: 6f0c…
X-Mkhaya-Schema-Version: 1
X-Mkhaya-Timestamp: 1760310000
X-Mkhaya-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + body)
```

Receivers verify the signature, refuse timestamps older than five minutes, and de-duplicate on `event_id` (a delivery can arrive more than once). Failed deliveries retry with back-off (up to 8 attempts), then wait as dead letters until replayed (`POST /events/{eventId}/replay`). An endpoint failing 20 times in a row is switched off.

## ERP and commerce systems

Messages travel through Manufacturing's integration hub: `POST /api/manufacturing/integrations/{uuid}/webhook` (HMAC `X-Mkhaya-Signature`) or scheduled sync. Warehouse message types:

| Direction | Type | Effect |
|---|---|---|
| In | `WMS_ORDER` | An order to fulfil (products and warehouses by mapping, else SKU/code) |
| In | `WMS_STOCK_LEVELS` | The other system's stock, reconciled against the ledger |
| Out | `WMS_RECEIPT`, `WMS_DISPATCH`, `WMS_ADJUSTMENT`, `WMS_RETURN` | From the outbox, once each, with the other system's references |

Each message is processed once per idempotency key; failures retry and end as dead letters for a person to retry or set aside.

## Standards (EPCIS / GS1)

`GET /epcis/events?from&to[&warehouse]` returns an EPCIS 2.0-shaped JSON-LD document: ObjectEvents for movements, TransformationEvents for production, AggregationEvents for packed SSCCs, with CBV business steps and dispositions and GS1 Digital Link identifiers where GTINs and GLNs are held. **It is a mapping, not a validated or certified EPCIS implementation**; the document says so and lists the GS1 keys still missing.
