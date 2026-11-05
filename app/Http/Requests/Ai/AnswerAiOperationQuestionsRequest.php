<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Each answer names its outputs: the whole group at the `fingerprint` the
 * editor saw, or the `member_ids` of single outputs.
 */
class AnswerAiOperationQuestionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:50'],
            'answers.*.question_id' => ['required', 'string', 'size:32'],
            'answers.*.answer' => ['required', 'string', 'max:2000'],
            'answers.*.fingerprint' => ['required_without:answers.*.member_ids', 'prohibits:answers.*.member_ids', 'string', 'size:64'],
            'answers.*.member_ids' => ['required_without:answers.*.fingerprint', 'array', 'min:1', 'max:200'],
            'answers.*.member_ids.*' => ['string', 'size:32'],
        ];
    }
}
