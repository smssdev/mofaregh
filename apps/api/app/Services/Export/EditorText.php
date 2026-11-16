<?php

namespace App\Services\Export;

/**
 * Flattens the editor's document (TipTap/ProseMirror JSON) into the plain paragraphs the Word template takes.
 * Character formatting is dropped on purpose: the deliverable has one font and no bold or colour.
 */
class EditorText
{
    /**
     * @param  array<string, mixed>|null  $doc
     * @return list<string>
     */
    public static function paragraphs(?array $doc): array
    {
        $out = [];
        foreach ($doc['content'] ?? [] as $node) {
            self::block($node, $out);
        }

        return array_values(array_filter($out, fn (string $p): bool => trim($p) !== ''));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $out
     */
    private static function block(array $node, array &$out, string $prefix = ''): void
    {
        switch ($node['type'] ?? '') {
            case 'paragraph':
            case 'heading':
            case 'codeBlock':
                $out[] = $prefix.self::inline($node);
                break;

            case 'bulletList':
            case 'taskList':
                foreach ($node['content'] ?? [] as $item) {
                    self::listItem($item, $out, '• ');
                }
                break;

            case 'orderedList':
                $n = (int) ($node['attrs']['start'] ?? 1);
                foreach ($node['content'] ?? [] as $item) {
                    self::listItem($item, $out, self::arabicDigits($n++).'- ');
                }
                break;

            case 'table':
                foreach ($node['content'] ?? [] as $row) {
                    $cells = [];
                    foreach ($row['content'] ?? [] as $cell) {
                        $lines = [];
                        foreach ($cell['content'] ?? [] as $child) {
                            self::block($child, $lines);
                        }
                        $cells[] = implode(' ', $lines);
                    }
                    $out[] = implode("\t", $cells);
                }
                break;

            default:   // blockquote and any other wrapper: keep its paragraphs
                foreach ($node['content'] ?? [] as $child) {
                    self::block($child, $out, $prefix);
                }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $out
     */
    private static function listItem(array $item, array &$out, string $marker): void
    {
        $first = true;
        foreach ($item['content'] ?? [] as $child) {
            self::block($child, $out, $first ? $marker : '');
            $first = false;
        }
    }

    /** @param  array<string, mixed>  $node */
    private static function inline(array $node): string
    {
        $text = '';
        foreach ($node['content'] ?? [] as $child) {
            $text .= match ($child['type'] ?? '') {
                'text' => (string) ($child['text'] ?? ''),
                'hardBreak' => "\n",
                default => self::inline($child),
            };
        }

        return $text;
    }

    private static function arabicDigits(int $n): string
    {
        return strtr((string) $n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
