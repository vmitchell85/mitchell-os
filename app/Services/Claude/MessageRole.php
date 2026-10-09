<?php

namespace App\Services\Claude;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
