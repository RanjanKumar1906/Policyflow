<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_with_success_scenario_issues_policy_and_generates_policy_number(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'SUBMIT-REQ-001',
            'agent_id' => 'AGENT-22',
            'customer_name' => 'Michael Scott',
            'date_of_birth' => '1985-03-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium' => '60.00',
            'status' => 'VALIDATED',
        ]);

        $response = $this->postJson("/api/policy-requests/{$policy->uuid}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 1);

        $policyNumber = $response->json('data.policy_number');
        $this->assertNotEmpty($policyNumber);
        $this->assertStringStartsWith('POL-', $policyNumber);

        // Verify audit trail / state history in response
        $this->assertNotEmpty($response->json('data.state_history'));
        $this->assertNotEmpty($response->json('data.attempts'));

        // Verify database updates
        $this->assertDatabaseHas('policy_requests', [
            'id' => $policy->id,
            'status' => 'ISSUED',
            'policy_number' => $policyNumber,
            'attempt_count' => 1,
        ]);

        $this->assertDatabaseHas('issuance_attempts', [
            'policy_request_id' => $policy->id,
            'attempt_number' => 1,
            'status' => 'SUCCESS',
            'policy_number' => $policyNumber,
        ]);
    }

    public function test_submit_already_issued_policy_is_idempotent_and_does_not_call_provider_again(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'SUBMIT-REQ-002',
            'agent_id' => 'AGENT-22',
            'customer_name' => 'Dwight Schrute',
            'date_of_birth' => '1980-01-20',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
            'premium' => '405.00',
            'status' => 'ISSUED',
            'policy_number' => 'POL-EXISTING-999',
            'provider_reference' => 'PRV-EXISTING-123',
            'attempt_count' => 1,
        ]);

        // Submit again on already ISSUED request
        $response = $this->postJson("/api/policy-requests/{$policy->uuid}/submit");

        $response->assertStatus(200)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('data.policy_number', 'POL-EXISTING-999')
            ->assertJsonPath('data.attempt_count', 1);

        // Attempt count must NOT have increased
        $this->assertEquals(1, $policy->fresh()->attempt_count);
    }
}
