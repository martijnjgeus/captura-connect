<?php

namespace App\Http\Requests\Api;

use App\Services\Logging\IntegrationLogger;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class IncomingOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expectedKey = config('api.orders.incoming_api_key');
        $providedKey = $this->header('X-Api-Key');

        if (!is_string($expectedKey) || $expectedKey === '') {
            return false;
        }

        if (!is_string($providedKey) || $providedKey === '') {
            return false;
        }

        return hash_equals($expectedKey, $providedKey);
    }

    public function rules(): array
    {
        return [
            'id'          => ['required', 'string', 'max:100'],
            'locale'      => ['required', 'string', 'max:10'],
            'currency'    => ['required', 'string', 'size:3'],
            'created_at'  => ['required', 'date_format:Y-m-d H:i:s'],
            'updated_at'  => ['required', 'date_format:Y-m-d H:i:s'],
            'relation_id' => ['required', 'integer'],
            'company'     => ['nullable', 'string', 'max:255'],

            'line_items' => ['required', 'array', 'min:1'],

            'line_items.*.ean'          => ['required', 'string', 'digits_between:8,14'],
            'line_items.*.sku'          => ['required', 'string', 'max:100'],
            'line_items.*.name'         => ['required', 'string', 'max:255'],
            'line_items.*.price'        => ['required', 'numeric', 'min:0'],
            'line_items.*.total'        => ['required', 'numeric', 'min:0'],
            'line_items.*.discount'     => ['required', 'numeric', 'min:0'],
            'line_items.*.quantity'     => ['required', 'integer', 'min:1'],
            'line_items.*.tax_rate'     => ['required', 'numeric', 'min:0', 'max:100'],
            'line_items.*.size_code'    => ['required', 'string', 'max:50'],
            'line_items.*.size_name'    => ['required', 'string', 'max:100'],
            'line_items.*.color_code'   => ['required', 'string', 'max:50'],
            'line_items.*.color_name'   => ['required', 'string', 'max:100'],
            'line_items.*.product_id'   => ['required', 'string', 'max:100'],
            'line_items.*.tax_amount'   => ['required', 'numeric', 'min:0'],
            'line_items.*.product_code' => ['required', 'string', 'max:100'],

            'billing_address'                       => ['required', 'array'],
            'billing_address.id'                    => ['nullable', 'integer'],
            'billing_address.city'                  => ['required', 'string', 'max:100'],
            'billing_address.street'                => ['required', 'string', 'max:255'],
            'billing_address.company'               => ['nullable', 'string', 'max:255'],
            'billing_address.country'               => ['required', 'string', 'max:100'],
            'billing_address.last_name'             => ['nullable', 'string', 'max:100'],
            'billing_address.first_name'            => ['nullable', 'string', 'max:100'],
            'billing_address.postal_code'           => ['required', 'string', 'max:20'],
            'billing_address.house_number'          => ['nullable', 'string', 'max:20'],
            'billing_address.house_number_addition' => ['nullable', 'string', 'max:20'],

            'shipping_address'                       => ['required', 'array'],
            'shipping_address.id'                    => ['nullable', 'integer'],
            'shipping_address.city'                  => ['required', 'string', 'max:100'],
            'shipping_address.street'                => ['required', 'string', 'max:255'],
            'shipping_address.company'               => ['nullable', 'string', 'max:255'],
            'shipping_address.country'               => ['required', 'string', 'max:100'],
            'shipping_address.last_name'             => ['nullable', 'string', 'max:100'],
            'shipping_address.first_name'            => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code'           => ['required', 'string', 'max:20'],
            'shipping_address.house_number'          => ['nullable', 'string', 'max:20'],
            'shipping_address.house_number_addition' => ['nullable', 'string', 'max:20'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Unauthorized',
            ], 401)
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        app(IntegrationLogger::class)->webhookValidationFailed(
            source: 'incoming_order',
            receivedBody: $this->all(),
            errors: $validator->errors()->toArray(),
        );

        throw new HttpResponseException(response()->json([
            'message' => 'Validation failed.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
