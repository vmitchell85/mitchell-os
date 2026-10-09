<?php

namespace App\Services\Claude;

enum StreamEventType: string
{
    case TextDelta = 'text_delta';
    case ToolUse = 'tool_use';
}
