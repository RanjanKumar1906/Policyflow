# AI Usage and Verification Disclosure

This document complies with the **Campus Placement | Software Engineering Practical Assessment AI Policy** guidelines.

---

## 1. AI Tools Used
- **Google Antigravity Agent (Gemini 3.8 / Claude 4.6)**: Assisted with project scaffolding, architectural planning, test case generation, and documentation.
- **PHP CLI / PHPUnit**: Static analysis and automated test verification.

---

## 2. Concrete Ways AI Assisted in the Implementation
1. **Architectural Scaffolding & State Machine Design**: AI helped outline the state transitions table and formalize the transition matrix between `RECEIVED`, `VALIDATED`, `SUBMITTED`, `PENDING_CONFIRMATION`, `NEEDS_RECONCILIATION`, `ISSUED`, and `FAILED`, ensuring invalid regressions (such as `ISSUED → SUBMITTED`) are blocked at both database and domain service boundaries.
2. **Deterministic Decimal Arithmetic with BCMATH**: AI assisted in drafting the string-based decimal calculations for the multi-tier pricing formula, accounting for age at trip start, destination multiplier, and coverage tier multipliers with rounding to two decimal places.
3. **Comprehensive Test Suite Generation**: AI generated edge-case test matrices covering duplicate intake near-simultaneous collisions, timeout recovery before reissue, duplicate callback idempotency, and terminal state protection.

---

## 3. Inaccurate or Incomplete Suggestions Encountered and Fixed
1. **Implicit Float-to-Int Conversion in PHP 8.2+**:
   - *Initial AI Suggestion*: Used Carbon's `$dob->diffInYears($tripStart, false)` cast directly to `int`, which triggered a PHP 8.2+ deprecation notice (`Implicit conversion from float to int loses precision`).
   - *Correction*: Refactored to native PHP `DateInterval` year extraction: `(int) $dobDate->diff($startDate)->y`, ensuring exact calendar year difference with zero deprecation warnings.
2. **Blind Retry on HTTP Timeout**:
   - *Initial AI Suggestion*: When catching a timeout on the mock insurer HTTP call, the default suggestion was to transition to `FAILED` and prompt the agent to retry `POST /submit`.
   - *Correction*: In an unreliable insurer integration, a network timeout could occur after the insurer has already committed and issued the policy. Blind re-issuing would result in double-issuance or duplicate policies. The architecture was corrected to transition to `NEEDS_RECONCILIATION`, save the `provider_reference`, and query the provider status lookup before initiating any re-issuance attempt.
3. **Race Condition in Duplicate Intake**:
   - *Initial AI Suggestion*: Checked `PolicyRequest::where('agent_id', ...)->first()` solely in application code before `create()`.
   - *Correction*: Under high concurrency / near-simultaneous requests, both threads could pass the `where()` check before inserting. We added a composite unique database index `unique(['agent_id', 'client_request_id'])` and wrapped intake in a transaction with a `QueryException` collision catch block to ensure absolute idempotency under race conditions.

---

## 4. Verification and Validation Methodology
- **Automated Unit & Feature Tests**: Ran all 24 tests using `php artisan test`, ensuring 100% pass rate (107 assertions) across pricing, state transitions, API contract adherence, and reliability edge cases.
- **Manual Verification**: Verified each endpoint using `curl` matching the exact JSON payloads from Section 4 of the specification.
- **Database Integrity**: Inspected SQLite database schema migrations and constraints to verify composite unique indexes and foreign key cascades.
