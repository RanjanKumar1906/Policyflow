# PolicyFlow — Fault-Tolerant Insurance Issuance & Reconciliation Engine

> **Campus Placement | Software Engineering Practical Assessment**  
> Technology Stack: **PHP 8.2+**, **Laravel 11/12**, **SQLite / MySQL**, **REST API**, **PHPUnit**.

---

## 1. Executive Summary & Architecture

**PolicyFlow** is a fault-tolerant insurance issuance and reconciliation engine designed for travel insurance distribution. External insurance providers are inherently unreliable—requests experience network timeouts, asynchronous callbacks can be duplicated or delayed, and agents frequently retry requests near-simultaneously.

PolicyFlow guarantees:
1. **Zero Duplicate Issuances**: Strong database-level and service-level idempotency prevents double issuance.
2. **Deterministic Decimal-Safe Pricing**: Precise daily base rates, age-bracket multipliers, destination multipliers, and coverage multipliers calculated and snapshotted immutably upon intake.
3. **Strict State Machine Workflow**: Explicit forward-only state progression with strict rejection of regressions (e.g., `ISSUED` cannot be regressed).
4. **Resilient Provider Reconciliation**: Network timeouts transition requests to `NEEDS_RECONCILIATION`, querying the insurer provider registry before attempting any re-issuance.

```
                           ┌────────────────────────┐
                           │   POST /policy-requests│
                           │     (Agent Intake)     │
                           └───────────┬────────────┘
                                       │
                                       ▼
                       ┌────────────────────────────────┐
                       │  Idempotency Check (DB Unique) │
                       │    (agent_id + client_req_id)  │
                       └───────┬────────────────┬───────┘
                     New       │                │ Duplicate
                               ▼                ▼
                     ┌──────────────────┐    ┌──────────────────┐
                     │ Calculate Premium│    │ Return 200 OK    │
                     │  (Immutable)     │    │ (Existing Record)│
                     └─────────┬────────┘    └──────────────────┘
                               │
                               ▼
                     ┌──────────────────┐
                     │ Status: VALIDATED│
                     └─────────┬────────┘
                               │
                               ▼
                     ┌───────────────────────────┐
                     │POST /policy-requests/{id}/│
                     │          submit           │
                     └─────────────┬─────────────┘
                                   │
              ┌────────────────────┼────────────────────┐
              ▼                    ▼                    ▼
     [Scenario: SUCCESS]  [TIMEOUT_AFTER_ISSUE] [DELAYED_CALLBACK]
              │                    │                    │
              ▼                    ▼                    ▼
     ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
     │ Status: ISSUED  │  │ Status: NEEDS_  │  │ Status: PENDING_│
     │ (Unique Policy#)│  │ RECONCILIATION  │  │  CONFIRMATION   │
     └─────────────────┘  └────────┬────────┘  └────────┬────────┘
                                   │                    │
                          Reconcile Lookup      Callback Webhook
                          before re-issue       (/mock-insurer/cb)
                                   │                    │
                                   └─────────┬──────────┘
                                             ▼
                                    ┌─────────────────┐
                                    │ Status: ISSUED  │
                                    └─────────────────┘
```

---

## 2. Core Implemented Entities & Database Schema

The database is structured into four normalized, relational tables with database-level uniqueness constraints:

| Entity / Table | Purpose & Constraints |
| :--- | :--- |
| `policy_requests` | Core intake request. Stores agent info, trip dates, customer details, immutable `premium` snapshot, JSON `pricing_breakdown`, current `status`, and unique `policy_number`. **Composite unique key**: `(agent_id, client_request_id)`. |
| `issuance_attempts` | Records every attempt made to the insurer. Tracks `attempt_number`, `status`, `scenario`, `request_payload`, `response_payload`, `provider_reference`, and `error_message`. **Composite unique key**: `(policy_request_id, attempt_number)`. |
| `provider_events` | Inbound asynchronous webhooks/callbacks from insurer. Tracks payload, processed status, and timestamps. **Unique key**: `event_id` (guarantees callback idempotency). |
| `policy_state_history` | Complete immutable audit trail logging each state change, from-status, to-status, trigger reason, and metadata. |

---

## 3. Pricing Logic (Deterministic Engine)

The pricing engine (`App\Services\PremiumCalculator`) uses decimal-safe arithmetic via `bcmath` or string math rounded to 2 decimal places:

$$\text{Premium} = \text{base\_daily\_rate} \times \text{travel\_days} \times \text{age\_multiplier} \times \text{destination\_multiplier} \times \text{coverage\_multiplier}$$

### Pricing Rules Matrix:
- **Base Premium per Travel Day**:
  - `BASIC`: ₹12.00
  - `PLUS`: ₹20.00
  - `PREMIUM`: ₹30.00
- **Age Multiplier (Age at Trip Start)**:
  - $0 - 17$: $1.10$
  - $18 - 60$: $1.00$
  - $61 - 70$: $1.50$
  - $71+$: $2.00$
- **Destination Multiplier**:
  - `DOMESTIC`: $1.00$
  - `INTERNATIONAL`: $1.80$
- **Coverage Multiplier**:
  - ₹100,000: $1.00$
  - ₹300,000: $1.25$
  - ₹500,000: $1.50$
- **Travel Days**: Inclusive formula: $\text{trip\_end} - \text{trip\_start} + 1$ (minimum 1 day).

#### Example from Specification:
- Customer DOB: `1998-04-10`, Trip: `2026-11-10` to `2026-11-14` (5 days inclusive)
- Age on trip start: 28 years $\rightarrow$ Multiplier: $1.00$
- Destination: `INTERNATIONAL` $\rightarrow$ Multiplier: $1.80$
- Plan: `PLUS` (₹20.00/day), Sum Insured: ₹300,000 $\rightarrow$ Multiplier: $1.25$
- Calculation: $20.00 \times 5 \times 1.00 \times 1.80 \times 1.25 = \mathbf{₹225.00}$ (Verified in test suite).

---

## 4. State Machine Workflow

Supported states:
`RECEIVED` $\rightarrow$ `VALIDATED` $\rightarrow$ `SUBMITTED` $\rightarrow$ `PENDING_CONFIRMATION` $\rightarrow$ `ISSUED` / `FAILED` / `NEEDS_RECONCILIATION`.

### Transition Matrix:
- `RECEIVED` $\rightarrow$ `VALIDATED`, `FAILED`
- `VALIDATED` $\rightarrow$ `SUBMITTED`, `FAILED`
- `SUBMITTED` $\rightarrow$ `PENDING_CONFIRMATION`, `ISSUED`, `NEEDS_RECONCILIATION`, `FAILED`
- `PENDING_CONFIRMATION` $\rightarrow$ `ISSUED`, `NEEDS_RECONCILIATION`, `FAILED`
- `NEEDS_RECONCILIATION` $\rightarrow$ `SUBMITTED` (safe retry), `ISSUED` (via provider reconciliation), `FAILED`
- `ISSUED` $\rightarrow$ **Terminal state**. Re-affirming issuance is idempotent. Any attempt to regress (e.g., `ISSUED` $\rightarrow$ `SUBMITTED`) throws an `InvalidArgumentException`.

---

## 5. Implemented Reliability & Fault-Tolerance Scenarios

PolicyFlow implements **all 4 reliability scenarios** specified in Section 3.4:

### 1. Duplicate Intake Prevention (Near-Simultaneous Submissions)
- **Problem**: Agents or automated retry scripts send identical requests concurrently.
- **Solution**:
  - Database composite unique index on `(agent_id, client_request_id)`.
  - Application-level transaction with a `QueryException` collision catch handler.
  - Returns `HTTP 201 Created` on original intake, and `HTTP 200 OK` on duplicates with the existing record, preserving the original premium snapshot and request ID.

### 2. Timeout After Success & Safe Reconciliation
- **Problem**: External insurer successfully creates a policy internally, but drops the HTTP connection before returning `HTTP 200`. Blindly calling "issue" again causes double-billing or multiple policy numbers.
- **Solution**:
  - On timeout (scenario `TIMEOUT_AFTER_ISSUE`), the request status moves to `NEEDS_RECONCILIATION` and saves the `provider_reference`.
  - On subsequent submissions or via `POST /api/policy-requests/{id}/reconcile`, the engine first queries `MockInsurerService::lookupStatus($providerReference)`.
  - If the insurer confirms the policy was issued, PolicyFlow updates status to `ISSUED` with the external policy number **without issuing a new policy or incrementing attempt counts unnecessarily**.

### 3. Duplicate Provider Webhook / Callback Idempotency
- **Problem**: Webhook delivery infrastructure sends duplicate HTTP POST events.
- **Solution**:
  - `provider_events` table enforces a database-level `UNIQUE(event_id)`.
  - Duplicate callbacks return `HTTP 200` with `idempotent: true`, ignoring duplicate state changes.
  - If the policy is already `ISSUED`, incoming callbacks cannot regress or overwrite state.

### 4. Retry After Temporary Failure
- **Problem**: Insurer returns `503 Service Unavailable`.
- **Solution**:
  - The failure is captured in `issuance_attempts` with `retriable = true`.
  - The request transitions to `FAILED` or `NEEDS_RECONCILIATION`.
  - Safe retry via `POST /api/policy-requests/{id}/submit` succeeds on subsequent attempt without recalculating or modifying the accepted premium.

---

## 6. API Reference

All requests accept and return `application/json`.

### 6.1 Policy Intake
`POST /api/policy-requests`

**Request Body:**
```json
{
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```

**Responses:**
- `201 Created`: Newly created policy request with calculated premium.
- `200 OK`: Duplicate intake detected; returns existing request safely (`idempotent: true`).
- `422 Unprocessable Content`: Validation failure (invalid dates, unsupported plan/sum).

### 6.2 Retrieve Request Details & Audit Trail
`GET /api/policy-requests/{id}`

Returns full request status, immutable premium snapshot, pricing breakdown, issuance attempts, and state transition audit trail.

### 6.3 Submit Issuance
`POST /api/policy-requests/{id}/submit`

**Optional Header / Body:**
`X-Mock-Scenario` or `{"scenario": "SUCCESS" | "TEMPORARY_FAILURE" | "TIMEOUT_AFTER_ISSUE" | "DELAYED_CALLBACK"}`

**Responses:**
- `200 OK`: Issuance processed or reconciled.
- `422 Unprocessable Content`: Invalid state for submission.

### 6.4 Provider Webhook / Callback
`POST /api/mock-insurer/callback`

**Request Body:**
```json
{
  "event_id": "EVT-2026-001",
  "event_type": "POLICY_ISSUED",
  "provider_reference": "PRV-EXAMPLE-123",
  "policy_number": "POL-2026-CONFIRMED"
}
```

### 6.5 Provider Status Lookup (Reconciliation)
`GET /api/mock-insurer/policies/{provider_reference}`

Queries external provider cache/registry to verify policy status.

### 6.6 List Policy Requests
`GET /api/policy-requests?status=ISSUED&per_page=15`

Supports filtering by `status`, `agent_id`, and standard pagination.

---

## 7. Local Setup and Execution Instructions

### Prerequisites
- PHP 8.2+ with `sqlite3` and `bcmath` extensions enabled
- Composer 2+

### 1. Installation
```bash
git clone <repository_url>
cd policyflow

# Copy environment file
cp .env.example .env

# Install dependencies (if cloning clean repo)
composer install

# Generate application key
php artisan key:generate
```

### 2. Database Setup
```bash
# SQLite is preconfigured by default
touch database/database.sqlite
php artisan migrate:fresh
```

### 3. Run Automated Tests
```bash
php artisan test
```

Expected result:
```text
  Tests:    24 passed (107 assertions)
  Duration: 1.39s
```

### 4. Start Local Development Server
```bash
php artisan serve
```
Application will be accessible at: `http://127.0.0.1:8000`

---

## 8. Known Limitations & Production Considerations

1. **Mock Insurer Storage**: The mock insurer uses Laravel's cache layer to simulate the external insurer's database. In a multi-region production deployment, external status inquiries would communicate over mutual TLS (mTLS) with HMAC-SHA256 signature verification.
2. **Queue Workers**: Currently issuance is synchronous within transactions for immediate response in this assessment. For high throughput, submissions can be offloaded to Laravel Queues with exponential backoff and dead-letter queues.
3. **PFI / PII Data Masking**: While error logs redact sensitive credentials, customer names and dates of birth should be encrypted at rest in production via Laravel Eloquent model encryption (`casts => ['customer_name' => 'encrypted']`).
