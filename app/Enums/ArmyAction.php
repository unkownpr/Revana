<?php declare(strict_types=1);

namespace Devana\Enums;

final class ArmyAction
{
    public const REINFORCE = 0;
    public const RAID = 1;
    public const ATTACK = 2;
    public const SPY = 3;

    public static function label(int $type): string
    {
        return match ($type) {
            self::REINFORCE => 'reinforce',
            self::RAID => 'raid',
            self::ATTACK => 'attack',
            self::SPY => 'spy',
            default => 'unknown',
        };
    }
}
