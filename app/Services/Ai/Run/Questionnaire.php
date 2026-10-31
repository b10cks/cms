<?php

namespace App\Services\Ai\Run;

use Illuminate\Validation\ValidationException;

/**
 * Validates questionnaires the model asks and the answers users give. A
 * question with options takes one option ID or the user's own text; one
 * without takes free text.
 */
final class Questionnaire
{
    public const MAX_QUESTIONS = 6;

    public const MAX_OPTIONS = 8;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{questions: list<array{id: string, title: string, description: string|null, required: bool, options: list<array{id: string, label: string, description: string|null}>}>}
     *
     * @throws ToolError for the model to correct
     */
    public static function fromToolArguments(array $arguments): array
    {
        $questions = $arguments['questions'] ?? null;

        if (! \is_array($questions) || $questions === [] || \count($questions) > self::MAX_QUESTIONS) {
            throw new ToolError('Ask between 1 and '.self::MAX_QUESTIONS.' questions.');
        }

        $ids = [];
        $normalized = [];

        foreach (array_values($questions) as $question) {
            $id = \is_array($question) ? (string) ($question['id'] ?? '') : '';
            $title = \is_array($question) ? trim((string) ($question['title'] ?? '')) : '';

            if ($id === '' || $title === '' || isset($ids[$id])) {
                throw new ToolError('Every question needs a unique id and a title.');
            }
            $ids[$id] = true;

            $options = [];
            $optionIds = [];
            foreach (array_values(\is_array($question['options'] ?? null) ? $question['options'] : []) as $option) {
                $optionId = \is_array($option) ? (string) ($option['id'] ?? '') : '';
                $label = \is_array($option) ? trim((string) ($option['label'] ?? '')) : '';

                if ($optionId === '' || $label === '' || isset($optionIds[$optionId])) {
                    throw new ToolError("Options of question {$id} need unique ids and labels.");
                }
                $optionIds[$optionId] = true;
                $options[] = ['id' => $optionId, 'label' => mb_substr($label, 0, 200), 'description' => self::text($option['description'] ?? null)];
            }

            if (\count($options) > self::MAX_OPTIONS) {
                throw new ToolError('Offer at most '.self::MAX_OPTIONS.' options per question.');
            }

            $normalized[] = [
                'id' => mb_substr($id, 0, 64),
                'title' => mb_substr($title, 0, 300),
                'description' => self::text($question['description'] ?? null),
                'required' => (bool) ($question['required'] ?? true),
                'options' => $options,
            ];
        }

        return ['questions' => $normalized];
    }

    /**
     * Validate answers against the stored questionnaire. Skipped optional
     * questions are null.
     *
     * @param  array{questions: list<array<string, mixed>>}  $questionnaire
     * @param  array<string, mixed>  $answers  question id => option id or text
     * @return array<string, string|null>
     *
     * @throws ValidationException
     */
    public static function validateAnswers(array $questionnaire, array $answers): array
    {
        $errors = [];
        $known = array_column($questionnaire['questions'], null, 'id');

        foreach (array_keys($answers) as $id) {
            if (! isset($known[$id])) {
                $errors["answers.{$id}"][] = 'Unknown question.';
            }
        }

        $result = [];
        foreach ($known as $id => $question) {
            $value = $answers[$id] ?? null;
            $value = \is_string($value) ? trim($value) : null;

            if ($value === null || $value === '') {
                if ($question['required']) {
                    $errors["answers.{$id}"][] = 'This question needs an answer.';
                }
                $result[$id] = null;

                continue;
            }

            $result[$id] = mb_substr($value, 0, 2000);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    /**
     * The tool result the model sees: each question with the chosen label or text.
     *
     * @param  array{questions: list<array<string, mixed>>}  $questionnaire
     * @param  array<string, string|null>  $answers
     */
    public static function toolResult(array $questionnaire, array $answers): string
    {
        $lines = [];

        foreach ($questionnaire['questions'] as $question) {
            $answer = $answers[$question['id']] ?? null;
            $option = array_find($question['options'], static fn (array $option) => $option['id'] === $answer);
            $own = $answer !== null && $question['options'] !== [] && $option === null;

            $lines[] = ['id' => $question['id'], 'question' => $question['title'], 'answer' => $option['label'] ?? $answer, 'skipped' => $answer === null]
                + ($own ? ['own_answer' => true] : []);
        }

        return json_encode(['answers' => $lines], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 500) : null;
    }
}
