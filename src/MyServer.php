<?php

declare(strict_types=1);

namespace App;

use Swoole\Http\Request;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class MyServer
{
    private bool $debugLog = true;

    public function main(): void
    {
        $ws = new Server('0.0.0.0', 9502);

        $ws->set([
            'hook_flags' => SWOOLE_HOOK_ALL,
        ]);

        $settingsTable = new Table(1024);
        $settingsTable->column('id', Table::TYPE_STRING, 64);
        $settingsTable->column('status', Table::TYPE_STRING, 64);
        $settingsTable->column('createdAt', Table::TYPE_INT);
        $settingsTable->column('homeName', Table::TYPE_STRING, 64);
        $settingsTable->column('homeVotes', Table::TYPE_INT);
        $settingsTable->column('homeFlag', Table::TYPE_STRING, 256);
        $settingsTable->column('awayName', Table::TYPE_STRING, 64);
        $settingsTable->column('awayVotes', Table::TYPE_INT);
        $settingsTable->column('awayFlag', Table::TYPE_STRING, 256);
        $settingsTable->create();

        $ws->on('start', function (Server $server) use ($settingsTable): void {
            $this->debugLog("[Server] Started!");

            go(function () use ($settingsTable, $server): void {

                $this->debugLog("[Gameloop] Starting!");

                while (true) {

                    $this->debugLog("[Gameloop] Starting game state and setting to waiting.");

                    $teamHome = $this->getRandomTeam();
                    $teamAway = $this->getRandomTeam();
                    
                    while ($teamHome['id'] === $teamAway['id']) {
                        $teamAway = $this->getRandomTeam();
                    }

                    $settingsTable->set('game', [
                        'id' => uniqid(),
                        'status' => 'waiting',
                        'homeName' => $teamHome['name'],
                        'homeVotes' => 0,
                        'homeFlag' => $teamHome['flag'],
                        'awayName' => $teamAway['name'],
                        'awayVotes' => 0,
                        'awayFlag' => $teamAway['flag'],
                        'createdAt' => time(),
                    ]);

                    $this->debugLog("[Gameloop] Sending game state to clients.");

                    foreach ($server->connections as $fd) {
                        $server->push($fd, json_encode($settingsTable->get('game')));
                    }

                    $this->debugLog("[Gameloop] Waiting for 6 seconds for the next phase.");

                    sleep(6);

                    $this->debugLog("[Gameloop] Setting game state to running.");

                    $settingsTable->set('game', ['status' => 'running']);

                    $this->debugLog("[Gameloop] Sending game state to clients.");

                    foreach ($server->connections as $fd) {
                        $server->push($fd, json_encode($settingsTable->get('game')));
                    }

                    $this->debugLog("[Gameloop] Waiting for 15 seconds for the next phase.");

                    sleep(10);

                    $this->debugLog("[Gameloop] Setting game state to finished.");

                    $settingsTable->set('game', ['status' => 'finished']);

                    $this->debugLog("[Gameloop] Sending game state to clients.");

                    foreach ($server->connections as $fd) {
                        $server->push($fd, json_encode($settingsTable->get('game')));
                    }

                    $this->debugLog("[Gameloop] Waiting for 3 seconds for the next phase.");
                    sleep(3);

                    $this->debugLog("[Gameloop] Game loop finished. Restarting...");
                }
            });
        });

        $ws->on('open', function (Server $server, Request $request): void {
            echo "connection open: {$request->fd}\n";
            $this->debugLog("[Server] Connection open: {$request->fd}");
        });

        $ws->on('message', function (Server $server, Frame $frame) use ($settingsTable): void {
            $this->debugLog("[Server] Received message: {$frame->data}");

            if ($frame->data === 'send-state') {
                $this->debugLog("[Server] The client request the state, so we are sending it: {$frame->fd}");

                $server->push($frame->fd, json_encode($settingsTable->get('game')));
                return;
            }

            if ($frame->data === 'vote-home') {
                $this->debugLog("[Server] The client voted for home team: {$frame->fd}");

                $settingsTable->incr('game', 'homeVotes');

                foreach ($server->connections as $fd) {
                    $server->push($fd, json_encode($settingsTable->get('game')));
                }

                return;
            }

            if ($frame->data === 'vote-away') {
                $this->debugLog("[Server] The client voted for away team: {$frame->fd}");

                $settingsTable->incr('game', 'awayVotes');
                
                foreach ($server->connections as $fd) {
                    $server->push($fd, json_encode($settingsTable->get('game')));
                }

                return;
            }
        });

        $ws->on('close', function (Server $server, int $fd): void {
            echo "connection close: {$fd}\n";
            $this->debugLog("[Server] Connection close: {$fd}");
        });

        $ws->start();
    }

    private function getRandomTeam(): array
    {
        $teams = $this->getTeams();
        $randomIndex = array_rand($teams);

        return $teams[$randomIndex];
    }

    private function getTeams(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Real Madrid',
                'flag' => 'https://img.sofascore.com/api/v1/team/2829/image',
            ],
            [
                'id' => 2,
                'name' => 'Barcelona',
                'flag' => 'https://img.sofascore.com/api/v1/team/2817/image',
            ],
            [
                'id' => 3,
                'name' => 'Liverpool',
                'flag' => 'https://img.sofascore.com/api/v1/team/44/image',
            ],
            [
                'id' => 4,
                'name' => 'Manchester City',
                'flag' => 'https://img.sofascore.com/api/v1/team/17/image',
            ],
            [
                'id' => 5,
                'name' => 'Bayern Munich',
                'flag' => 'https://img.sofascore.com/api/v1/team/2672/image',
            ],
            [
                'id' => 6,
                'name' => 'Paris Saint-Germain',
                'flag' => 'https://img.sofascore.com/api/v1/team/1644/image',
            ],
            [
                'id' => 7,
                'name' => 'Chelsea',
                'flag' => 'https://img.sofascore.com/api/v1/team/38/image',
            ],
            [
                'id' => 8,
                'name' => 'Borussia Dortmund',
                'flag' => 'https://img.sofascore.com/api/v1/team/2673/image',
            ],
            [
                'id' => 9,
                'name' => 'Atletico Madrid',
                'flag' => 'https://img.sofascore.com/api/v1/team/2836/image',
            ],
            [
                'id' => 10,
                'name' => 'Inter Milan',
                'flag' => 'https://img.sofascore.com/api/v1/team/2697/image',
            ]
        ];
    }

    private function debugLog(... $items): void
    {
        if (! $this->debugLog) {
            return;
        }

        foreach ($items as $item) {
            $date = date('Y-m-d H:i:s');

            echo "[{$date}] [DEBUG] ";
            print_r($item);
            echo "\n";
        }
    }
}
