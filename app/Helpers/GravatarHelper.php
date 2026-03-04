<?php declare(strict_types=1);

namespace Devana\Helpers;

final class GravatarHelper
{
    public static function url(string $seed, int $size = 40, string $style = 'pixel-art'): string
    {
        if (empty($seed)) {
            $seed = 'default';
        }
        $encodedSeed = rawurlencode($seed);
        return "https://api.dicebear.com/9.x/{$style}/svg?seed={$encodedSeed}&size={$size}";
    }
}
