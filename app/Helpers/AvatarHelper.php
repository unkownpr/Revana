<?php declare(strict_types=1);

namespace Devana\Helpers;

final class AvatarHelper
{
    public const DEFAULT_STYLE = 'pixel-art';

    /** @var array<string, string[]> */
    private const STYLE_ALLOWED = [
        'pixel-art' => ['pixel-art'],
    ];

    /** @var string[] */
    private const ACCESSORIES = ['variant01', 'variant02', 'variant03', 'variant04'];
    /** @var string[] */
    private const BEARD = ['variant01', 'variant02', 'variant03', 'variant04', 'variant05', 'variant06', 'variant07', 'variant08'];
    /** @var string[] */
    private const CLOTHING = [
        'variant01','variant02','variant03','variant04','variant05','variant06','variant07','variant08','variant09','variant10','variant11','variant12','variant13','variant14','variant15','variant16','variant17','variant18','variant19','variant20','variant21','variant22','variant23'
    ];
    /** @var string[] */
    private const EYES = ['variant01','variant02','variant03','variant04','variant05','variant06','variant07','variant08','variant09','variant10','variant11','variant12'];
    /** @var string[] */
    private const GLASSES = ['dark01','dark02','dark03','dark04','dark05','dark06','dark07','light01','light02','light03','light04','light05','light06','light07'];
    /** @var string[] */
    private const HAIR = [
        'long01','long02','long03','long04','long05','long06','long07','long08','long09','long10','long11','long12','long13','long14','long15','long16','long17','long18','long19','long20','long21',
        'short01','short02','short03','short04','short05','short06','short07','short08','short09','short10','short11','short12','short13','short14','short15','short16','short17','short18','short19','short20','short21','short22','short23','short24'
    ];
    /** @var string[] */
    private const HAT = ['variant01','variant02','variant03','variant04','variant05','variant06','variant07','variant08','variant09','variant10'];
    /** @var string[] */
    private const MOUTH = ['happy01','happy02','happy03','happy04','happy05','happy06','happy07','happy08','happy09','happy10','happy11','happy12','happy13','sad01','sad02','sad03','sad04','sad05','sad06','sad07','sad08','sad09','sad10'];

    /** @var string[] */
    private const COLOR_SKIN = ['8d5524','a26d3d','b68655','cb9e6e','e0b687','eac393','f5cfa0','ffdbac'];
    /** @var string[] */
    private const COLOR_ACCESSORY = ['a9a9a9','d3d3d3','daa520','fafad2','ffd700'];
    /** @var string[] */
    private const COLOR_CLOTHING = ['00b159','5bc0de','44c585','88d8b0','428bca','03396c','ae0001','d11141','ff6f69','ffc425','ffd969','ffeead'];
    /** @var string[] */
    private const COLOR_EYES = ['5b7c8b','647b90','697b94','76778b','588387','876658'];
    /** @var string[] */
    private const COLOR_GLASSES = ['4b4b4b','5f705c','43677d','191919','323232','a04b5d'];
    /** @var string[] */
    private const COLOR_HAIR = ['009bbd','91cb15','603a14','611c17','28150a','83623b','603015','612616','a78961','bd1700','cab188'];
    /** @var string[] */
    private const COLOR_HAT = ['2e1e05','3d8a6b','614f8a','2663a3','989789','a62116','cc6192'];
    /** @var string[] */
    private const COLOR_MOUTH = ['c98276','d29985','de0f0d','e35d6a'];
    /** @var string[] */
    private const COLOR_BACKGROUND = ['b6e3f4','c0aede','d1d4f9','ffd5dc','ffdfbf','transparent'];

    public static function decodeAndNormalize(?string $json, string $seedFallback): array
    {
        $data = [];
        if (!empty($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return self::normalize($data, $seedFallback);
    }

    public static function normalize(array $input, string $seedFallback): array
    {
        $seed = trim((string) ($input['seed'] ?? $seedFallback));
        if ($seed === '') {
            $seed = $seedFallback ?: 'player';
        }
        $seed = mb_substr($seed, 0, 64);

        $style = self::DEFAULT_STYLE;
        $requestedStyle = $input['style'] ?? self::DEFAULT_STYLE;
        if (is_string($requestedStyle) && isset(self::STYLE_ALLOWED[$requestedStyle])) {
            $style = $requestedStyle;
        }

        $options = [
            'seed' => $seed,
            'style' => $style,
        ];

        $options['flip'] = (bool) ($input['flip'] ?? false);
        $options['clip'] = array_key_exists('clip', $input) ? (bool) $input['clip'] : true;
        $options['randomizeIds'] = (bool) ($input['randomizeIds'] ?? false);

        $options['rotate'] = self::clampInt($input['rotate'] ?? 0, 0, 360);
        $options['scale'] = self::clampInt($input['scale'] ?? 100, 0, 200);
        $options['radius'] = self::clampInt($input['radius'] ?? 0, 0, 50);
        $options['translateX'] = self::clampInt($input['translateX'] ?? 0, -100, 100);
        $options['translateY'] = self::clampInt($input['translateY'] ?? 0, -100, 100);

        $options['backgroundType'] = self::filterArray($input['backgroundType'] ?? [], ['solid', 'gradientLinear']);
        $options['backgroundRotation'] = self::filterRotation($input['backgroundRotation'] ?? [0, 360]);
        $options['backgroundColor'] = self::filterColors($input['backgroundColor'] ?? [], self::COLOR_BACKGROUND);

        $options['accessories'] = self::filterArray($input['accessories'] ?? [], self::ACCESSORIES);
        $options['accessoriesColor'] = self::filterColors($input['accessoriesColor'] ?? [], self::COLOR_ACCESSORY);
        $options['accessoriesProbability'] = self::clampInt($input['accessoriesProbability'] ?? 10, 0, 100);

        $options['beard'] = self::filterArray($input['beard'] ?? [], self::BEARD);
        $options['beardProbability'] = self::clampInt($input['beardProbability'] ?? 5, 0, 100);

        $options['clothing'] = self::filterArray($input['clothing'] ?? [], self::CLOTHING);
        $options['clothingColor'] = self::filterColors($input['clothingColor'] ?? [], self::COLOR_CLOTHING);

        $options['eyes'] = self::filterArray($input['eyes'] ?? [], self::EYES);
        $options['eyesColor'] = self::filterColors($input['eyesColor'] ?? [], self::COLOR_EYES);

        $options['glasses'] = self::filterArray($input['glasses'] ?? [], self::GLASSES);
        $options['glassesColor'] = self::filterColors($input['glassesColor'] ?? [], self::COLOR_GLASSES);
        $options['glassesProbability'] = self::clampInt($input['glassesProbability'] ?? 20, 0, 100);

        $options['hair'] = self::filterArray($input['hair'] ?? [], self::HAIR);
        $options['hairColor'] = self::filterColors($input['hairColor'] ?? [], self::COLOR_HAIR);

        $options['hat'] = self::filterArray($input['hat'] ?? [], self::HAT);
        $options['hatColor'] = self::filterColors($input['hatColor'] ?? [], self::COLOR_HAT);
        $options['hatProbability'] = self::clampInt($input['hatProbability'] ?? 5, 0, 100);

        $options['mouth'] = self::filterArray($input['mouth'] ?? [], self::MOUTH);
        $options['mouthColor'] = self::filterColors($input['mouthColor'] ?? [], self::COLOR_MOUTH);

        $options['skinColor'] = self::filterColors($input['skinColor'] ?? [], self::COLOR_SKIN);

        return $options;
    }

    public static function url(string $style, array $options, int $size = 96): string
    {
        $style = isset(self::STYLE_ALLOWED[$style]) ? $style : self::DEFAULT_STYLE;
        $query = [];
        foreach ($options as $key => $value) {
            if ($key === 'style') {
                continue;
            }
            if ($key === 'seed') {
                $query['seed'] = $value;
                continue;
            }
            if (is_bool($value)) {
                $query[$key] = $value ? 'true' : 'false';
                continue;
            }
            if (is_int($value)) {
                $query[$key] = (string) $value;
                continue;
            }
            if (is_array($value)) {
                $filtered = array_values(array_filter($value, static fn($v) => $v !== '' && $v !== null));
                if (!empty($filtered)) {
                    if ($key === 'backgroundRotation' && count($filtered) === 2) {
                        $query[$key] = implode(',', $filtered);
                    } else {
                        $query[$key] = implode(',', $filtered);
                    }
                }
            }
        }

        $query['size'] = (string) $size;

        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return "https://api.dicebear.com/9.x/{$style}/svg?{$qs}";
    }

    private static function clampInt(mixed $value, int $min, int $max): int
    {
        $intVal = (int) $value;
        if ($intVal < $min) {
            return $min;
        }
        if ($intVal > $max) {
            return $max;
        }
        return $intVal;
    }

    /** @param array<int|string, mixed> $values */
    private static function filterArray(array $values, array $allowed): array
    {
        $allowedSet = array_fill_keys($allowed, true);
        $result = [];
        foreach ($values as $val) {
            if (is_string($val) && isset($allowedSet[$val])) {
                $result[] = $val;
            }
        }
        return array_values(array_unique($result));
    }

    /** @param array<int|string, mixed> $values */
    private static function filterColors(array $values, array $palette): array
    {
        $paletteSet = array_fill_keys($palette, true);
        $result = [];
        foreach ($values as $val) {
            if (!is_string($val)) {
                continue;
            }
            $val = strtolower(trim($val));
            if ($val === 'transparent') {
                $result[] = 'transparent';
                continue;
            }
            if (preg_match('/^[0-9a-f]{6}$/', $val) && isset($paletteSet[$val])) {
                $result[] = $val;
            }
        }
        return array_values(array_unique($result));
    }

    private static function filterRotation(mixed $value): array
    {
        $default = [0, 360];
        if (!is_array($value)) {
            return $default;
        }
        $vals = array_values($value);
        if (count($vals) !== 2) {
            return $default;
        }
        $a = self::clampInt($vals[0], 0, 360);
        $b = self::clampInt($vals[1], 0, 360);
        return [$a, $b];
    }
}
