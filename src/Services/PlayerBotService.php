<?php

declare(strict_types=1);

namespace App\Services;

use App\Tables\PlayersTable;
use Swoole\Coroutine;
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
        $botPlayers = [];

        $allPlayers = $this->playersTable->get($searchOffline);

        foreach ($allPlayers as $player) {
            if ($player['isBot'] !== 1) {
                continue;
            }

            $botPlayers[] = $player;
        }

        return $botPlayers;
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

        debugLog("[PlayerBotService] Temos " . count($players) . " bots online e desejamos ter {$count} bots.");

        if (count($players) === $count) {
            // Se o número de bots online for igual ao valor desejado, não precisamos
            // logar mais bots.
            debugLog("[PlayerBotService] Já temos {$count} bots online.");

            return;
        } elseif (count($players) < $count) {
            // Se o número de bots online for menor que o valor desejado, preicsamos
            // logar mais bots.
            $connectCount = $count - count($players);

            debugLog("[PlayerBotService] Vamos conectar {$connectCount} bots.");

            $playersWithOffline = $this->playersTable->get(searchOffline: true);

            foreach ($playersWithOffline as $player) {
                if ($connectCount <= 0) {
                    break;
                }

                if ($player['isBot'] === false) {
                    continue;
                }

                // Se o bot já estiver online, não precisamos logar novamente
                if ($player['connected'] === 1) {
                    continue;
                }

                $this->playersTable->setOnline($player['id'], $player['fd']);

                debugLog("Conectando bot {$player['name']} ({$player['id']})");

                $connectCount--;
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

                debugLog("Desconectando bot {$player['name']} ({$player['id']})");

                // Desconecto o bot
                $this->playersTable->remove($player['id']);

                $disconnectCount--;
            }

            return;
        }
    }

    public function chooseTeam(): void
    {
        $players = $this->get();

        foreach ($players as $player) {
            $options = ['home', 'away'];

            $this->playersTable->selectTeam($player['id'], $options[array_rand($options)]);
        }
    }

    public function vote(?callable $callback = null): void
    {
        $players = $this->get();

        foreach ($players as $player) {
            go(function () use ($player, $callback) {
                // Get round duration
                $roundDuration = (int) $_ENV['PHASE_DURATION_RUNNING'] ?? 60;

                // The user can only vote 1 time per second, so lets calculate
                // the maximum votes that the bot can do in the round duration
                $maxVotes = (int) ($roundDuration / 1);

                // Get a random number of votes between 1 and the max votes
                $votes = random_int(1, $maxVotes - 1);

                debugLog("[PlayerBotService] Bot {$player['name']} ({$player['id']}) vai votar {$votes} vezes.");

                for ($i = 0; $i < $votes; $i++) {
                    $totalMiliseconds = 1000;
                    $aMiliseconds = random_int(1, $totalMiliseconds);
                    $bMiliseconds = $totalMiliseconds - $aMiliseconds;

                    // Sleep for $a milliseconds with native sleep function php, even if is blocking
                    usleep($aMiliseconds * 1000);

                    // Send the vote to the server
                    $result = $this->playersTable->vote($player['id'], $player['currentTeam']);

                    if ($result) {
                        debugLog("[PlayerBotService] Bot {$player['name']} ({$player['id']}) votou.");

                        // Call the callback function
                        if (is_callable($callback)) {
                            $callback($player['currentTeam']);
                        }
                    } else {
                        debugLog("[PlayerBotService] Bot {$player['name']} ({$player['id']}) não conseguiu votar.");
                    }

                    // Sleep for 1 second
                    usleep($bMiliseconds * 1000);
                }
            });
        }
    }
}
