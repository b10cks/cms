<?php

namespace App\Http\Requests\Automation\Concerns;

use App\Models\Management\Space;
use App\Services\Automation\Enums\TriggerType;
use App\Services\Automation\TriggerCatalog;
use Cron\CronExpression;
use Illuminate\Validation\Validator;

trait ValidatesTriggerDefinition
{
    protected function validateTriggerDefinition(
        Validator $validator,
        string $typeValue,
        array $config,
    ): void {
        $type = TriggerType::tryFrom($typeValue);
        if (! $type) {
            return;
        }

        $this->validateConditions($validator, (array) ($config['conditions'] ?? []));

        if (isset($config['payload']) && ! is_array($config['payload'])) {
            $validator->errors()->add('trigger.config.payload', 'Trigger payload must be a JSON object.');
        }

        $this->validateTableConfiguration($validator, $type, $config);
        $this->validateManualConfiguration($validator, $type, $config);

        if ($type === TriggerType::TIME_BASED) {
            $schedule = trim((string) ($config['schedule'] ?? ''));
            if ($schedule === '') {
                $validator->errors()->add('trigger.config.schedule', 'A cron schedule is required for time-based automations.');
            } elseif (! $this->isValidCronExpression($schedule)) {
                $validator->errors()->add('trigger.config.schedule', 'Use a valid cron expression.');
            }

            $timezone = $config['timezone'] ?? null;
            if ($timezone !== null && ! in_array($timezone, timezone_identifiers_list(), true)) {
                $validator->errors()->add('trigger.config.timezone', 'Choose a valid timezone.');
            }
        }
    }

    protected function validateConditions(Validator $validator, array $conditions): void
    {
        $allowedOperators = ['eq', 'ne', 'contains', 'gt', 'gte', 'lt', 'lte', 'in', 'nin', 'exists', 'empty'];

        foreach ($conditions as $index => $condition) {
            if (! is_array($condition)) {
                $validator->errors()->add("trigger.config.conditions.$index", 'Each condition must be an object.');

                continue;
            }

            $path = trim((string) ($condition['path'] ?? ''));
            if ($path === '') {
                $validator->errors()->add("trigger.config.conditions.$index.path", 'Each condition needs a context path.');
            }

            $operator = (string) ($condition['operator'] ?? '');
            if (! in_array($operator, $allowedOperators, true)) {
                $validator->errors()->add("trigger.config.conditions.$index.operator", 'Choose a valid condition operator.');
            }
        }
    }

    protected function validateTableConfiguration(Validator $validator, TriggerType $type, array $config): void
    {
        if (! in_array($type, [TriggerType::ON_INSERT, TriggerType::ON_UPDATE, TriggerType::ON_DELETE], true)) {
            return;
        }

        /** @var TriggerCatalog $catalog */
        $catalog = app(TriggerCatalog::class);
        $table = $catalog->resolveTableFromConfig($config);

        if ($table === null) {
            $validator->errors()->add('trigger.config.table', 'Choose a CMS table for this trigger.');

            return;
        }

        if (! $catalog->supportsTable($table)) {
            $validator->errors()->add('trigger.config.table', 'Choose one of the supported CMS tables.');

            return;
        }

        if ($type !== TriggerType::ON_UPDATE && ! empty($config['watch_columns'])) {
            $validator->errors()->add('trigger.config.watch_columns', 'Watched columns can only be used with update triggers.');
        }

        $columns = $this->resolveColumnsForTriggerTable($table);
        $watchColumns = array_values(array_filter(
            (array) ($config['watch_columns'] ?? []),
            fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        foreach ($watchColumns as $index => $column) {
            if ($columns !== [] && ! in_array($column, $columns, true)) {
                $validator->errors()->add(
                    "trigger.config.watch_columns.$index",
                    sprintf('The column "%s" is not available on %s.', $column, $table),
                );
            }
        }
    }

    protected function validateManualConfiguration(Validator $validator, TriggerType $type, array $config): void
    {
        if ($type !== TriggerType::MANUAL) {
            if (! empty($config['block_ids'])) {
                $validator->errors()->add('trigger.config.block_ids', 'Block restrictions can only be used with manual triggers.');
            }

            return;
        }

        /** @var TriggerCatalog $catalog */
        $catalog = app(TriggerCatalog::class);
        $table = $catalog->resolveTableFromConfig($config);

        if ($table !== null && ! $catalog->supportsTable($table)) {
            $validator->errors()->add('trigger.config.table', 'Choose one of the supported CMS tables.');
        }

        $blockIds = (array) ($config['block_ids'] ?? []);
        if ($blockIds === []) {
            return;
        }

        if ($table !== 'contents') {
            $validator->errors()->add('trigger.config.block_ids', 'Block restrictions require the trigger to target contents.');
        }

        foreach ($blockIds as $index => $blockId) {
            if (! is_string($blockId) || trim($blockId) === '') {
                $validator->errors()->add("trigger.config.block_ids.$index", 'Each block restriction must be a block id.');
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function resolveColumnsForTriggerTable(string $table): array
    {
        /** @var Space|null $space */
        $space = $this->route('space');

        if (! $space instanceof Space) {
            return [];
        }

        /** @var TriggerCatalog $catalog */
        $catalog = app(TriggerCatalog::class);

        return $catalog->columnsForTable($table, $space);
    }

    protected function isValidCronExpression(string $expression): bool
    {
        try {
            return CronExpression::factory($expression) !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
