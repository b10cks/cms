<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\InternalRequestDispatcher;
use App\Mcp\Support\OperationRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class MgmtCallTool extends Tool
{
    protected string $name = 'b10cks_mgmt_call';

    protected string $description = 'Execute a b10cks Management API operation. Before creating or updating blocks, call b10cks_content_model_guide to understand best practices for block types, field types, tag hierarchy, and editor layout.';

    public function __construct(private readonly InternalRequestDispatcher $dispatcher) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $stringArguments = [
            'spaceId' => null,
            'teamId' => null,
            'userId' => null,
            'id' => 'Generic resource ID.',
            'folderId' => null,
            'tagId' => null,
            'contentId' => null,
            'blockId' => null,
            'assetId' => null,
            'redirectId' => null,
            'tokenId' => null,
            'dataSourceId' => null,
            'entryId' => null,
            'versionId' => 'Version ID (string).',
            'automationId' => null,
            'actionId' => null,
            'executionId' => null,
            'releaseId' => null,
            'commentId' => null,
            'templateId' => null,
            'configId' => null,
            'backupId' => null,
            'migrationId' => null,
            'inviteId' => null,
            'noteId' => null,
            'iconId' => null,
            'collectionId' => null,
            'shareId' => null,
            'packageId' => null,
            'notificationId' => null,
            'periodId' => 'Subscription period ID.',
            'roleId' => null,
            'blueprintId' => null,
            'provider' => 'Social login provider name.',
            'token' => 'Public share token.',
            'accessToken' => 'Access token from shares.unlock, for password-protected shares.',
        ];

        $properties = [
            'operation' => $schema->string()
                ->description('Operation name, for example contents.list or blocks.create. Call b10cks_mgmt_operations for the full list.')
                ->required(),
        ];

        foreach ($stringArguments as $argument => $description) {
            $type = $schema->string();
            $properties[$argument] = $description === null ? $type : $type->description($description);
        }

        $properties['params'] = $schema->object()
            ->description('Query parameters for list/search operations (e.g. page, per_page, filters).');
        $properties['payload'] = $schema->object()
            ->description('JSON request body for create/update/action operations.');

        return $properties;
    }

    public function handle(Request $request, HttpRequest $httpRequest): Response
    {
        $arguments = $request->all();
        $operationName = (string) $request->get('operation');
        $operation = OperationRegistry::find($operationName);

        if ($operation === null) {
            // levenshtein() rejects arguments longer than 255 characters
            $needle = substr($operationName, 0, 255);
            $suggestions = collect(OperationRegistry::names())
                ->sortBy(fn (string $name): int => levenshtein($needle, $name))
                ->take(3)
                ->implode(', ');

            return Response::error("Unknown operation: {$operationName}. Did you mean: {$suggestions}? Call b10cks_mgmt_operations for the full list.");
        }

        $bearerToken = $httpRequest->bearerToken() ?: config('services.b10cks_mcp.token');

        if (isset($operation['authArg'])) {
            $override = $arguments[$operation['authArg']] ?? null;

            if (is_string($override) && $override !== '') {
                $bearerToken = $override;
            }
        }

        try {
            $uri = OperationRegistry::resolveUri($operation['uri'], $arguments);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $accepts = $operation['accepts'] ?? [];
        $query = in_array('params', $accepts, true) ? ($this->sanitize($arguments['params'] ?? null) ?? []) : [];
        $payload = in_array('payload', $accepts, true) ? $this->sanitize($arguments['payload'] ?? null) : null;

        if ($operation['method'] === 'GET') {
            $result = $this->dispatcher->dispatch('GET', $uri, $query, null, $bearerToken);
        } else {
            $result = $this->dispatcher->dispatch($operation['method'], $uri, $query, $payload ?? [], $bearerToken);
        }

        if ($result['status'] >= 400) {
            return Response::error($this->formatError($result['status'], $result['body']));
        }

        return Response::text($this->formatBody($result['body']));
    }

    /**
     * Strips prototype-polluting keys from untrusted objects, mirroring the TS server.
     *
     * @return array<string, mixed>|null
     */
    private function sanitize(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        unset($input['__proto__'], $input['prototype'], $input['constructor']);

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->sanitize($value);
            }
        }

        return $input;
    }

    /**
     * Surfaces only safe, structured fields from an error response — never the
     * raw body, which can echo request context or internal detail.
     */
    private function formatError(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $details = ['statusCode' => $status];

        if (is_array($decoded)) {
            foreach (['message', 'error'] as $key) {
                if (is_string($decoded[$key] ?? null)) {
                    $details[$key] = $decoded[$key];
                }
            }

            if (is_array($decoded['errors'] ?? null)) {
                $details['errors'] = $decoded['errors'];
            }
        }

        $message = $details['message'] ?? $details['error'] ?? "HTTP {$status}";

        return "Management API request failed: {$message}\n".json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function formatBody(string $body): string
    {
        if ($body === '') {
            return 'null';
        }

        $decoded = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $body;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
