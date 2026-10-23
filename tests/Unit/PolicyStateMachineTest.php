<?php

namespace Tests\Unit;

use App\Models\PolicyRequest;
use App\Services\PolicyStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PolicyStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected PolicyStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new PolicyStateMachine();
    }

    public function test_valid_forward_transitions(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'REQ-001',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Alice Doe',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-08-01',
            'trip_end' => '2026-08-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium' => '60.00',
            'status' => PolicyStateMachine::STATE_RECEIVED,
        ]);

        $this->stateMachine->transition($policy, PolicyStateMachine::STATE_VALIDATED);
        $this->assertEquals(PolicyStateMachine::STATE_VALIDATED, $policy->status);

        $this->stateMachine->transition($policy, PolicyStateMachine::STATE_SUBMITTED);
        $this->assertEquals(PolicyStateMachine::STATE_SUBMITTED, $policy->status);

        $this->stateMachine->transition($policy, PolicyStateMachine::STATE_ISSUED);
        $this->assertEquals(PolicyStateMachine::STATE_ISSUED, $policy->status);
    }

    public function test_regression_from_issued_is_strictly_prohibited(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'REQ-002',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Bob Smith',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-08-01',
            'trip_end' => '2026-08-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium' => '60.00',
            'status' => PolicyStateMachine::STATE_ISSUED,
            'policy_number' => 'POL-TEST-1234',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->stateMachine->transition($policy, PolicyStateMachine::STATE_SUBMITTED);
    }

    public function test_state_history_records_audit_trail(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'REQ-003',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Charlie',
            'date_of_birth' => '1992-02-02',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-08-01',
            'trip_end' => '2026-08-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium' => '60.00',
            'status' => PolicyStateMachine::STATE_RECEIVED,
        ]);

        $this->stateMachine->transition(
            $policy,
            PolicyStateMachine::STATE_VALIDATED,
            'Passed intake validation rules',
            ['source' => 'api']
        );

        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $policy->id,
            'from_status' => PolicyStateMachine::STATE_RECEIVED,
            'to_status' => PolicyStateMachine::STATE_VALIDATED,
            'reason' => 'Passed intake validation rules',
        ]);
    }
}
