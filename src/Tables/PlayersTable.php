<?php

declare(strict_types=1);

namespace App\Tables;

use Exception;
use Swoole\Table;

final class PlayersTable
{
    private Table $table;

    private const MAX_PLAYERS = 1024;

    public function __construct()
    {
        $this->table = new Table(self::MAX_PLAYERS);

        $this->table->column('fd', Table::TYPE_INT);
        $this->table->column('name', Table::TYPE_STRING, 50);

        $this->table->create();
    }

    public function get(): array
    {
        $result = [];

        foreach ($this->table as $row) {
            $result[] = $row;
        }

        return $result;
    }

    public function add(int $fd): bool
    {
        if (count($this->table) >= self::MAX_PLAYERS) {
            return false;
        }

        $player = [
            'fd' => $fd,
            'name' => "Player {$fd}",
        ];

        $this->table->set("players_{$fd}", $player);

        return true;
    }

    public function remove(int $fd): void
    {
        $this->table->del("players_{$fd}");
    }
}
