<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskQuestionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'question' => ['required', 'string', 'min:2', 'max:2000'],
            'conversation_id' => ['sometimes', 'nullable', 'integer', Rule::exists('conversations', 'id')->where('user_id', $userId)],
            'document_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'document_ids.*' => ['integer', Rule::exists('documents', 'id')->where('user_id', $userId)],
        ];
    }
}
