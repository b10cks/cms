<?php

namespace Tests\Feature\Documentation;

use App\Services\Documentation\OpenApiGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiContentOperationsSpecTest extends TestCase
{
    private const OPERATIONS = '/mgmt/v1/spaces/{space}/ai/operations/{operation}';

    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spec = app(OpenApiGenerator::class)->generateForPrefix('mgmt/v1');
    }

    #[Test]
    public function generation_is_repeatable_and_every_reference_and_path_parameter_resolves(): void
    {
        $this->assertSame($this->spec, app(OpenApiGenerator::class)->generateForPrefix('mgmt/v1'));

        preg_match_all('~#/components/schemas/(\w+)~', (string) json_encode($this->spec, JSON_UNESCAPED_SLASHES), $references);
        $this->assertSame([], array_values(array_diff(array_unique($references[1]), array_keys($this->spec['components']['schemas']))));

        foreach ($this->spec['paths'] as $path => $methods) {
            if (! str_contains($path, '/ai/')) {
                continue;
            }

            preg_match_all('/\{(\w+)}/', $path, $placeholders);

            foreach ($methods as $method => $operation) {
                $declared = array_column(array_filter($operation['parameters'] ?? [], fn (array $parameter) => $parameter['in'] === 'path'), 'name');
                $this->assertEqualsCanonicalizing($placeholders[1], $declared, "{$method} {$path}");
            }
        }
    }

    #[Test]
    public function nested_review_coverage_and_spend_are_described_rather_than_opaque(): void
    {
        $schemas = $this->spec['components']['schemas'];

        $operation = $schemas['AiOperationResource']['properties'];
        $this->assertSame('#/components/schemas/AiOperationCounts', $operation['counts']['$ref']);
        $this->assertSame('#/components/schemas/AiOperationCoverage', $schemas['AiOperationCounts']['properties']['coverage']['allOf'][0]['$ref']);
        $this->assertSame('number', $schemas['AiOperationBudget']['properties']['max_usd']['type']);

        $item = $schemas['AiOperationItemResource']['properties'];
        $this->assertSame('#/components/schemas/AiOperationPartOutcome', $item['parts']['additionalProperties']['$ref']);
        $this->assertSame('integer', $item['attempts']['type']);

        $review = $schemas['AiChangesetItemReview']['properties'];
        $this->assertSame(['evidence'], array_keys($review));
        $this->assertSame(['changed', 'conflicts'], array_keys($schemas['AiOperationChanges']['properties']['summary']['properties']));

        $run = $schemas['AiRunResource']['properties'];
        $this->assertSame('#/components/schemas/AiProviderRetry', $run['provider_retry']['allOf'][0]['$ref']);
        $this->assertTrue($schemas['AiRunUsage']['properties']['cost_confirmed_at']['nullable']);
        $this->assertSame('integer', $schemas['AiProviderRetry']['properties']['unconfirmed_calls']['type']);

        $this->assertSame('#/components/schemas/AiAssetFieldReport', $schemas['AiAssetClassificationProgress']['properties']['field_report']['$ref']);
    }

    #[Test]
    public function requests_and_their_new_statuses_match_the_controllers(): void
    {
        $paths = $this->spec['paths'];
        $schema = fn (array $operation, string $status) => $operation['responses'][$status]['content']['application/json']['schema'];

        $accept = $paths['/mgmt/v1/spaces/{space}/ai/changesets/{changeset}/accept']['post'];
        $body = $accept['requestBody']['content']['application/json']['schema']['properties'];
        $this->assertSame('object', $body['values']['type']);
        $this->assertSame('#/components/schemas/ValidationError', $schema($accept, '422')['$ref']);

        $approve = $paths[self::OPERATIONS.'/approve']['post'];
        $this->assertStringContainsString('paid', $approve['summary']);
        $this->assertContains('plan_changed', $this->spec['components']['schemas']['AiOperationConflict']['properties']['reason']['enum']);
        $this->assertEqualsCanonicalizing([200, 409, 422, 429], array_keys($approve['responses']));

        $questions = $paths[self::OPERATIONS.'/questions']['get'];
        $this->assertContains('per_page', array_column($questions['parameters'], 'name'));
        $this->assertSame('#/components/schemas/AiOperationQuestionPage', $schema($questions, '200')['$ref']);
    }
}
