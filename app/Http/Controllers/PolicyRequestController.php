<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePolicyRequest;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use App\Services\PolicyStateMachine;
use App\Services\PremiumCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PolicyRequestController extends Controller
{
    public function __construct(
        protected PremiumCalculator $calculator,
        protected PolicyStateMachine $stateMachine,
        protected IssuanceService $issuanceService,
    ) {}

    /**
     * POST /api/policy-requests
     * Create or safely return existing request (Idempotent Intake).
     */
    public function store(StorePolicyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $agentId = $validated['agent_id'];
        $clientRequestId = $validated['client_request_id'];

        // 1. Fast check if logical request already exists
        $existing = PolicyRequest::where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'success',
                'idempotent' => true,
                'message' => 'Policy request already exists for this agent_id and client_request_id.',
                'data' => $this->formatPolicyResponse($existing),
            ], 200);
        }

        // 2. Compute deterministic premium snapshot
        $pricing = $this->calculator->calculate(
            $validated['plan_code'],
            $validated['sum_insured'],
            $validated['destination'],
            $validated['date_of_birth'],
            $validated['trip_start'],
            $validated['trip_end']
        );

        // 3. Database transaction with concurrency collision rescue
        try {
            $policy = DB::transaction(function () use ($validated, $pricing) {
                $policyRequest = PolicyRequest::create([
                    'client_request_id' => $validated['client_request_id'],
                    'agent_id' => $validated['agent_id'],
                    'customer_name' => $validated['customer_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'destination' => strtoupper($validated['destination']),
                    'trip_start' => $validated['trip_start'],
                    'trip_end' => $validated['trip_end'],
                    'plan_code' => strtoupper($validated['plan_code']),
                    'sum_insured' => $validated['sum_insured'],
                    'premium' => $pricing['premium'],
                    'pricing_breakdown' => $pricing['breakdown'],
                    'status' => PolicyStateMachine::STATE_RECEIVED,
                ]);

                // Record initial state
                $this->stateMachine->transition(
                    $policyRequest,
                    PolicyStateMachine::STATE_VALIDATED,
                    'Input validated and premium calculated successfully.'
                );

                return $policyRequest;
            });

            return response()->json([
                'status' => 'success',
                'idempotent' => false,
                'message' => 'Policy request created and validated successfully.',
                'data' => $this->formatPolicyResponse($policy),
            ], 201);

        } catch (QueryException $e) {
            // Near-simultaneous race condition caught by DB unique constraint
            $existing = PolicyRequest::where('agent_id', $agentId)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'idempotent' => true,
                    'message' => 'Policy request created concurrently. Returning existing record.',
                    'data' => $this->formatPolicyResponse($existing),
                ], 200);
            }

            throw $e;
        }
    }

    /**
     * GET /api/policy-requests/{id}
     * Retrieve status, premium, references, and audit trail.
     */
    public function show(string $id): JsonResponse
    {
        $policy = $this->findPolicy($id);

        if (!$policy) {
            return response()->json([
                'status' => 'error',
                'message' => "Policy request '{$id}' not found.",
            ], 404);
        }

        $policy->load(['issuanceAttempts', 'providerEvents', 'stateHistory']);

        return response()->json([
            'status' => 'success',
            'data' => $this->formatPolicyResponse($policy, includeAuditTrail: true),
        ], 200);
    }

    /**
     * GET /api/policy-requests
     * List requests with status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query()->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->query('agent_id'));
        }

        $perPage = (int) $request->query('per_page', 15);
        $paginated = $query->paginate(max(1, min($perPage, 100)));

        return response()->json([
            'status' => 'success',
            'data' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * POST /api/policy-requests/{id}/submit
     * Start or safely resume issuance.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $policy = $this->findPolicy($id);

        if (!$policy) {
            return response()->json([
                'status' => 'error',
                'message' => "Policy request '{$id}' not found.",
            ], 404);
        }

        $scenario = $request->header('X-Mock-Scenario', $request->input('scenario', 'SUCCESS'));

        try {
            $result = $this->issuanceService->submit($policy, $scenario);

            $statusCode = 200;
            return response()->json([
                'status' => 'success',
                'idempotent' => $result['idempotent'] ?? false,
                'reconciled' => $result['reconciled'] ?? false,
                'message' => $result['message'] ?? 'Issuance workflow executed.',
                'data' => $this->formatPolicyResponse($result['policy_request'], includeAuditTrail: true),
            ], $statusCode);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/policy-requests/{id}/reconcile
     * Explicit reconciliation against mock provider.
     */
    public function reconcile(string $id): JsonResponse
    {
        $policy = $this->findPolicy($id);

        if (!$policy) {
            return response()->json([
                'status' => 'error',
                'message' => "Policy request '{$id}' not found.",
            ], 404);
        }

        $result = $this->issuanceService->reconcile($policy);

        return response()->json([
            'status' => 'success',
            'reconciled' => $result['reconciled'],
            'outcome' => $result['status'],
            'message' => $result['message'],
            'data' => $this->formatPolicyResponse($result['policy_request'], includeAuditTrail: true),
        ], 200);
    }

    /**
     * Helper to lookup policy by ID or UUID.
     */
    protected function findPolicy(string $id): ?PolicyRequest
    {
        if (is_numeric($id)) {
            return PolicyRequest::find($id) ?? PolicyRequest::where('uuid', $id)->first();
        }
        return PolicyRequest::where('uuid', $id)->first();
    }

    /**
     * Format standardized JSON structure for a policy request.
     */
    protected function formatPolicyResponse(PolicyRequest $policy, bool $includeAuditTrail = false): array
    {
        $data = [
            'id' => $policy->id,
            'uuid' => $policy->uuid,
            'client_request_id' => $policy->client_request_id,
            'agent_id' => $policy->agent_id,
            'customer_name' => $policy->customer_name,
            'date_of_birth' => $policy->date_of_birth?->format('Y-m-d') ?? $policy->getRawOriginal('date_of_birth'),
            'destination' => $policy->destination,
            'trip_start' => $policy->trip_start?->format('Y-m-d') ?? $policy->getRawOriginal('trip_start'),
            'trip_end' => $policy->trip_end?->format('Y-m-d') ?? $policy->getRawOriginal('trip_end'),
            'plan_code' => $policy->plan_code,
            'sum_insured' => (int) $policy->sum_insured,
            'premium' => (string) $policy->premium,
            'status' => $policy->status,
            'policy_number' => $policy->policy_number,
            'provider_reference' => $policy->provider_reference,
            'attempt_count' => (int) $policy->attempt_count,
            'pricing_breakdown' => $policy->pricing_breakdown,
            'failure_reason' => $policy->failure_reason,
            'created_at' => $policy->created_at?->toIso8601String(),
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ];

        if ($includeAuditTrail) {
            $data['attempts'] = $policy->issuanceAttempts->map(fn($a) => [
                'attempt_number' => $a->attempt_number,
                'status' => $a->status,
                'scenario' => $a->scenario,
                'provider_reference' => $a->provider_reference,
                'policy_number' => $a->policy_number,
                'error_message' => $a->error_message,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

            $data['state_history'] = $policy->stateHistory->map(fn($h) => [
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'reason' => $h->reason,
                'metadata' => $h->metadata,
                'timestamp' => $h->created_at?->toIso8601String(),
            ]);

            $data['provider_events'] = $policy->providerEvents->map(fn($e) => [
                'event_id' => $e->event_id,
                'event_type' => $e->event_type,
                'processed' => $e->processed,
                'processed_at' => $e->processed_at?->toIso8601String(),
            ]);
        }

        return $data;
    }
}
