<?php

namespace App\Services;

use App\Models\PolicyRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MockInsurerService
{
    public const SCENARIO_SUCCESS = 'SUCCESS';
    public const SCENARIO_TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    public const SCENARIO_TIMEOUT_AFTER_ISSUE = 'TIMEOUT_AFTER_ISSUE';
    public const SCENARIO_DELAYED_CALLBACK = 'DELAYED_CALLBACK';
    public const SCENARIO_DUPLICATE_CALLBACK = 'DUPLICATE_CALLBACK';

    /**
     * Submit policy issuance request to the mock insurer.
     */
    public function issuePolicy(PolicyRequest $policyRequest, ?string $scenarioOverride = null): array
    {
        $scenario = strtoupper($scenarioOverride ?: self::SCENARIO_SUCCESS);
        $providerReference = 'PRV-' . strtoupper(Str::random(10));
        $cacheKey = "mock_provider_record:{$providerReference}";

        switch ($scenario) {
            case self::SCENARIO_SUCCESS:
                $policyNumber = 'POL-' . date('Y') . '-' . strtoupper(Str::random(8));
                $record = [
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'status' => 'ISSUED',
                    'client_request_id' => $policyRequest->client_request_id,
                    'agent_id' => $policyRequest->agent_id,
                ];
                Cache::put($cacheKey, $record, now()->addDays(7));
                Cache::put("mock_provider_by_agent_req:{$policyRequest->agent_id}:{$policyRequest->client_request_id}", $record, now()->addDays(7));

                return [
                    'success' => true,
                    'status_code' => 200,
                    'data' => [
                        'provider_reference' => $providerReference,
                        'policy_number' => $policyNumber,
                        'status' => 'ISSUED',
                        'message' => 'Policy issued successfully by external insurer',
                    ],
                ];

            case self::SCENARIO_TEMPORARY_FAILURE:
                return [
                    'success' => false,
                    'status_code' => 503,
                    'error' => [
                        'code' => 'INSURER_TEMPORARY_UNAVAILABLE',
                        'message' => 'External insurer gateway timeout or upstream rate limit exceeded. Please retry.',
                        'retriable' => true,
                        'provider_reference' => $providerReference,
                    ],
                ];

            case self::SCENARIO_TIMEOUT_AFTER_ISSUE:
                // Insurer issues internally, but HTTP connection drops before client receives 200!
                $policyNumber = 'POL-' . date('Y') . '-' . strtoupper(Str::random(8));
                $record = [
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'status' => 'ISSUED',
                    'client_request_id' => $policyRequest->client_request_id,
                    'agent_id' => $policyRequest->agent_id,
                ];
                Cache::put($cacheKey, $record, now()->addDays(7));
                Cache::put("mock_provider_by_agent_req:{$policyRequest->agent_id}:{$policyRequest->client_request_id}", $record, now()->addDays(7));

                return [
                    'success' => false,
                    'status_code' => 504,
                    'error' => [
                        'code' => 'HTTP_CLIENT_TIMEOUT',
                        'message' => 'Insurer connection timed out while waiting for HTTP response payload.',
                        'provider_reference' => $providerReference,
                        'retriable' => false,
                        'reconciliation_required' => true,
                    ],
                ];

            case self::SCENARIO_DELAYED_CALLBACK:
            case self::SCENARIO_DUPLICATE_CALLBACK:
                // External provider queues issuance asynchronously and will send webhook / callback
                $policyNumber = 'POL-' . date('Y') . '-' . strtoupper(Str::random(8));
                $record = [
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'status' => 'QUEUED_FOR_CALLBACK',
                    'client_request_id' => $policyRequest->client_request_id,
                    'agent_id' => $policyRequest->agent_id,
                ];
                Cache::put($cacheKey, $record, now()->addDays(7));
                Cache::put("mock_provider_by_agent_req:{$policyRequest->agent_id}:{$policyRequest->client_request_id}", $record, now()->addDays(7));

                return [
                    'success' => true,
                    'status_code' => 202,
                    'data' => [
                        'provider_reference' => $providerReference,
                        'status' => 'PENDING_CONFIRMATION',
                        'message' => 'Issuance accepted by insurer asynchronously. Final outcome will be delivered via callback.',
                        'callback_expected' => true,
                    ],
                ];

            default:
                throw new \InvalidArgumentException("Unknown mock scenario: {$scenario}");
        }
    }

    /**
     * Provider status lookup endpoint / reconciliation check.
     * Allows system to reconcile when a timeout occurred.
     */
    public function lookupStatus(string $providerReference): ?array
    {
        $cacheKey = "mock_provider_record:{$providerReference}";
        return Cache::get($cacheKey);
    }

    /**
     * Provider lookup by agent_id and client_request_id.
     */
    public function lookupByAgentRequest(string $agentId, string $clientRequestId): ?array
    {
        return Cache::get("mock_provider_by_agent_req:{$agentId}:{$clientRequestId}");
    }
}
