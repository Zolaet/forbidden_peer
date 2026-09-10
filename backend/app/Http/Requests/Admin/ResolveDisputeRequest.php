<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ResolveDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'release' pays the buyer, 'refund' returns the escrow to the
            // seller. There is no third outcome: the USDT is in escrow and it
            // has to end up with one of the two parties.
            'outcome' => ['required', 'string', 'in:release,refund'],
            'note' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.in' => 'Choose either "release" (pay the buyer) or "refund" (return the escrow to the seller).',
            'note.required' => 'Record the reasoning — this is the audit trail for moving someone\'s money.',
        ];
    }
}
