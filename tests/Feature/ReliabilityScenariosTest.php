<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReliabilityScenariosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RELIABILITY CASE 1: Duplicate Intake
     * The same client_request_id is submitted repeatedly.
     * System must safely return existing request without creating duplicates or altering state.
     */
    public function test_reliability_case_1_duplicate_intake_prevention(): void
    {
        $payload = [
            'client_request_id' => 'DUP-INTAKE-100',
            'agent_id' => 'AGENT-ALPHA',
            'customer_name' => 'John Duplicate',
            'date_of_birth' => '1990-05-10',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        // 1. Initial request -> 201 Created
        $response1 = $this->postJson('/api/policy-requests', $payload);
        $response1->assertStatus(201)
            ->assertJsonPath('idempotent', false);
        $uuid1 = $response1->json('data.uuid');
        $premium1 = $response1->json('data.premium');

        // 2. Exact duplicate intake -> 200 OK with same data
        $response2 = $this->postJson('/api/policy-requests', $payload);
        $response2->assertStatus(200)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('data.uuid', $uuid1)
            ->assertJsonPath('data.premium', $premium1);

        // 3. Third duplicate intake -> 200 OK
        $response3 = $this->postJson('/api/policy-requests', $payload);
        $response3->assertStatus(200)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('data.uuid', $uuid1);

        // Database assertion: exactly one row exists for this agent + client_request_id
        $this->assertEquals(1, PolicyRequest::where('agent_id', 'AGENT-ALPHA')
            ->where('client_request_id', 'DUP-INTAKE-100')
            ->count());
    }

    /**
     * RELIABILITY CASE 2: Timeout After Success
     * The insurer issued the policy internally, but HTTP client timed out.
     * Before blindly re-issuing, the system reconciles with provider to prevent duplicate issuance.
     */
    public function test_reliability_case_2_timeout_after_success_and_reconciliation(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'TIMEOUT-REQ-200',
            'agent_id' => 'AGENT-BETA',
            'customer_name' => 'Sarah Connor',
            'date_of_birth' => '1985-02-14',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-04',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'premium' => '180.00',
            'status' => 'VALIDATED',
        ]);

        // 1. Submit with TIMEOUT_AFTER_ISSUE scenario
        $timeoutResponse = $this->postJson("/api/policy-requests/{$policy->uuid}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $timeoutResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'NEEDS_RECONCILIATION');

        $this->assertDatabaseHas('policy_requests', [
            'id' => $policy->id,
            'status' => 'NEEDS_RECONCILIATION',
        ]);
        $this->assertNotNull($policy->fresh()->provider_reference);

        // 2. Next attempt/reissue: before blindly issuing again, system checks provider lookup!
        // It discovers the policy was actually issued, and recovers cleanly to ISSUED.
        $reissueResponse = $this->postJson("/api/policy-requests/{$policy->uuid}/submit");

        $reissueResponse->assertStatus(200)
            ->assertJsonPath('reconciled', true)
            ->assertJsonPath('data.status', 'ISSUED');

        $policyNumber = $reissueResponse->json('data.policy_number');
        $this->assertNotEmpty($policyNumber);
        $this->assertStringStartsWith('POL-', $policyNumber);

        // Crucial Check: only 1 issuance attempt was made to external insurer, not 2!
        $this->assertEquals(1, $policy->fresh()->attempt_count);
    }

    /**
     * RELIABILITY CASE 3: Duplicate Callback
     * External provider delivers the identical event multiple times.
     * System processes the event once, rejects subsequent identical events idempotently.
     */
    public function test_reliability_case_3_duplicate_callback_idempotency(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'CALLBACK-REQ-300',
            'agent_id' => 'AGENT-GAMMA',
            'customer_name' => 'Kyle Reese',
            'date_of_birth' => '1987-03-21',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-10',
            'trip_end' => '2026-12-15',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium' => '72.00',
            'status' => 'PENDING_CONFIRMATION',
            'provider_reference' => 'PRV-CALLBACK-300',
        ]);

        $callbackPayload = [
            'event_id' => 'EVT-UNIQUE-777',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => 'PRV-CALLBACK-300',
            'policy_number' => 'POL-CONFIRMED-888',
        ];

        // 1. First callback delivery -> 200 OK, marks ISSUED
        $cb1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb1->assertStatus(200)
            ->assertJsonPath('idempotent', false);

        $this->assertDatabaseHas('policy_requests', [
            'id' => $policy->id,
            'status' => 'ISSUED',
            'policy_number' => 'POL-CONFIRMED-888',
        ]);

        // 2. Second callback delivery (identical event_id) -> 200 OK, idempotent: true
        $cb2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb2->assertStatus(200)
            ->assertJsonPath('idempotent', true);

        // 3. Third callback delivery -> 200 OK, idempotent: true
        $cb3 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb3->assertStatus(200)
            ->assertJsonPath('idempotent', true);

        // Database assertion: Provider event was only recorded once
        $this->assertEquals(1, ProviderEvent::where('event_id', 'EVT-UNIQUE-777')->count());
        $this->assertEquals('ISSUED', $policy->fresh()->status);
    }

    /**
     * RELIABILITY CASE 4: Retry After Temporary Failure
     * Insurer fails temporarily (503). Request can be retried safely.
     * Original calculated premium remains intact and immutable.
     */
    public function test_reliability_case_4_retry_after_temporary_failure(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'RETRY-REQ-400',
            'agent_id' => 'AGENT-DELTA',
            'customer_name' => 'Ellen Ripley',
            'date_of_birth' => '1992-06-18',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-15',
            'trip_end' => '2026-11-20',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
            'premium' => '486.00',
            'status' => 'VALIDATED',
        ]);

        // 1. First attempt fails with TEMPORARY_FAILURE
        $attempt1 = $this->postJson("/api/policy-requests/{$policy->uuid}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $attempt1->assertStatus(200)
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.attempt_count', 1);

        // Original premium must remain immutable ₹486.00
        $this->assertEquals('486.00', (string) $policy->fresh()->premium);

        // 2. Safe Retry with SUCCESS scenario
        $attempt2 = $this->postJson("/api/policy-requests/{$policy->uuid}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $attempt2->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 2);

        // Policy is now issued without mutating initial premium snapshot
        $fresh = $policy->fresh();
        $this->assertEquals('ISSUED', $fresh->status);
        $this->assertEquals('486.00', (string) $fresh->premium);
        $this->assertNotEmpty($fresh->policy_number);
        $this->assertEquals(2, $fresh->attempt_count);
    }

    /**
     * RELIABILITY CASE 5: Confirmed ISSUED state cannot be overwritten by subsequent failure callback
     */
    public function test_confirmed_issued_state_cannot_be_overwritten_by_failure_callback(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'TERMINAL-REQ-500',
            'agent_id' => 'AGENT-EPSILON',
            'customer_name' => 'Luke Skywalker',
            'date_of_birth' => '1995-10-10',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-02',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium' => '24.00',
            'status' => 'ISSUED',
            'policy_number' => 'POL-FINAL-555',
            'provider_reference' => 'PRV-FINAL-555',
        ]);

        // Send a late/erroneous FAILURE callback for this policy
        $failCallback = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-LATE-ERR-999',
            'event_type' => 'POLICY_FAILED',
            'provider_reference' => 'PRV-FINAL-555',
            'reason' => 'Network drop after issuance',
        ]);

        $failCallback->assertStatus(200)
            ->assertJsonPath('idempotent', true);

        // Status must still be ISSUED, policy_number preserved
        $fresh = $policy->fresh();
        $this->assertEquals('ISSUED', $fresh->status);
        $this->assertEquals('POL-FINAL-555', $fresh->policy_number);
    }
}
