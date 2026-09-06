<?php

namespace App\Http\Requests\PaymentMethod;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:bank_transfer,mobile_money,paypal,other'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:255'],
            'bank_or_provider_name' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}