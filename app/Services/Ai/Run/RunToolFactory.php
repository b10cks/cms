<?php

namespace App\Services\Ai\Run;

use App\Enums\AiKnowledgeType;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\AiRun;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Knowledge\KnowledgeSearch;
use App\Services\Ai\Skills\ResolvedSkill;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Tools\AskQuestionsTool;
use App\Services\Ai\Tools\AuthorizedTool;
use App\Services\Ai\Tools\GetBlockListTool;
use App\Services\Ai\Tools\GetBlockSchemasTool;
use App\Services\Ai\Tools\GetEntryTool;
use App\Services\Ai\Tools\GetMentionedContentTool;
use App\Services\Ai\Tools\LookupGlossaryTool;
use App\Services\Ai\Tools\ReadKnowledgeTool;
use App\Services\Ai\Tools\ReadSkillFileTool;
use App\Services\Ai\Tools\ReadSkillTool;
use App\Services\Ai\Tools\SearchAssetsTool;
use App\Services\Ai\Tools\SearchContentTool;
use App\Services\Ai\Tools\SearchKnowledgeTool;
use App\Services\Auth\AuthorizationService;
use App\Services\Search\SearchService;

/**
 * Builds a run's tools from its frozen setup. Every tool acts as the run's
 * actor: reads are gated by the same space abilities the UI uses.
 */
class RunToolFactory
{
    public const ALL = [
        'get_block_list',
        'get_block_schemas',
        'search_assets',
        'get_mentioned_content',
        'get_entry',
        'search_content',
        'read_skill',
        'read_skill_file',
        'search_knowledge',
        'read_knowledge',
        'lookup_glossary',
        AskQuestionsTool::NAME,
    ];

    public function __construct(
        private readonly AuthorizationService $auth,
        private readonly SkillResolver $skills,
        private readonly KnowledgeResolver $knowledge,
        private readonly KnowledgeSearch $search,
        private readonly SearchService $contentSearch,
    ) {}

    /**
     * Tool names a run gets: the skill's list narrows the full set, never widens it.
     *
     * @param  list<string>|null  $skillTools
     * @return list<string>
     */
    public static function namesFor(?array $skillTools): array
    {
        return $skillTools === null ? self::ALL : array_values(array_intersect(self::ALL, $skillTools));
    }

    public function make(AiRun $run, Space $space, User $actor): ToolSet
    {
        return $this->build($run->setup, $run->surface, $space, $actor);
    }

    /**
     * Tools over what the actor sees now, for callers without a run (MCP).
     *
     * @param  list<string>  $names
     */
    public function forSpace(Space $space, User $actor, array $names): ToolSet
    {
        $catalog = array_map(fn (ResolvedSkill $skill) => $skill->freeze(), $this->skills->resolve($space));
        $knowledge = $this->knowledge->documents($space, AiKnowledgeType::Reference)
            ->mapWithKeys(fn (AiKnowledgeDocument $doc) => [$doc->published_revision_id => $doc->id])->all();

        return $this->build(['tools' => $names, 'skill' => null, 'catalog' => $catalog, 'knowledge' => $knowledge], '', $space, $actor);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function build(array $setup, string $surface, Space $space, User $actor): ToolSet
    {
        $can = fn (string $ability) => fn (): bool => $this->auth->canInSpace($actor, $space, $ability);
        $catalog = [...($setup['catalog'] ?? []), ...($setup['skill'] ? [$setup['skill']['name'] => $setup['skill']] : [])];
        $loadSkill = fn (string $name) => isset($catalog[$name]) ? $this->skills->load($catalog[$name]) : null;
        $knowledge = $setup['knowledge'] ?? [];

        $tools = array_map(fn (string $name): AiToolInterface => match ($name) {
            'get_block_list' => new AuthorizedTool(
                (new GetBlockListTool)->setSpace($space)->setTypes($surface === 'content-tree' ? ['root', 'universal', 'single'] : ['nestable', 'universal']),
                $can('content.view'),
            ),
            'get_block_schemas' => new AuthorizedTool((new GetBlockSchemasTool)->setSpace($space), $can('content.view')),
            'search_assets' => new AuthorizedTool((new SearchAssetsTool)->setSpace($space), $can('assets.view')),
            'get_mentioned_content' => new AuthorizedTool((new GetMentionedContentTool)->setSpace($space), $can('content.view')),
            'get_entry' => new AuthorizedTool(new GetEntryTool, $can('content.view')),
            'search_content' => new AuthorizedTool(new SearchContentTool($this->contentSearch, $space), $can('content.view')),
            'read_skill' => new ReadSkillTool($loadSkill),
            'read_skill_file' => new ReadSkillFileTool($loadSkill),
            'search_knowledge' => new SearchKnowledgeTool($this->search, $knowledge),
            'read_knowledge' => new ReadKnowledgeTool($knowledge),
            'lookup_glossary' => new LookupGlossaryTool(fn () => $this->knowledge->glossaryTerms($space)),
            AskQuestionsTool::NAME => new AskQuestionsTool,
        }, array_values(array_intersect($setup['tools'] ?? [], self::ALL)));

        return new ToolSet($tools);
    }
}
