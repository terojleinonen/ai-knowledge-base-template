<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'extensions:'.implode(',', config('knowledge.uploads.mimes')),
                'max:'.config('knowledge.uploads.max_kilobytes'),
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
