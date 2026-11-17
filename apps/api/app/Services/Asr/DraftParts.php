<?php

namespace App\Services\Asr;

/**
 * Putting a draft together from parts transcribed one at a time: a new part replaces what the draft had in the
 * same stretch of audio and keeps the rest; the covered stretches are kept merged so the next part can follow.
 */
class DraftParts
{
    /**
     * @param  list<array<string, mixed>>|null  $draft
     * @param  list<array<string, mixed>>  $part
     * @return list<array<string, mixed>>
     */
    public static function merge(?array $draft, array $part, int $startMs, int $endMs): array
    {
        $kept = array_filter($draft ?? [], fn (array $p): bool => $p['start_ms'] < $startMs || $p['start_ms'] >= $endMs);
        $all = [...array_values($kept), ...$part];
        usort($all, fn (array $a, array $b): int => $a['start_ms'] <=> $b['start_ms']);

        return array_values($all);
    }

    /**
     * @param  list<array{int, int}>|null  $covered
     * @return list<array{int, int}>
     */
    public static function cover(?array $covered, int $startMs, int $endMs): array
    {
        $spans = [...($covered ?? []), [$startMs, $endMs]];
        usort($spans, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($spans as [$from, $to]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
            } else {
                $merged[] = [(int) $from, (int) $to];
            }
        }

        return $merged;
    }
}
