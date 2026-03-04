<?php declare(strict_types=1);

namespace Devana\Services;

/**
 * Generates unique fantasy-style usernames for bot accounts.
 * Name pools are grouped by faction to give each bot a culturally fitting name.
 */
final class BotNameService
{
    /** @var array<int, list<string>> */
    private const FIRST_NAMES = [
        1 => ['Marcus', 'Lucius', 'Gaius', 'Titus', 'Quintus', 'Decimus', 'Julius',
              'Claudius', 'Flavius', 'Publius', 'Servius', 'Aemilius', 'Brutus', 'Maximus'],
        2 => ['Arminius', 'Siegfried', 'Gunther', 'Dietrich', 'Albrecht', 'Heinrich',
              'Wolfgang', 'Konrad', 'Gottfried', 'Ulrich', 'Bernhard', 'Hartmann', 'Rudolph'],
        3 => ['Brennus', 'Ambiorix', 'Dumnorix', 'Orgetorix', 'Cavarinus', 'Viridomarus',
              'Viridovix', 'Indutiomarus', 'Comm', 'Litaviccus', 'Celtillus', 'Cassivellaunus'],
    ];

    /** @var array<int, list<string>> */
    private const LAST_NAMES = [
        1 => ['the Bold', 'Ironhand', 'Stoneheart', 'the Brave', 'Blacksword',
              'the Just', 'Redcloak', 'Goldenshield', 'Swiftblade', 'the Fierce'],
        2 => ['Eisenhand', 'Schwarzwald', 'Berghammer', 'Steinbrunn', 'Waldvogel',
              'Kriegsmann', 'Blutfurst', 'Kriegsheld', 'Eisenbrunn', 'Steinwall'],
        3 => ['ap Bran', 'ap Cynr', 'ap Madoc', 'fab Beli', 'fab Cunedd',
              'ap Rhys', 'fab Mawr', 'ap Dafydd', 'fab Emrys', 'ap Gethin'],
    ];

    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a unique username for the given faction.
     * Tries up to 30 combinations before falling back to a timestamped name.
     */
    public function generateUniqueName(int $factionId): string
    {
        $factionId  = max(1, min(3, $factionId));
        $firstNames = self::FIRST_NAMES[$factionId];
        $lastNames  = self::LAST_NAMES[$factionId];

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $first = $firstNames[array_rand($firstNames)];
            $last  = $lastNames[array_rand($lastNames)];
            $name  = $this->normalizeBotName($first . $last);
            if ($name === '') {
                $name = 'Bot' . rand(100, 999);
            }

            // Add a numeric suffix when base combinations run out.
            if ($attempt > 10) {
                $name = substr($name, 0, 40) . rand(10, 99);
            }

            if (strlen($name) > 45) {
                $name = substr($name, 0, 45);
            }

            $exists = $this->db->exec(
                'SELECT id FROM users WHERE name = ? LIMIT 1',
                [$name]
            );

            if (empty($exists)) {
                return $name;
            }
        }

        return 'Bot' . substr(md5((string) microtime(true)), 0, 8);
    }

    private function normalizeBotName(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $map = [
            'ç' => 'c', 'Ç' => 'C',
            'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'İ' => 'I',
            'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S',
            'ü' => 'u', 'Ü' => 'U',
        ];
        $raw = strtr($raw, $map);
        $raw = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '';

        if ($raw === '') {
            return '';
        }

        if (!preg_match('/^[A-Za-z]/', $raw)) {
            $raw = 'Bot' . $raw;
        }

        return $raw;
    }
}
