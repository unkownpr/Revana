<?php

declare(strict_types=1);

namespace Devana\Models;

final class GameConfig extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'config');
        $this->database = $db;
    }

    public function all(): array
    {
        return $this->database->exec('SELECT * FROM config ORDER BY ord');
    }

    public function getValue(string $name): ?string
    {
        $this->load(['name = ?', $name]);

        if ($this->dry()) {
            return null;
        }

        return (string) $this->cast()['value'];
    }

    public function setValue(string $name, string $value): void
    {
        $this->database->exec(
            'UPDATE config SET value = ? WHERE name = ?',
            [$value, $name]
        );
    }
}
