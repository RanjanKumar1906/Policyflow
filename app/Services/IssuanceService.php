<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IssuanceService
{
    public function __construct(
        protected MockInsurerService $mockInsurer,
        protected PolicyStateMachine $stateMachine,
    ) {}

    /**
     * Start or safely resume issuance with idempotency and retry guards.
     */
    public function submit(PolicyRequest $policyRequest, ?string $scenario = null): array
    {
        return DB::transaction(function () use ($policyRequest, $scenario) {
            // Lock row for concurrency protection
            /** @var PolicyRequest $lockedRequest */
            $lockedRequest = PolicyRequest::query()
                ->where('id', $policyRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Idempotency Check: if already ISSUED, return immediately
            if ($lockedRequest->status === PolicyStateMachine::STATE_ISSUED) {
                return [
                    'idempotent' => true,
                    'message' => 'Policy is already ISSUED. Submission skipped to prevent duplicate issuance.',
                    'policy_request' => $lockedRequest,
                ];
            }

            // 2. Reconciliation check before blind re-issue:
            // If previous attempt timed out (NEEDS_RECONCILIATION or PENDING_CONFIRMATION)
            if (
                in_array($lockedRequest->status, [
                    PolicyStateMachine::STATE_NEEDS_RECONCILIATION,
                    PolicyStateMachine::STATE_PENDING_CONFIRMATION,
                ], true) && $lockedRequest->provider_reference
            ) {
                $providerRecord = $this->mockInsurer->lookupStatus($lockedRequest->provider_reference);
                if ($providerRecord && ($providerRecord['status'] ?? null) === 'ISSUED') {
                    $lockedRequest->policy_number = $providerRecord['policy_number'];
                    $lockedRequest->save();

                    $this->stateMachine->transition(
                        $lockedRequest,
                        PolicyStateMachine::STATE_ISSUED,
                        'Reconciled via external insurer lookup before reissue attempt.',
                        ['provider_reference' => $lockedRequest->provider_reference]
                    );

                    return [
                        'reconciled' => true,
                        'message' => 'Resolved pending state through insurer reconciliation without re-issuing.',
                        'policy_request' => $lockedRequest->fresh(['issuanceAttempts', 'stateHistory']),
                    ];
                }
            }

            // 3. Ensure valid starting state for submission
            if ($lockedRequest->status === PolicyStateMachine::STATE_RECEIVED) {
                $this->stateMachine->transition(
                    $lockedRequest,
                    PolicyStateMachine::STATE_VALIDATED,
                    'Automatically validated request prerequisites before submission'
                );
            }

            // Transition to SUBMITTED
            if ($lockedRequest->status !== PolicyStateMachine::STATE_SUBMITTED) {
                $this->stateMachine->transition(
                    $lockedRequest,
                    PolicyStateMachine::STATE_SUBMITTED,
                    'Submitting to external insurer'
                );
            }

            // Increment attempt count
            $attemptNumber = $lockedRequest->attempt_count + 1;
            $lockedRequest->attempt_count = $attemptNumber;
            $lockedRequest->save();

            // 4. Call Mock Insurer
            $response = $this->mockInsurer->issuePolicy($lockedRequest, $scenario);

            $attemptStatus = 'FAILED';
            $providerReference = null;
            $policyNumber = null;
            $errorMessage = null;

            if ($response['success']) {
                $data = $response['data'];
                $providerReference = $data['provider_reference'] ?? null;
                $policyNumber = $data['policy_number'] ?? null;

                if (($data['status'] ?? null) === 'ISSUED') {
                    $attemptStatus = 'SUCCESS';
                    $lockedRequest->policy_number = $policyNumber;
                    $lockedRequest->provider_reference = $providerReference;
                    $lockedRequest->last_provider_response = json_encode($data);
                    $lockedRequest->failure_reason = null;
                    $lockedRequest->save();

                    $this->stateMachine->transition(
                        $lockedRequest,
                        PolicyStateMachine::STATE_ISSUED,
                        'Policy issued successfully by insurer on attempt #' . $attemptNumber
                    );
                } elseif (($data['status'] ?? null) === 'PENDING_CONFIRMATION') {
                    $attemptStatus = 'PENDING';
                    $lockedRequest->provider_reference = $providerReference;
                    $lockedRequest->last_provider_response = json_encode($data);
                    $lockedRequest->save();

                    $this->stateMachine->transition(
                        $lockedRequest,
                        PolicyStateMachine::STATE_PENDING_CONFIRMATION,
                        'Insurer accepted async issuance; awaiting callback.'
                    );
                }
            } else {
                $err = $response['error'] ?? [];
                $providerReference = $err['provider_reference'] ?? null;
                $errorMessage = $err['message'] ?? 'Insurer error';
                $attemptStatus = ($response['status_code'] === 504) ? 'TIMEOUT' : 'FAILED';

                $lockedRequest->provider_reference = $providerReference ?: $lockedRequest->provider_reference;
                $lockedRequest->last_provider_response = json_encode($err);
                $lockedRequest->failure_reason = $errorMessage;
                $lockedRequest->save();

                if (!empty($err['reconciliation_required']) || $response['status_code'] === 504) {
                    $this->stateMachine->transition(
                        $lockedRequest,
                        PolicyStateMachine::STATE_NEEDS_RECONCILIATION,
                        "Attempt #{$attemptNumber} timed out. Flagged for reconciliation to avoid duplicate issuance.",
                        ['provider_reference' => $providerReference]
                    );
                } else {
                    $this->stateMachine->transition(
                        $lockedRequest,
                        PolicyStateMachine::STATE_FAILED,
                        "Attempt #{$attemptNumber} failed: {$errorMessage}",
                        ['retriable' => $err['retriable'] ?? false]
                    );
                }
            }

            // Persist the attempt record
            IssuanceAttempt::create([
                'policy_request_id' => $lockedRequest->id,
                'attempt_number' => $attemptNumber,
                'status' => $attemptStatus,
                'scenario' => $scenario ?: 'SUCCESS',
                'request_payload' => [
                    'agent_id' => $lockedRequest->agent_id,
                    'client_request_id' => $lockedRequest->client_request_id,
                    'customer_name' => $lockedRequest->customer_name,
                    'premium' => $lockedRequest->premium,
                ],
                'response_payload' => $response,
                'provider_reference' => $providerReference,
                'policy_number' => $policyNumber,
                'error_message' => $errorMessage,
            ]);

            return [
                'idempotent' => false,
                'status' => $lockedRequest->status,
                'attempt_number' => $attemptNumber,
                'policy_request' => $lockedRequest->fresh(['issuanceAttempts', 'stateHistory']),
            ];
        });
    }

    /**
     * Process asynchronous callback / webhook from external provider.
     * Guaranteed idempotent by event_id database constraint and state guards.
     */
    public function processCallback(array $payload): array
    {
        $eventId = $payload['event_id'] ?? null;
        if (empty($eventId)) {
            throw new RuntimeException("Missing 'event_id' in provider callback payload.");
        }

        // Check if event has already been recorded
        $existingEvent = ProviderEvent::where('event_id', $eventId)->first();
        if ($existingEvent) {
            return [
                'idempotent' => true,
                'message' => "Event '{$eventId}' was already processed. Duplicate callback ignored.",
                'event' => $existingEvent,
            ];
        }

        return DB::transaction(function () use ($payload, $eventId) {
            // Find corresponding policy request by provider_reference, uuid, or policy_number
            $query = PolicyRequest::query();
            if (!empty($payload['provider_reference'])) {
                $query->where('provider_reference', $payload['provider_reference']);
            } elseif (!empty($payload['policy_request_uuid'])) {
                $query->where('uuid', $payload['policy_request_uuid']);
            } elseif (!empty($payload['client_request_id']) && !empty($payload['agent_id'])) {
                $query->where('agent_id', $payload['agent_id'])
                    ->where('client_request_id', $payload['client_request_id']);
            }

            /** @var PolicyRequest|null $policyRequest */
            $policyRequest = $query->lockForUpdate()->first();
            if (!$policyRequest) {
                throw new RuntimeException("No matching policy request found for provider callback.");
            }

            // Create provider event record (enforces unique event_id)
            $eventRecord = ProviderEvent::create([
                'event_id' => $eventId,
                'policy_request_id' => $policyRequest->id,
                'event_type' => $payload['event_type'] ?? 'POLICY_ISSUED',
                'provider_reference' => $payload['provider_reference'] ?? $policyRequest->provider_reference,
                'policy_number' => $payload['policy_number'] ?? null,
                'payload' => $payload,
                'processed' => false,
            ]);

            // Guard: If policy is already ISSUED, never overwrite or regress
            if ($policyRequest->status === PolicyStateMachine::STATE_ISSUED) {
                $eventRecord->processed = true;
                $eventRecord->processed_at = now();
                $eventRecord->save();

                return [
                    'idempotent' => true,
                    'message' => 'Policy is already ISSUED. Event recorded but state was preserved.',
                    'event' => $eventRecord,
                ];
            }

            $eventType = strtoupper($payload['event_type'] ?? 'POLICY_ISSUED');
            if ($eventType === 'POLICY_ISSUED') {
                $policyNumber = $payload['policy_number'] ?? ('POL-' . date('Y') . '-' . strtoupper(\Illuminate\Support\Str::random(8)));
                $policyRequest->policy_number = $policyNumber;
                $policyRequest->last_provider_response = json_encode($payload);
                $policyRequest->save();

                $this->stateMachine->transition(
                    $policyRequest,
                    PolicyStateMachine::STATE_ISSUED,
                    "Policy confirmed ISSUED via provider callback event '{$eventId}'",
                    ['event_id' => $eventId]
                );
            } elseif ($eventType === 'POLICY_FAILED') {
                $policyRequest->failure_reason = $payload['reason'] ?? 'Provider callback indicated issuance failure';
                $policyRequest->last_provider_response = json_encode($payload);
                $policyRequest->save();

                $this->stateMachine->transition(
                    $policyRequest,
                    PolicyStateMachine::STATE_FAILED,
                    "Provider callback indicated failure: {$policyRequest->failure_reason}",
                    ['event_id' => $eventId]
                );
            }

            $eventRecord->processed = true;
            $eventRecord->processed_at = now();
            $eventRecord->save();

            return [
                'idempotent' => false,
                'message' => 'Callback processed successfully.',
                'event' => $eventRecord,
                'policy_request' => $policyRequest->fresh(),
            ];
        });
    }

    /**
     * Explicit reconciliation against mock provider.
     */
    public function reconcile(PolicyRequest $policyRequest): array
    {
        return DB::transaction(function () use ($policyRequest) {
            /** @var PolicyRequest $lockedRequest */
            $lockedRequest = PolicyRequest::query()
                ->where('id', $policyRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status === PolicyStateMachine::STATE_ISSUED) {
                return [
                    'reconciled' => true,
                    'status' => 'ALREADY_ISSUED',
                    'message' => 'Policy was already confirmed ISSUED.',
                    'policy_request' => $lockedRequest,
                ];
            }

            $lookup = null;
            if ($lockedRequest->provider_reference) {
                $lookup = $this->mockInsurer->lookupStatus($lockedRequest->provider_reference);
            }
            if (!$lookup) {
                $lookup = $this->mockInsurer->lookupByAgentRequest($lockedRequest->agent_id, $lockedRequest->client_request_id);
            }

            if ($lookup && ($lookup['status'] ?? null) === 'ISSUED') {
                $lockedRequest->policy_number = $lookup['policy_number'];
                if (empty($lockedRequest->provider_reference) && !empty($lookup['provider_reference'])) {
                    $lockedRequest->provider_reference = $lookup['provider_reference'];
                }
                $lockedRequest->save();

                $this->stateMachine->transition(
                    $lockedRequest,
                    PolicyStateMachine::STATE_ISSUED,
                    'Reconciled successfully via external provider lookup.',
                    ['provider_lookup' => $lookup]
                );

                return [
                    'reconciled' => true,
                    'status' => 'RECONCILED_TO_ISSUED',
                    'message' => 'Provider confirmed policy was issued; state updated to ISSUED.',
                    'policy_request' => $lockedRequest->fresh(['issuanceAttempts', 'stateHistory']),
                ];
            }

            return [
                'reconciled' => false,
                'status' => $lockedRequest->status,
                'message' => 'Provider does not have an ISSUED record for this request.',
                'policy_request' => $lockedRequest,
            ];
        });
    }
}
