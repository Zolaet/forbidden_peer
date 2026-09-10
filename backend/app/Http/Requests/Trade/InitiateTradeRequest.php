<?php

namespace App\Http\Requests\Trade;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiateTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'offer_id' => ['required', 'exists:p2p_offers,id'],

            // Passed through as a string, never a float: `numeric` accepts
            // floats, so a client sending 0.1 + 0.2-shaped values would land
            // here already imprecise. The value is re-parsed through Money in
            // the controller, which is what actually guarantees the ledger.
            'crypto_amount' => ['required', 'numeric', 'gt:0'],

            // Must belong to the caller. Without this, a user could open an
            // order naming *someone else's* saved bank account, and the trade
            // would then hand the counterparty details the caller has no claim
            // to — and, before the presenter was tightened, the row itself.
            'payment_method_id' => [
                'required',
                Rule::exists('payment_methods', 'id')->where(
                    fn ($query) => $query->where('user_id', $this->user()?->id)
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method_id.exists' => 'Choose one of your own saved payment methods.',
        ];
    }
}
