<?php

declare(strict_types=1);

namespace App\Tables;

use Exception;
use Swoole\Table;

final class HistoryTable
{
    private Table $table;

    private const MAX_ITEMS = 10;

    public function __construct()
    {
        $this->table = new Table(30);

        $this->table->column('id', Table::TYPE_INT);
        $this->table->column('homeName', Table::TYPE_STRING, 50);
        $this->table->column('awayName', Table::TYPE_STRING, 50);
        $this->table->column('homeFlag', Table::TYPE_STRING, 192);
        $this->table->column('awayFlag', Table::TYPE_STRING, 192);
        $this->table->column('homeVotes', Table::TYPE_INT);
        $this->table->column('awayVotes', Table::TYPE_INT);
        $this->table->column('status', Table::TYPE_STRING, 10);
        $this->table->column('createdAt', Table::TYPE_INT);

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

    public function add(array $game): void
    {
        if ($game['status'] !== 'finished') {
            throw new Exception('The game we are trying to add to the history table is not finished.');
        }

        // @TODO - Remove this shit from here. If is not valid we should not call this method.
        if ($game['homeVotes'] === $game['awayVotes']) {
            return;
            throw new Exception('The game we are trying to add to the history table is a draw.');
        }

        $result = [];

        $result[] = [
            'id' => $game['id'],
            'homeName' => $game['homeName'],
            'awayName' => $game['awayName'],
            'homeFlag' => $game['homeFlag'],
            'awayFlag' => $game['awayFlag'],
            'homeVotes' => $game['homeVotes'],
            'awayVotes' => $game['awayVotes'],
            'status' => $game['status'],
            'createdAt' => $game['createdAt'],
        ];

        foreach ($this->table as $row) {
            if (count($result) >= self::MAX_ITEMS) {
                break;
            }

            $result[] = $row;
        }

        foreach ($result as $key => $value) {
            $this->table->set("history_{$key}", $value);
        }
    }
}
