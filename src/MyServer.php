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

    private array $historyTable = [];

    private array $players = [];

    private int $workerQuantity = 8;

    private function getHistory(): array
    {
        return $this->historyTable;
    }

    private function addHistory(array $game): void
    {
        if ($game['status'] !== 'finished') {
            $this->debugLog("[History] Game is not finished, so we are not adding to history.");
            return;
        }

        if ($game['homeVotes'] === $game['awayVotes']) {
            $this->debugLog("[History] Game is a draw, so we are not adding to history.");
            return;
        }

        if (count($this->historyTable) < 20) {
            array_unshift($this->historyTable, $game);
        } else {
            array_unshift($this->historyTable, $game);
            array_pop($this->historyTable);
        }
    }

    public function main(): void
    {
        $ws = new Server('0.0.0.0', 9502);

        $ws->set([
            'hook_flags' => SWOOLE_HOOK_ALL,
            'worker_num' => $this->workerQuantity,
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
        $settingsTable->column('phaseStart', Table::TYPE_INT);
        $settingsTable->column('phaseDuration', Table::TYPE_INT);
        $settingsTable->create();
        
        $statsTable = new Table(1024);
        $statsTable->column('teamId', Table::TYPE_INT);
        $statsTable->column('played', Table::TYPE_INT);
        $statsTable->column('won', Table::TYPE_INT);
        $statsTable->create();

        
        // Após criar a tabela $statsTable
        foreach ($this->getTeams() as $team) {
            $statsTable->set($team['name'], [
                'teamId' => $team['id'],
                'played' => 0,
                'won'    => 0,
            ]);
        }

        $ws->on('start', function (Server $server) use ($settingsTable, $statsTable): void {
            $this->debugLog("[Server] Started!");
        });

        $ws->on('WorkerStart', function (Server $server, int $workerId) use ($settingsTable, $statsTable): void {
            // $this->debugLog("[Worker] {$workerId} Started!");

            if ($server->taskworker) {
                $this->debugLog("[TaskWorker] {$workerId} Started!");

                return;
            }

            $this->debugLog("[Worker] {$workerId} Started!");

            if ($workerId === 0) {
                go(function () use ($settingsTable, $statsTable, $server): void {

                    $this->debugLog("[Gameloop] Starting!");
    
                    while (true) {
    
                        $this->debugLog("[Gameloop] [Worker {$server->worker_id}] Starting game state and setting to waiting.");
    
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
                            'phaseStart' => time(),
                            'phaseDuration' => 6,
                            'createdAt' => time(),
                        ]);
    
                        $this->debugLog("[Gameloop] [Worker {$server->worker_id}] Sending message to everyone on worker 0.");

                        $dataToSend = [
                            'history' => $this->getHistory(),
                            'game' => $settingsTable->get('game'),
                            'stats' => $this->getAllStats($statsTable),
                        ];

                        for ($i = 0; $i < $this->workerQuantity; $i++) {
                            if ($i === $server->worker_id) {
                                continue;
                            }

                            $server->sendMessage(json_encode($dataToSend), $i);
                        }

                        foreach ($this->players as $player) {
                            $dataToSend = [
                                'history' => $this->getHistory(),
                                'game' => $settingsTable->get('game'),
                                'stats' => $this->getAllStats($statsTable),
                            ];

                            $server->push($player['fd'], json_encode($dataToSend));
                        }
    
                        $this->debugLog("[Gameloop] Waiting for 6 seconds for the next phase.");
    
                        sleep(6);
    
                        $this->debugLog("[Gameloop] Setting game state to running.");
    
                        $settingsTable->set('game', [
                            'status' => 'running',
                            'phaseStart' => time(),
                            'phaseDuration' => 10,  
                        ]);
    
                        $this->debugLog("[Gameloop] Sending game state to clients.");
    
                        $dataToSend = [
                            'history' => $this->getHistory(),
                            'game' => $settingsTable->get('game'),
                            'stats' => $this->getAllStats($statsTable),
                        ];

                        for ($i = 0; $i < $this->workerQuantity; $i++) {
                            if ($i === $server->worker_id) {
                                continue;
                            }

                            $server->sendMessage(json_encode($dataToSend), $i);
                        }

                        foreach ($this->players as $player) {
                            $dataToSend = [
                                'history' => $this->getHistory(),
                                'game' => $settingsTable->get('game'),
                                'stats' => $this->getAllStats($statsTable),
                            ];

                            $server->push($player['fd'], json_encode($dataToSend));
                        }
    
                        $this->debugLog("[Gameloop] Waiting for 15 seconds for the next phase.");
    
                        sleep(10);
    
                        $this->debugLog("[Gameloop] Setting game state to finished.");
    
                        $settingsTable->set('game', [
                            'status' => 'finished',
                            'phaseStart' => time(),
                            'phaseDuration' => 3,
                        ]);
    
                        $this->addHistory($settingsTable->get('game'));
                        
                        $gameData = $settingsTable->get('game');
                        $homeName = $gameData['homeName'];
                        $awayName = $gameData['awayName'];
                        $homeVotes = $gameData['homeVotes'];
                        $awayVotes = $gameData['awayVotes'];
                        
                        $statsHome = $statsTable->get($homeName);
                        $statsAway = $statsTable->get($awayName);
                        
                        $statsHome['played']++;
                        $statsAway['played']++;
    
                        // 4) Descobrimos quem ganhou
                        if ($homeVotes > $awayVotes) {
                            $statsHome['won']++;
                        } elseif ($awayVotes > $homeVotes) {
                            $statsAway['won']++;
                        } 
    
                        $statsTable->set($homeName, $statsHome);
                        $statsTable->set($awayName, $statsAway);
    
                        $this->debugLog("[Gameloop] Sending game state to clients.");
    
                        $dataToSend = [
                            'history' => $this->getHistory(),
                            'game' => $settingsTable->get('game'),
                            'stats' => $this->getAllStats($statsTable),
                        ];

                        for ($i = 0; $i < $this->workerQuantity; $i++) {
                            if ($i === $server->worker_id) {
                                continue;
                            }

                            $server->sendMessage(json_encode($dataToSend), $i);
                        }

                        foreach ($this->players as $player) {
                            $dataToSend = [
                                'history' => $this->getHistory(),
                                'game' => $settingsTable->get('game'),
                                'stats' => $this->getAllStats($statsTable),
                            ];

                            $server->push($player['fd'], json_encode($dataToSend));
                        }
    
                        $this->debugLog("[Gameloop] Waiting for 3 seconds for the next phase.");
                        sleep(3);
    
                        $this->debugLog("[Gameloop] Game loop finished. Restarting...");
                    }
                });
            }
        });

        $ws->on('pipeMessage', function (Server $server, int $srcWorkerId, mixed $message): void {
            $this->debugLog("[Server] [Worker {$server->worker_id}] Sending message to all (except {$srcWorkerId})");

            foreach ($server->connections as $fd) {
                $server->push($fd, $message);
            }
        });

        $ws->on('open', function (Server $server, Request $request): void {
            $this->debugLog("[Server] [Worker {$server->worker_id}] Player has connected: {$request->fd}");

            $this->players[$request->fd] = [
                'fd' => $request->fd,
                'name' => "Player {$request->fd}",
            ];
        });

        $ws->on('message', function (Server $server, Frame $frame) use ($settingsTable, $statsTable): void {
            $this->debugLog("[Server] Received message: {$frame->data}");

            if ($frame->data === 'send-state') {
                $this->debugLog("[Server] The client request the state, so we are sending it: {$frame->fd}");

                $dataToSend = [
                    'history' => $this->getHistory(),
                    'game' => $settingsTable->get('game'),
                    'stats' => $this->getAllStats($statsTable),
                ];
                $server->push($frame->fd, json_encode($dataToSend));
                return;
            }

            if ($frame->data === 'vote-home') {
                $this->debugLog("[Server] The client voted for home team: {$frame->fd}");

                $settingsTable->incr('game', 'homeVotes');

                foreach ($server->connections as $fd) {
                    $dataToSend = [
                        'history' => $this->getHistory(),
                        'game' => $settingsTable->get('game'),
                        'stats' => $this->getAllStats($statsTable),
                    ];
                    $server->push($fd, json_encode($dataToSend));
                }

                return;
            }

            if ($frame->data === 'vote-away') {
                $this->debugLog("[Server] The client voted for away team: {$frame->fd}");

                $settingsTable->incr('game', 'awayVotes');
                
                foreach ($server->connections as $fd) {
                    $dataToSend = [
                        'history' => $this->getHistory(),
                        'game' => $settingsTable->get('game'),
                        'stats' => $this->getAllStats($statsTable),
                    ];
                    $server->push($fd, json_encode($dataToSend));
                }

                return;
            }
        });

        $ws->on('close', function ($server, int $fd): void {
            $this->debugLog("[Server] [Worker {$server->worker_id}] Player has disconnected: {$fd}");
            
            unset($this->players[$fd]);
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
    
    private function getAllStats(Table $statsTable): array
    {
        $stats = [];
        foreach ($this->getTeams() as $team) {
            $key = (string)$team['name'];  // isso aqui vai ser interessante pegar pelo id ne?
            $row = $statsTable->get($key);
            
            if (!$row) {
                $stats[$key] = [
                    'played' => 0,
                    'won' => 0,
                    'winRate' => 0,
                ];
                continue;
            }
            
            $played = $row['played'];
            $won = $row['won'];
            $winRate = $played > 0 ? ($won / $played) : 0;
            
            $stats[$key] = [
                'played' => $played,
                'won' => $won,
                'winRate' => $winRate,
            ];
        }
        return $stats;
    }

    private function debugLog($item): void
    {
        if (! $this->debugLog) {
            return;
        }

        // if can be print out as string
        if (is_scalar($item)) {
            $date = date('Y-m-d H:i:s');
            echo "[{$date}] [DEBUG] {$item}\n";
            return;
        } else {
            var_dump($item);
        }
    }
}
