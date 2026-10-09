<?php

namespace App\Services\Claude;

enum ClaudeRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
