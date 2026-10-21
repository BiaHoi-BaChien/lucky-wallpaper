<?php

namespace App\Http\Requests;

use App\Services\KigakuPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WallpaperFilterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'direction' => ['nullable', Rule::in(array_keys(KigakuPolicy::GROUPS))],
        ];
    }

    public function messages(): array
    {
        return ['date_to.after_or_equal' => '終了日は開始日以降にしてください。'];
    }
}
