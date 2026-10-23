<?php

namespace App\Http\Requests;

use App\Services\PremiumCalculator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $supportedPlans = implode(',', array_keys(PremiumCalculator::PLAN_RATES));
        $supportedDests = implode(',', array_keys(PremiumCalculator::DESTINATION_MULTIPLIERS));
        $supportedCoverages = implode(',', array_keys(PremiumCalculator::COVERAGE_MULTIPLIERS));

        return [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'destination' => ['required', 'string', "in:{$supportedDests}"],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', "in:{$supportedPlans}"],
            'sum_insured' => ['required', 'integer', "in:{$supportedCoverages}"],
        ];
    }

    public function messages(): array
    {
        return [
            'trip_end.after_or_equal' => 'The trip_end date must be greater than or equal to the trip_start date.',
            'plan_code.in' => 'Unsupported plan_code. Valid options are: BASIC, PLUS, PREMIUM.',
            'destination.in' => 'Unsupported destination. Valid options are: DOMESTIC, INTERNATIONAL.',
            'sum_insured.in' => 'Unsupported sum_insured. Valid options are: 100000, 300000, 500000.',
            'date_of_birth.before' => 'date_of_birth must be a date in the past.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Validation failed for policy intake request.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
