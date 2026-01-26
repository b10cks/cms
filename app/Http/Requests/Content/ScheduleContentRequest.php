<?php

namespace App\Http\Requests\Content;


class ScheduleContentRequest extends UpsertContentRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'message' => 'sometimes|string|max:255',
            'scheduled_at' => 'required|date|after_or_equal:now',
        ];
    }
}
