<?php

namespace App\Services\Claude;

final readonly class StreamEvent
{
    public function __construct(
        public StreamEventType $type,
        public ?string $text = null,
        public ?string $toolName = null,
    ) {}

    /**
     * Translate one line of `stream-json` output into an event worth surfacing, if it is one.
     *
     * @param  array<string, mixed>  $line
     */
    public static function fromLine(array $line): ?self
    {
        if (($line['type'] ?? null) !== 'stream_event') {
            return null;
        }

        $event = $line['event'];

        return match (true) {
            $event['type'] === 'content_block_delta' && $event['delta']['type'] === 'text_delta' => new self(StreamEventType::TextDelta, text: $event['delta']['text']),
            $event['type'] === 'content_block_start' && $event['content_block']['type'] === 'tool_use' => new self(StreamEventType::ToolUse, toolName: $event['content_block']['name']),
            default => null,
        };
    }
}
