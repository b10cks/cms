<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Ai\OpenRouterKeyManager;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProvisionSpaceAiKeyCommand extends Command
{
    protected $signature = 'ai:provision-key
        {space : The space ID or slug}
        {--limit= : Spending limit in USD}
        {--limit-reset=monthly : Reset interval: daily, weekly, or monthly}
        {--expires-at= : Expiration date (Y-m-d format)}';

    protected $description = 'Provision an OpenRouter API key for a space';

    public function handle(OpenRouterKeyManager $manager): int
    {
        $spaceIdentifier = $this->argument('space');
        $space = Space::where('id', $spaceIdentifier)
            ->orWhere('slug', $spaceIdentifier)
            ->first();

        if (! $space) {
            $this->error("Space '{$spaceIdentifier}' not found.");

            return self::FAILURE;
        }

        $limit = $this->option('limit') ? (float) $this->option('limit') : null;
        $limitReset = $limit ? $this->option('limit-reset') : null;
        $expiresAt = $this->option('expires-at')
            ? Carbon::parse($this->option('expires-at'))
            : null;

        try {
            $key = $manager->provisionKey($space, $limit, $limitReset, $expiresAt);

            $this->info("Key provisioned successfully for space '{$space->name}'.");
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $key->id],
                    ['Name', $key->name],
                    ['Driver', $key->driver],
                    ['Limit', $limit ? "\${$limit}" : 'None'],
                    ['Reset', $limitReset ?? 'N/A'],
                    ['Expires', $expiresAt?->toDateString() ?? 'Never'],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to provision key: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
