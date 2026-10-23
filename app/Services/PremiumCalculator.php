<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculator
{
    public const PLAN_RATES = [
        'BASIC' => '12.00',
        'PLUS' => '20.00',
        'PREMIUM' => '30.00',
    ];

    public const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    public const COVERAGE_MULTIPLIERS = [
        100000 => '1.00',
        300000 => '1.25',
        500000 => '1.50',
    ];

    /**
     * Calculate age at trip start date.
     */
    public function calculateAgeAtDate(string|Carbon $dob, string|Carbon $tripStart): int
    {
        $dobDate = $dob instanceof Carbon ? $dob : Carbon::parse($dob);
        $startDate = $tripStart instanceof Carbon ? $tripStart : Carbon::parse($tripStart);

        if ($startDate->lt($dobDate)) {
            throw new InvalidArgumentException("Date of birth cannot be in the future relative to trip start date.");
        }

        // Native DateInterval year diff returns clean int
        return (int) $dobDate->diff($startDate)->y;
    }

    /**
     * Get age multiplier based on age at trip start.
     */
    public function getAgeMultiplier(int $age): string
    {
        if ($age < 0) {
            throw new InvalidArgumentException("Date of birth cannot be in the future relative to trip start date.");
        }

        if ($age <= 17) {
            return '1.10';
        }
        if ($age <= 60) {
            return '1.00';
        }
        if ($age <= 70) {
            return '1.50';
        }
        return '2.00';
    }

    /**
     * Calculate inclusive travel days.
     */
    public function calculateTravelDays(string|Carbon $tripStart, string|Carbon $tripEnd): int
    {
        $start = ($tripStart instanceof Carbon ? $tripStart->copy() : Carbon::parse($tripStart))->startOfDay();
        $end = ($tripEnd instanceof Carbon ? $tripEnd->copy() : Carbon::parse($tripEnd))->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException("trip_end cannot be before trip_start.");
        }

        $days = $start->diffInDays($end) + 1;
        return max(1, (int) $days);
    }

    /**
     * Decimal-safe calculation using bcmath or string arithmetic.
     *
     * Formula:
     * premium = base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier
     */
    public function calculate(
        string $planCode,
        int|string $sumInsured,
        string $destination,
        string|Carbon $dob,
        string|Carbon $tripStart,
        string|Carbon $tripEnd
    ): array {
        $plan = strtoupper(trim($planCode));
        if (!array_key_exists($plan, self::PLAN_RATES)) {
            throw new InvalidArgumentException("Unsupported plan_code: '{$planCode}'. Supported: " . implode(', ', array_keys(self::PLAN_RATES)));
        }

        $dest = strtoupper(trim($destination));
        if (!array_key_exists($dest, self::DESTINATION_MULTIPLIERS)) {
            throw new InvalidArgumentException("Unsupported destination: '{$destination}'. Supported: " . implode(', ', array_keys(self::DESTINATION_MULTIPLIERS)));
        }

        $sum = (int) $sumInsured;
        if (!array_key_exists($sum, self::COVERAGE_MULTIPLIERS)) {
            throw new InvalidArgumentException("Unsupported sum_insured: '{$sumInsured}'. Supported: " . implode(', ', array_keys(self::COVERAGE_MULTIPLIERS)));
        }

        $days = $this->calculateTravelDays($tripStart, $tripEnd);
        $age = $this->calculateAgeAtDate($dob, $tripStart);
        $ageMultiplier = $this->getAgeMultiplier($age);
        $baseDailyRate = self::PLAN_RATES[$plan];
        $destMultiplier = self::DESTINATION_MULTIPLIERS[$dest];
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sum];

        // Decimal safe bcmath computation with precision 6, rounded to 2 decimals
        if (function_exists('bcmul')) {
            $step1 = bcmul($baseDailyRate, (string) $days, 6);
            $step2 = bcmul($step1, $ageMultiplier, 6);
            $step3 = bcmul($step2, $destMultiplier, 6);
            $step4 = bcmul($step3, $coverageMultiplier, 6);
            $rounded = number_format((float) $step4, 2, '.', '');
        } else {
            $total = (float) $baseDailyRate * $days * (float) $ageMultiplier * (float) $destMultiplier * (float) $coverageMultiplier;
            $rounded = number_format(round($total, 2), 2, '.', '');
        }

        return [
            'premium' => $rounded,
            'breakdown' => [
                'plan_code' => $plan,
                'base_daily_rate' => $baseDailyRate,
                'travel_days' => $days,
                'age_at_trip_start' => $age,
                'age_multiplier' => $ageMultiplier,
                'destination' => $dest,
                'destination_multiplier' => $destMultiplier,
                'sum_insured' => $sum,
                'coverage_multiplier' => $coverageMultiplier,
                'formula' => "{$baseDailyRate} * {$days} * {$ageMultiplier} * {$destMultiplier} * {$coverageMultiplier}",
            ],
        ];
    }
}
