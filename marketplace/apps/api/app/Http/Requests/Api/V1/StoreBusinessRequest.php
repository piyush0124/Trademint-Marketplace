<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BusinessType;
use App\Enums\TradeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $businessId = $this->user()->business?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('businesses', 'slug')->ignore($businessId)],
            'about' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],

            'business_type' => ['nullable', Rule::enum(BusinessType::class)],
            'years_in_business' => ['nullable', 'integer', 'min:0', 'max:200'],
            'trade_type' => ['nullable', Rule::enum(TradeType::class)],
            'category_slug' => ['nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'is_available' => ['nullable', 'boolean'],

            // business_hours: { "monday": {"open": "09:00", "close": "18:00", "closed": false}, ... }
            'business_hours' => ['nullable', 'array'],
            'business_hours.*' => ['array'],
            'business_hours.*.open' => ['nullable', 'string', 'max:10'],
            'business_hours.*.close' => ['nullable', 'string', 'max:10'],
            'business_hours.*.closed' => ['nullable', 'boolean'],

            // GST format is validated for shape only — actual government
            // verification is a separate, explicit admin action (see
            // BusinessVerificationController). Submitting a number here
            // never marks it verified.
            'gst_number' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/'],

            'location' => ['nullable', 'array'],
            'location.city' => ['required_with:location', 'string', 'max:100'],
            'location.state' => ['nullable', 'string', 'max:100'],
            'location.country' => ['nullable', 'string', 'size:2'],
        ];
    }

    public function messages(): array
    {
        return [
            'gst_number.regex' => 'GST number format is invalid.',
        ];
    }
}
