<?php

namespace App\Services\Ai\Operations;

use App\Enums\AiOperationKind;
use App\Services\Ai\Changesets\ChangesetRecorder;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Contracts\ProposesChanges;
use App\Services\Ai\Operations\Tools\ProposePlanTool;
use App\Services\Ai\Operations\Tools\ReportItemsTool;
use App\Services\Ai\Run\ToolSet;
use App\Services\Ai\Tools\AskQuestionsTool;
use App\Services\Ai\Tools\ProposeTranslationMetadataTool;
use App\Services\Auth\AuthorizationService;

/**
 * Adjusts the tools of an operation run in code, whatever the skill lists:
 * a planning run loses every tool that proposes changes and gets
 * propose_plan; a batch run cannot pause for questions nobody would answer
 * and gets report_items. A translation batch whose plan generates metadata also
 * gets the tool that writes it, since its skill was written for translating.
 */
final class OperationTools
{
    public static function adjust(OperationContext $context, ToolSet $tools): ToolSet
    {
        $kept = [];
        foreach ($tools->names() as $name) {
            $tool = $tools->get($name);
            $drop = $context->isPlanning() ? $tool instanceof ProposesChanges : $name === AskQuestionsTool::NAME;
            if (! $drop) {
                $kept[] = $tool;
            }
        }

        $kept[] = $context->isPlanning() ? new ProposePlanTool($context) : new ReportItemsTool($context);
        if (! $context->isPlanning() && $context->operation->kind === AiOperationKind::Translation && isset($context->plan()['metadata'])) {
            $kept[] = new ProposeTranslationMetadataTool($context->run, $context->operation->space, $context->operation->actor, app(ChangeTargets::class), app(ChangesetRecorder::class), app(AuthorizationService::class));
        }

        return new ToolSet($kept);
    }
}
