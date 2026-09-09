<?php

namespace App\Http\Requests\Offer;

use Illuminate\Foundation\Http\FormRequest;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:buy,sell'],
            // Forbidden is an ETB ⇄ USDT-only marketplace.
            'fiat_currency' => ['required', 'string', 'in:ETB'],
            'price' => ['required', 'numeric', 'gt:0'],
            'total_amount' => ['required', 'numeric', 'gt:0'],
            'min_limit' => ['required', 'numeric', 'gt:0'],
            'max_limit' => ['required', 'numeric', 'gte:min_limit'],
            'payment_window_minutes' => ['required', 'integer', 'between:15,60'],
        ];
    }
}