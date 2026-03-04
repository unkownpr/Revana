<?php declare(strict_types=1);

namespace Devana\Helpers;

final class InputSanitizer
{
    public static function clean(string $value): string
    {
        $value = trim($value);
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return $value;
    }

    public static function cleanInt(mixed $value): int
    {
        return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    public static function cleanEmail(string $value): string
    {
        return (string) filter_var(trim($value), FILTER_SANITIZE_EMAIL);
    }

    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
