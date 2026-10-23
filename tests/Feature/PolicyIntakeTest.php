<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_policy_intake_creates_record_with_calculated_premium(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('idempotent', false)
            ->assertJsonPath('data.premium', '225.00')
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.client_request_id', 'AGT-2026-0001')
            ->assertJsonPath('data.agent_id', 'AGENT-17');

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'status' => 'VALIDATED',
            'premium' => 225.00,
        ]);
    }

    public function test_intake_rejects_trip_end_before_trip_start(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0002',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-14',
            'trip_end' => '2026-11-10', // Invalid: before start
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['trip_end']]);
    }

    public function test_intake_rejects_unsupported_plan_code(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0003',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'SUPER_TITANIUM', // Unsupported
            'sum_insured' => 100000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['plan_code']]);
    }

    public function test_repeated_submission_with_same_agent_and_client_request_id_returns_200(): void
    {
        $payload = [
            'client_request_id' => 'IDEMP-REQ-001',
            'agent_id' => 'AGENT-99',
            'customer_name' => 'Jane Agent',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        // First intake: creates request -> 201 Created
        $first = $this->postJson('/api/policy-requests', $payload);
        $first->assertStatus(201)
            ->assertJsonPath('idempotent', false);

        $originalUuid = $first->json('data.uuid');
        $originalPremium = $first->json('data.premium');

        // Second intake with identical agent_id + client_request_id -> 200 OK
        $second = $this->postJson('/api/policy-requests', $payload);
        $second->assertStatus(200)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('data.uuid', $originalUuid)
            ->assertJsonPath('data.premium', $originalPremium);

        // Database must have exactly ONE record
        $this->assertEquals(1, PolicyRequest::where('agent_id', 'AGENT-99')->where('client_request_id', 'IDEMP-REQ-001')->count());
    }

    public function test_can_retrieve_policy_request_by_id_or_uuid(): void
    {
        $payload = [
            'client_request_id' => 'LOOKUP-001',
            'agent_id' => 'AGENT-88',
            'customer_name' => 'John Lookup',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-10',
            'trip_end' => '2026-12-12',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $createRes = $this->postJson('/api/policy-requests', $payload);
        $uuid = $createRes->json('data.uuid');
        $id = $createRes->json('data.id');

        // Lookup by UUID
        $uuidRes = $this->getJson("/api/policy-requests/{$uuid}");
        $uuidRes->assertStatus(200)
            ->assertJsonPath('data.client_request_id', 'LOOKUP-001');

        // Lookup by ID
        $idRes = $this->getJson("/api/policy-requests/{$id}");
        $idRes->assertStatus(200)
            ->assertJsonPath('data.uuid', $uuid);
    }

    public function test_can_list_and_filter_policy_requests_with_pagination(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/policy-requests', [
                'client_request_id' => "LIST-{$i}",
                'agent_id' => 'AGENT-PAGINATE',
                'customer_name' => "Customer {$i}",
                'date_of_birth' => '1990-01-01',
                'destination' => 'DOMESTIC',
                'trip_start' => '2026-12-01',
                'trip_end' => '2026-12-02',
                'plan_code' => 'BASIC',
                'sum_insured' => 100000,
            ]);
        }

        $listRes = $this->getJson('/api/policy-requests?agent_id=AGENT-PAGINATE&per_page=2');
        $listRes->assertStatus(200)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 3);
    }
}
