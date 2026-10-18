# 3. Workflow diagrams

Mermaid diagrams; each names the service that does the work.

## Receiving (WMS-030..035)

```mermaid
flowchart TD
    A[Expected receipt: open PO line] --> B{Dock appointment?}
    B -- yes --> C[Arrived / unloading]
    B -- no --> D[Receive at dock]
    C --> D
    D --> E[Receiving::receiveOrder<br/>locks the PO row]
    E --> F{Delivery note seen before?}
    F -- yes, not allowed --> X[Refused]
    F -- no --> G[Procurement createGRN]
    G --> H[GrnStockPoster: StockLedger receive<br/>Dr inventory / Cr GRNI]
    H --> I{Discrepancies?<br/>over, under, damaged, short shelf life}
    I -- yes --> J[ReceiptDiscrepancies: open, alert buyer]
    I -- no --> K[Put-away tasks]
    H --> L{Inspection required?}
    L -- yes --> M[Quarantine + inspection<br/>Manufacturing QualityControl]
    M -- accept --> K
    M -- reject --> N[Supplier return]
```

## Put-away (WMS-040..043)

```mermaid
flowchart LR
    A[Stock at dock / unbinned] --> B[PutawayTasks::create<br/>PutawayAdvisor suggests a bin]
    B --> C[Operator scans item + bin]
    C --> D{Directed warehouse and different bin?}
    D -- yes, no override --> X[Refused]
    D -- no --> E[StockLedger transfer bin to bin]
    E --> F[DONE / PARTIAL / FAILED queue]
```

## Transfers (WMS-052)

```mermaid
stateDiagram-v2
    [*] --> DRAFT
    DRAFT --> APPROVED: approve (wms.transfer.approve)
    APPROVED --> PICKED: pick (checks stock)
    PICKED --> DISPATCHED: dispatch -- all lines or none
    DISPATCHED --> PARTIALLY_RECEIVED: receive some
    DISPATCHED --> RECEIVED: receive all
    PARTIALLY_RECEIVED --> RECONCILED: reconcile with reason (transit loss adjustment)
    RECEIVED --> RECONCILED
    DRAFT --> CANCELLED
    DISPATCHED --> CANCELLED: recall to source
```

## Picking, packing and dispatch (WMS-060..066)

```mermaid
flowchart TD
    A[Order: sales, web shop, API, ERP, production] --> B[FulfilmentOrders::create<br/>same external ref = same order]
    B --> C[Release] --> D[Allocator: FEFO/FIFO, skips expired, blocked, recalled<br/>locks positions]
    D --> E{Enough?}
    E -- no, partial not allowed --> F[Backordered, holds nothing]
    E -- yes --> G[Picking::release wave] --> H[Scan-checked pick]
    H --> I{Short?} -- yes --> J[Look elsewhere; open a count of the bin]
    H --> K[Packing: SSCC per package, pack check]
    K --> L[Dispatching: quality gate, pack check,<br/>StockLedger dispatch, Dr COGS / Cr inventory]
    L --> M[Carrier booking, tracking]
    M --> N[Proof of delivery]
```

## Stocktake (WMS-070..073)

```mermaid
flowchart TD
    A[Plan: cycle / ABC / event / wall-to-wall] --> B[Count opens: snapshot, optional bin freeze]
    B --> C[Blind count entries, append-only, offline allowed]
    C --> D{Within tolerance?}
    D -- yes --> E[MATCHED]
    D -- no --> F[Recount by a different counter]
    F --> G{Agrees?} -- no --> F
    G -- yes --> H[Variance: suggested causes]
    H --> I{Above adjustment limit?}
    I -- yes --> J[Approval levels -- WarehouseApprovals]
    I -- no --> K[Post adjustment]
    J --> K
    K --> L[Bins unfrozen; accuracy recorded]
```

## Customer returns (WMS-080..083)

```mermaid
flowchart LR
    A[Authorise against an order<br/>never more than was sent] --> B[Receive into quarantine at cost<br/>Dr inventory / Cr COGS]
    B --> C[Inspection]
    C -- restock --> D[Released to available]
    C -- repair --> E[Repair, inspect again]
    C -- scrap --> F[Scrap: Dr Scrap / Cr inventory]
    B --> G[Credit note by Finance, once]
```

## Accounting reconciliation (WMS-100..104)

```mermaid
flowchart TD
    A[Every stock movement] --> B{Accounting rule for the event}
    B -- POST --> C[Finance Ledger with idempotency key]
    B -- NONE --> D[No journal by design]
    B -- SUSPENDED --> X[Refused: nothing moves]
    E[wms:reconcile nightly] --> F[Stock subledger value vs GL inventory accounts]
    E --> G[Movements that should have a journal and do not]
    F --> H[wms_accounting_issues: open until cleared]
    G --> H
    I[wms:verify-ledger nightly] --> J[Projections vs ledger] --> K[Audit + health page]
```

## Approvals (WMS-191)

```mermaid
sequenceDiagram
    participant R as Requester
    participant A as WarehouseApprovals
    participant L1 as Level 1 approver
    participant L2 as Level 2 approver / delegate
    R->>A: request (adjustment, revaluation)
    A->>A: lay out levels by value (policy)
    L1->>A: approve (not the requester)
    A-->>L2: due by deadline; escalate if overdue
    L2->>A: approve (different person; delegate recorded as on behalf of)
    A->>R: posted
```
