<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_address.regex' => 'Please enter a valid BSC (BEP-20) USDT address (0x + 40 hex characters).',
            'amount.gt' => 'Amount must be greater than zero.',
        ];
    }
}
