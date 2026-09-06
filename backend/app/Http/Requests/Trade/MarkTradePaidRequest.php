<?php

namespace App\Http\Requests\Trade;

use Illuminate\Foundation\Http\FormRequest;

class MarkTradePaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proof_image' => ['required', 'image', 'mimes:jpeg,png,jpg', 'max:5120'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}