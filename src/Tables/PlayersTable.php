<?php

declare(strict_types=1);

namespace App\Tables;

use Exception;
use Swoole\Table;

final class PlayersTable
{
    private Table $table;

    private const MAX_PLAYERS = 1024;

    private const VOTE_COOLDOWN = 1;

    public function __construct()
    {
        $this->table = new Table(self::MAX_PLAYERS);

        $this->table->column('fd', Table::TYPE_INT);
        $this->table->column('name', Table::TYPE_STRING, 50);
        $this->table->column('lastVotedAt', Table::TYPE_INT);

        $this->table->create();
    }

    public function find($fd): array
    {
        $player = $this->table->get("players_{$fd}");

        if ($player === false) {
            throw new Exception("Player with fd {$fd} not found");
        }

        return $player;
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
            'lastVotedAt' => time(),
        ];

        $this->table->set("players_{$fd}", $player);

        return true;
    }

    public function vote(int $fd): bool
    {
        $player = $this->find($fd);

        $elapsed = time() - $player['lastVotedAt'];

        if ($elapsed >= self::VOTE_COOLDOWN) {
            $this->setItems($fd, ['lastVotedAt' => time()]);
            return true;
        }

        return false;
    }

    public function remove(int $fd): void
    {
        $this->table->del("players_{$fd}");
    }

    private function setItems(int $fd, array $payload): void
    {
        $player = $this->table->get("players_{$fd}");

        if ($player === false) {
            throw new Exception("Player with fd {$fd} not found");
        }

        $player = array_merge($player, $payload);

        $this->table->set("players_{$fd}", $player);
    }
}
