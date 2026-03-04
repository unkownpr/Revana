<?php declare(strict_types=1);

namespace Devana\Enums;

final class UserRole
{
    public const DELETED = 0;
    public const NORMAL = 1;
    public const PREMIUM = 2;
    public const MODERATOR = 3;
    public const ADMIN = 4;
    public const OWNER = 5;

    public static function isAdmin(int $level): bool
    {
        return $level >= self::MODERATOR;
    }

    public static function isOwner(int $level): bool
    {
        return $level >= self::OWNER;
    }
}
