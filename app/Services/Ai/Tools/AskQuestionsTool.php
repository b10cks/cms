<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\Questionnaire;
use LogicException;

/**
 * Pauses the run for structured input. The run service intercepts the call;
 * this class only carries its definition.
 */
final class AskQuestionsTool implements AiToolInterface
{
    public const NAME = 'ask_questions';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Asks the user one to '.Questionnaire::MAX_QUESTIONS.' questions when the task cannot be done well without their input. Offer options where possible; the user may still answer in their own words (`own_answer`). Use sparingly.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'required' => ['type' => 'boolean'],
                            'options' => [
                                'type' => 'array',
                                'description' => 'Choices; omit for a free-text answer.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'id' => ['type' => 'string'],
                                        'label' => ['type' => 'string'],
                                        'description' => ['type' => 'string'],
                                    ],
                                    'required' => ['id', 'label'],
                                ],
                            ],
                        ],
                        'required' => ['id', 'title'],
                    ],
                ],
            ],
            'required' => ['questions'],
        ];
    }

    public function execute(array $input): mixed
    {
        throw new LogicException('ask_questions is handled by the run service.');
    }
}
