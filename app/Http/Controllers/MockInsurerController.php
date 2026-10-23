<?php

namespace App\Http\Controllers;

use App\Services\IssuanceService;
use App\Services\MockInsurerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MockInsurerController extends Controller
{
    public function __construct(
        protected IssuanceService $issuanceService,
        protected MockInsurerService $mockInsurer,
    ) {}

    /**
     * POST /api/mock-insurer/callback
     * Receive a simulated provider event (Idempotent Callback Processing).
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_id' => ['required', 'string', 'max:100'],
            'event_type' => ['required', 'string', 'in:POLICY_ISSUED,POLICY_FAILED'],
            'provider_reference' => ['nullable', 'string'],
            'policy_request_uuid' => ['nullable', 'string'],
            'policy_number' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid callback payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->issuanceService->processCallback($request->all());

            return response()->json([
                'status' => 'success',
                'idempotent' => $result['idempotent'] ?? false,
                'message' => $result['message'],
                'event_id' => $request->input('event_id'),
            ], 200);

        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unexpected error processing provider callback: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/mock-insurer/policies/{provider_reference}
     * Query provider status for reconciliation.
     */
    public function lookup(string $provider_reference): JsonResponse
    {
        $record = $this->mockInsurer->lookupStatus($provider_reference);

        if (!$record) {
            return response()->json([
                'status' => 'error',
                'message' => "Provider reference '{$provider_reference}' not found in external insurer records.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $record,
        ], 200);
    }
}
