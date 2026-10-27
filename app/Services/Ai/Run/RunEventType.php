<?php

namespace App\Services\Ai\Run;

enum RunEventType: string
{
    case RunStarted = 'run_started';
    case TextDelta = 'text_delta';
    case ToolStarted = 'tool_started';
    case ToolFinished = 'tool_finished';
    case Source = 'source';
    case Proposal = 'proposal';
    case Questionnaire = 'questionnaire';
    case GlossaryFindings = 'glossary_findings';
    case Usage = 'usage';
    case MovedToBackground = 'moved_to_background';
    case Completed = 'completed';
    case Error = 'error';

    /** Events after which an inline stream closes. */
    public function endsStream(): bool
    {
        return \in_array($this, [self::Questionnaire, self::MovedToBackground, self::Completed, self::Error], true);
    }
}
