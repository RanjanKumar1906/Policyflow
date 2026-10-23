<?php

namespace App\Services;

use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PolicyStateMachine
{
    public const STATE_RECEIVED = 'RECEIVED';
    public const STATE_VALIDATED = 'VALIDATED';
    public const STATE_SUBMITTED = 'SUBMITTED';
    public const STATE_PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    public const STATE_ISSUED = 'ISSUED';
    public const STATE_FAILED = 'FAILED';
    public const STATE_NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    /**
     * Allowed state transitions map
     */
    protected const ALLOWED_TRANSITIONS = [
        self::STATE_RECEIVED => [
            self::STATE_VALIDATED,
            self::STATE_FAILED,
        ],
        self::STATE_VALIDATED => [
            self::STATE_SUBMITTED,
            self::STATE_FAILED,
        ],
        self::STATE_SUBMITTED => [
            self::STATE_PENDING_CONFIRMATION,
            self::STATE_ISSUED,
            self::STATE_NEEDS_RECONCILIATION,
            self::STATE_FAILED,
        ],
        self::STATE_PENDING_CONFIRMATION => [
            self::STATE_ISSUED,
            self::STATE_NEEDS_RECONCILIATION,
            self::STATE_FAILED,
        ],
        self::STATE_NEEDS_RECONCILIATION => [
            self::STATE_SUBMITTED, // For retry after checking reconciliation
            self::STATE_ISSUED,    // Directly reconciled with external provider
            self::STATE_FAILED,
        ],
        // Terminal states
        self::STATE_ISSUED => [], // Cannot transition anywhere once ISSUED
        self::STATE_FAILED => [
            self::STATE_SUBMITTED, // Allowed only when retrying temporary failure
        ],
    ];

    /**
     * Verify whether a transition from $from to $to is valid
     */
    public function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    /**
     * Transition a policy request to a new state and record in state history audit log.
     */
    public function transition(
        PolicyRequest $policyRequest,
        string $newStatus,
        ?string $reason = null,
        ?array $metadata = null
    ): PolicyRequest {
        $currentStatus = $policyRequest->status;

        // Never overwrite an already ISSUED policy
        if ($currentStatus === self::STATE_ISSUED) {
            if ($newStatus === self::STATE_ISSUED) {
                // Idempotent re-affirmation, no error, simply return
                return $policyRequest;
            }
            throw new InvalidArgumentException("Policy is already ISSUED. Regression from ISSUED to '{$newStatus}' is strictly prohibited.");
        }

        if (!$this->canTransition($currentStatus, $newStatus)) {
            throw new InvalidArgumentException("Invalid state transition from '{$currentStatus}' to '{$newStatus}'.");
        }

        return DB::transaction(function () use ($policyRequest, $currentStatus, $newStatus, $reason, $metadata) {
            $policyRequest->status = $newStatus;
            $policyRequest->save();

            PolicyStateHistory::create([
                'policy_request_id' => $policyRequest->id,
                'from_status' => $currentStatus,
                'to_status' => $newStatus,
                'reason' => $reason,
                'metadata' => $metadata,
            ]);

            return $policyRequest;
        });
    }
}
