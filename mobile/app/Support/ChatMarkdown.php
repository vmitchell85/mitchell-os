<?php

namespace App\Support;

/**
 * Turns the markdown in Claude's replies into a few simple blocks that the
 * chat screen can draw with native text (there is no built-in markdown view).
 *
 * Block types: paragraph, heading, bullet, numbered, code and rule. Every
 * block except code and rule has inline runs; code has a single text line.
 *
 * @phpstan-type Run array{text: string, bold: bool, italic: bool, code: bool, link: bool}
 * @phpstan-type Block array{type: string, level: int, marker: string, indent: int, text: string, runs: array<int, Run>}
 */
class ChatMarkdown
{
    /**
     * @return array<int, Block>
     */
    public static function blocks(string $markdown): array
    {
        $lines = preg_split('/\R/', str_replace("\t", '    ', trim($markdown))) ?: [];
        $blocks = [];
        $paragraph = [];
        $code = null;

        $flush = function () use (&$blocks, &$paragraph): void {
            if ($paragraph !== []) {
                $blocks[] = self::block('paragraph', implode(' ', $paragraph));
                $paragraph = [];
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                if ($code === null) {
                    $flush();
                    $code = [];
                } else {
                    $blocks[] = self::block('code', '', text: implode("\n", $code));
                    $code = null;
                }

                continue;
            }

            if ($code !== null) {
                $code[] = $line;

                continue;
            }

            if (trim($line) === '') {
                $flush();
            } elseif (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
                $flush();
                $blocks[] = self::block('rule', '');
            } elseif (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $match) === 1) {
                $flush();
                $blocks[] = self::block('heading', $match[2], level: strlen($match[1]));
            } elseif (preg_match('/^(\s*)[-*+]\s+(.*)$/', $line, $match) === 1) {
                $flush();
                $blocks[] = self::block('bullet', $match[2], indent: intdiv(strlen($match[1]), 2));
            } elseif (preg_match('/^(\s*)(\d{1,3})[.)]\s+(.*)$/', $line, $match) === 1) {
                $flush();
                $blocks[] = self::block('numbered', $match[3], marker: $match[2].'.', indent: intdiv(strlen($match[1]), 2));
            } elseif (preg_match('/^\s*>\s?(.*)$/', $line, $match) === 1) {
                $paragraph[] = $match[1];
            } else {
                $paragraph[] = trim($line);
            }
        }

        if ($code !== null) {
            $blocks[] = self::block('code', '', text: implode("\n", $code));
        }

        $flush();

        return $blocks;
    }

    /**
     * Splits one line of markdown into styled runs: **bold**, *italic*,
     * `code`, and [links](url) (shown as their label).
     *
     * @return array<int, Run>
     */
    public static function runs(string $text): array
    {
        $pattern = '/`([^`]+)`|\*\*(.+?)\*\*|__(.+?)__|\*([^*\s][^*]*?)\*|(?<![\w])_([^_\s][^_]*?)_(?![\w])|\[([^\]]+)\]\(([^)\s]+)\)/s';
        $runs = [];
        $offset = 0;

        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$whole, $position] = $match[0];

            if ($position > $offset) {
                $runs[] = self::run(substr($text, $offset, $position - $offset));
            }

            $runs[] = match (true) {
                ($match[1][1] ?? -1) >= 0 => self::run($match[1][0], code: true),
                ($match[2][1] ?? -1) >= 0 => self::run($match[2][0], bold: true),
                ($match[3][1] ?? -1) >= 0 => self::run($match[3][0], bold: true),
                ($match[4][1] ?? -1) >= 0 => self::run($match[4][0], italic: true),
                ($match[5][1] ?? -1) >= 0 => self::run($match[5][0], italic: true),
                default => self::run($match[6][0], link: true),
            };

            $offset = $position + strlen($whole);
        }

        if ($offset < strlen($text)) {
            $runs[] = self::run(substr($text, $offset));
        }

        return array_values(array_filter($runs, fn (array $run): bool => $run['text'] !== ''));
    }

    /**
     * @return array{type: string, level: int, marker: string, indent: int, text: string, runs: array<int, Run>}
     */
    protected static function block(string $type, string $source, int $level = 0, string $marker = '', int $indent = 0, string $text = ''): array
    {
        return [
            'type' => $type,
            'level' => $level,
            'marker' => $marker,
            'indent' => min($indent, 3),
            'text' => $text,
            'runs' => in_array($type, ['code', 'rule'], true) ? [] : self::runs($source),
        ];
    }

    /**
     * @return Run
     */
    protected static function run(string $text, bool $bold = false, bool $italic = false, bool $code = false, bool $link = false): array
    {
        return ['text' => $text, 'bold' => $bold, 'italic' => $italic, 'code' => $code, 'link' => $link];
    }
}
