<?php

declare(strict_types=1);

namespace App\Tables;

use App\Exceptions\PlayerNotFoundException;
use App\Exceptions\PlayerOfflineException;
use Swoole\Table;

final class PlayersTable
{
    private Table $table;

    private const MAX_PLAYERS = 1024;

    private const VOTE_COOLDOWN = 1;

    public function __construct()
    {
        $this->table = new Table(self::MAX_PLAYERS);

        $this->table->column('id', Table::TYPE_STRING, 26);
        $this->table->column('fd', Table::TYPE_INT);
        $this->table->column('name', Table::TYPE_STRING, 50);
        $this->table->column('currentTeam', Table::TYPE_INT);
        $this->table->column('wins', Table::TYPE_INT);
        $this->table->column('lastVotedAt', Table::TYPE_INT);
        $this->table->column('connected', Table::TYPE_INT);
        $this->table->column('lastLoginAt', Table::TYPE_INT);

        $this->table->create();
    }

    public function find(string $userId, bool $searchOffline = false): array
    {
        $player = $this->table->get("$userId");

        if ($player === false) {
            throw new PlayerNotFoundException($userId, 'fd');
        }

        if (! $searchOffline && $player['connected'] === 0) {
            throw new PlayerOfflineException($userId);
        }

        return $player;
    }

    public function findByFd(int $fd): array
    {
        $result = null;

        foreach ($this->table as $row) {
            if ($row['fd'] !== $fd) {
                continue;
            }

            $result = $row;
            break;
        }

        if ($result === null) {
            throw new PlayerNotFoundException($fd, 'fd');
        }

        return $result;
    }

    public function get(bool $searchOffline = false): array
    {
        $result = [];

        foreach ($this->table as $row) {
            if ($searchOffline) {
                $result[] = $row;
            } else {
                if ($row['connected'] === 1) {
                    $result[] = $row;
                }
            }
        }

        return $result;
    }

    public function add(int $fd, string $userId): bool
    {
        $userId = preg_replace('/[^a-zA-Z0-9]/', '', $userId);

        try {
            $player = $this->find(
                userId: $userId,
                searchOffline: true
            );
        } catch (PlayerNotFoundException) {
            $player = null;
        }

        if (! $player) {
            if (count($this->table) >= self::MAX_PLAYERS) {
                // Já atingimos o máximo de jogadores na memória
                return false;
            }

            $player = [
                'id' => $userId,
                'fd' => $fd,
                'name' => "Jogador",
                'connected' => 0,
            ];

            echo "Novo usuário se conectou a base!\n";
        } else {
            echo "Usuário já existente se conectou a base!\n";
        }

        if ($player['connected'] === 1) {
            // Player já está logado
            return false;
        }

        $player['fd'] = $fd;
        $player['connected'] = 1;
        $player['lastLoginAt'] = time();

        $this->table->set($userId, $player);

        return true;
    }

    public function vote(int $fd): bool
    {
        $player = $this->findByFd($fd);

        $elapsed = time() - $player['lastVotedAt'];

        if ($elapsed >= self::VOTE_COOLDOWN) {
            $this->setItems($fd, ['lastVotedAt' => time()]);
            return true;
        }

        return false;
    }

    public function remove(int $fd): void
    {
        try {
            $player = $this->findByFd($fd);
        } catch (PlayerNotFoundException) {
            // Se o player não foi encontrado, então ele não
            // chegou a logar. Isso significa que o server tá cheio
            // ou usuário tentou abrir outra aba.
            return;
        }

        if ($player['connected'] === 0) {
            throw new PlayerOfflineException($player['id']);
        }

        $this->table->decr($player['id'], 'connected');
    }

    public function givePrize(string $winner): void
    {
        $players = $this->get();

        foreach ($players as $player) {
            if ($player['currentTeam'] !== $winner) {
                continue;
            }

            $this->table->incr($player['id'], 'wins');
        }
    }

    public function cleanUpAfterGame(): void
    {
        $players = $this->get();

        foreach ($players as $player) {
            $this->table->set($player['id'], [
                'currentTeam' => 0,
                'lastVotedAt' => 0,
            ]);
        }
    }

    private function setItems(int $fd, array $payload): void
    {
        $player = $this->findByFd($fd);

        if ($player === false) {
            throw new PlayerNotFoundException($fd, 'fd');
        }

        $player = array_merge($player, $payload);

        $this->table->set($player['id'], $player);
    }
}
