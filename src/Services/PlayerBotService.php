<?php

declare(strict_types=1);

namespace App\Services;

use App\Tables\PlayersTable;
use Ulid\Ulid;

final class PlayerBotService
{
    private int $minBots;
    private int $maxBots;

    public function __construct(
        private PlayersTable $playersTable
    ) {
        $this->minBots = (int) $_ENV['PLAYER_BOT_MIN'] ?? 1;
        $this->maxBots = (int) $_ENV['PLAYER_BOT_MAX'] ?? 1;
    }

    public function get(bool $searchOffline = false): array
    {
        $players = [];

        foreach ($this->playersTable->get($searchOffline) as $player) {
            if (! $player['isBot']) {
                continue;
            }

            $players[] = $player;
        }

        return $players;
    }

    /**
     * Cria jogadores bots para serem utilizados de acordo
     * com a configuração na .env
     *
     * @return void
     */
    public function createBots(): void
    {
        for ($i = 0; $i < $this->maxBots; $i++) {
            $this->playersTable->addBot();
        }
    }

    /**
     * No início de cada rodada, nós rotacionamos os bots que estão online.
     * Isso é feito para que os bots não fiquem sempre na sala de jogo e haja rotatividade.
     * 
     * @return void
     */
    public function joinRoom(): void
    {
        // Pego um número aleatório entre o mínimo e máximo de bots
        $count = random_int($this->minBots, $this->maxBots);

        // Pego os bots que estão online
        $players = $this->get();

        if (count($players) === $count) {
            // Se o número de bots online for igual ao valor desejado, não precisamos
            // logar mais bots.
            debugLog("[PlayerBotService] Já temos {$count} bots online.");

            return;
        } elseif (count($players) < $count) {
            // Se o número de bots online for menor que o valor desejado, preicsamos
            // logar mais bots.

            $playersWithOffline = $this->get(searchOffline: true);

            $connectCount = $count - count($players);

            debugLog("[PlayerBotService] Vamos conectar {$connectCount} bots.");

            foreach ($playersWithOffline as $player) {
                if ($connectCount <= 0) {
                    break;
                }

                // Se o bot já estiver online, não precisamos logar novamente
                if ($player['connected'] === 1) {
                    continue;
                }

                $this->playersTable->setOnline($player['id']);
            }
        } else {
            // Se o número de bots online for maior que o valor desejado, precisamos
            // desconectar alguns bots.
            $disconnectCount = count($players) - $count;

            debugLog("[PlayerBotService] Vamos desconectar {$disconnectCount} bots.");

            foreach ($players as $player) {
                if ($disconnectCount <= 0) {
                    break;
                }

                // Desconecto o bot
                $this->playersTable->remove($player['id']);

                $disconnectCount--;
            }

            return;
        }
    }
}
