<?php

declare(strict_types=1);

namespace Devana\Services;

final class PreferenceService
{
    private \DB\SQL $db;

    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'allianceReports' => '1',
        'combatReports'   => '1',
        'tradeReports'    => '1',
        'nav_town_id'     => '',
    ];

    /** Keys whose values are stored as-is (not normalized to 0/1). */
    private const RAW_KEYS = ['nav_town_id'];

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, string>
     */
    public function getAll(int $userId): array
    {
        $prefs = self::DEFAULTS;
        $rows = $this->db->exec('SELECT `name`, `value` FROM preferences WHERE `user` = ?', [$userId]);

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || !array_key_exists($name, self::DEFAULTS)) {
                continue;
            }
            $prefs[$name] = (string) ($row['value'] ?? self::DEFAULTS[$name]);
        }

        return $prefs;
    }

    public function set(int $userId, string $name, string $value): void
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            return;
        }

        $normalized = in_array($name, self::RAW_KEYS, true)
            ? $value
            : (in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0');
        $this->db->exec(
            'INSERT INTO preferences (`user`, `name`, `value`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$userId, $name, $normalized]
        );
    }

    public function isEnabled(int $userId, string $name): bool
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            return false;
        }

        $row = $this->db->exec(
            'SELECT `value` FROM preferences WHERE `user` = ? AND `name` = ? LIMIT 1',
            [$userId, $name]
        );
        if (empty($row)) {
            return self::DEFAULTS[$name] === '1';
        }

        return (string) ($row[0]['value'] ?? '0') === '1';
    }
}
