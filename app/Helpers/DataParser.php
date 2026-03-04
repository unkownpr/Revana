<?php declare(strict_types=1);

namespace Devana\Helpers;

final class DataParser
{
    /**
     * Parse hyphen-separated string into int array.
     * Replaces: array_map('intval', explode('-', $str))
     *
     * @return array<int, int>
     */
    public static function toIntArray(string $str): array
    {
        return array_map('intval', explode('-', $str));
    }

    /**
     * Parse hyphen-separated string into float array.
     * Replaces: array_map('floatval', explode('-', $str))
     *
     * @return array<int, float>
     */
    public static function toFloatArray(string $str): array
    {
        return array_map('floatval', explode('-', $str));
    }

    /**
     * Serialize int array to hyphen-separated string.
     * Replaces: implode('-', $arr)
     */
    public static function serialize(array $arr): string
    {
        return implode('-', $arr);
    }

    /**
     * Serialize float array to hyphen-separated string with rounding.
     * Replaces: implode('-', array_map(fn(float $v): string => (string) round($v, 2), $arr))
     */
    public static function serializeRounded(array $arr, int $decimals = 2): string
    {
        return implode('-', array_map(
            fn(float $v): string => (string) round($v, $decimals),
            $arr
        ));
    }

    /**
     * Parse "H:M" duration string into seconds.
     * Replaces duplicate parseDuration() in QueueService and ArmyService.
     */
    public static function durationToSeconds(string $duration): int
    {
        $parts = explode(':', $duration);

        return ((int) ($parts[0] ?? 0)) * 3600 + ((int) ($parts[1] ?? 0)) * 60;
    }

    /**
     * Convert seconds to "H:M" duration string.
     */
    public static function secondsToDuration(int $seconds): string
    {
        return intdiv($seconds, 3600) . ':' . intdiv($seconds % 3600, 60);
    }

    /**
     * Parse resource string into named array.
     * Single source of truth - replaces duplicates in TownService and ResourceService.
     *
     * @return array{crop: float, lumber: float, stone: float, iron: float, gold: float}
     */
    public static function parseResources(string $resources): array
    {
        $parts = self::toFloatArray($resources);

        return [
            'crop'   => $parts[0] ?? 0.0,
            'lumber' => $parts[1] ?? 0.0,
            'stone'  => $parts[2] ?? 0.0,
            'iron'   => $parts[3] ?? 0.0,
            'gold'   => $parts[4] ?? 0.0,
        ];
    }

    /**
     * Parse general string into named array.
     * Replaces repeated explode('-', $town['general']) patterns.
     *
     * @return array{presence: int, level: int, unit_type: int, formation: int, xp: int}
     */
    public static function parseGeneral(string $general): array
    {
        $parts = self::toIntArray($general);

        return [
            'presence'  => $parts[0] ?? 0,
            'level'     => $parts[1] ?? 0,
            'unit_type' => $parts[2] ?? 0,
            'formation' => $parts[3] ?? 0,
            'xp'        => $parts[4] ?? 0,
        ];
    }

}
