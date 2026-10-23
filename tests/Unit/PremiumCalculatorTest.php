<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    protected PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    /**
     * Test the exact sample from the prompt:
     * client_request_id: AGT-2026-0001
     * agent_id: AGENT-17
     * customer_name: Sample Customer
     * date_of_birth: 1998-04-10
     * destination: INTERNATIONAL (1.80)
     * trip_start: 2026-11-10
     * trip_end: 2026-11-14 (5 days inclusive)
     * plan_code: PLUS (20)
     * sum_insured: 300000 (1.25)
     * Age on 2026-11-10: 28 years old (multiplier 1.00)
     * Expected premium: 20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00
     */
    public function test_specification_example_calculation(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'PLUS',
            sumInsured: 300000,
            destination: 'INTERNATIONAL',
            dob: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14'
        );

        $this->assertEquals('225.00', $result['premium']);
        $this->assertEquals(5, $result['breakdown']['travel_days']);
        $this->assertEquals('1.00', $result['breakdown']['age_multiplier']);
        $this->assertEquals('1.80', $result['breakdown']['destination_multiplier']);
        $this->assertEquals('1.25', $result['breakdown']['coverage_multiplier']);
    }

    /**
     * Test age brackets:
     * 0-17: 1.10
     * 18-60: 1.00
     * 61-70: 1.50
     * 71+: 2.00
     */
    public function test_age_multipliers(): void
    {
        // 10 years old -> 1.10
        $child = $this->calculator->calculate(
            planCode: 'BASIC', // 12
            sumInsured: 100000, // 1.00
            destination: 'DOMESTIC', // 1.00
            dob: '2016-01-01',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01' // 1 day
        );
        // 12 * 1 * 1.10 * 1.00 * 1.00 = 13.20
        $this->assertEquals('13.20', $child['premium']);

        // 65 years old -> 1.50
        $senior = $this->calculator->calculate(
            planCode: 'BASIC', // 12
            sumInsured: 100000, // 1.00
            destination: 'DOMESTIC', // 1.00
            dob: '1961-01-01',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01' // 1 day
        );
        // 12 * 1 * 1.50 * 1.00 * 1.00 = 18.00
        $this->assertEquals('18.00', $senior['premium']);

        // 75 years old -> 2.00
        $elderly = $this->calculator->calculate(
            planCode: 'PREMIUM', // 30
            sumInsured: 500000, // 1.50
            destination: 'INTERNATIONAL', // 1.80
            dob: '1950-01-01',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-02' // 2 days
        );
        // 30 * 2 * 2.00 * 1.80 * 1.50 = 324.00
        $this->assertEquals('324.00', $elderly['premium']);
    }

    /**
     * Test single-day trip inclusive calculation (trip_end == trip_start -> 1 day)
     */
    public function test_single_day_trip(): void
    {
        $res = $this->calculator->calculate(
            planCode: 'BASIC',
            sumInsured: 100000,
            destination: 'DOMESTIC',
            dob: '2000-01-01',
            tripStart: '2026-07-01',
            tripEnd: '2026-07-01'
        );

        $this->assertEquals(1, $res['breakdown']['travel_days']);
        $this->assertEquals('12.00', $res['premium']);
    }

    /**
     * Test unsupported plan rejection
     */
    public function test_unsupported_plan_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate(
            planCode: 'GOLD_ELITE',
            sumInsured: 100000,
            destination: 'DOMESTIC',
            dob: '2000-01-01',
            tripStart: '2026-07-01',
            tripEnd: '2026-07-05'
        );
    }

    /**
     * Test unsupported sum_insured rejection
     */
    public function test_unsupported_sum_insured_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate(
            planCode: 'BASIC',
            sumInsured: 250000, // Not in [100000, 300000, 500000]
            destination: 'DOMESTIC',
            dob: '2000-01-01',
            tripStart: '2026-07-01',
            tripEnd: '2026-07-05'
        );
    }

    /**
     * Test trip_end before trip_start rejection
     */
    public function test_invalid_trip_dates_throw_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate(
            planCode: 'BASIC',
            sumInsured: 100000,
            destination: 'DOMESTIC',
            dob: '2000-01-01',
            tripStart: '2026-07-10',
            tripEnd: '2026-07-01'
        );
    }
}
