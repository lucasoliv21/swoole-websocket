<?php

declare(strict_types=1);

namespace App;

use App\Tables\HistoryTable;
use App\Tables\PlayersTable;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class MyServer
{
    private bool $debugLog = true;

    private HistoryTable $historyTable;

    private PlayersTable $playersTable;

    private int $workerQuantity = 1;

    private const PHASE_DURATION_WAITING = 3;

    private const PHASE_DURATION_RUNNING = 10;

    private const PHASE_DURATION_FINISHED = 3;

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

        $this->historyTable = new HistoryTable();

        $this->playersTable = new PlayersTable();

        // xdebug_break();

        // Após criar a tabela $statsTable
        foreach ($this->getTeams() as $team) {
            $statsTable->set($team['name'], [
                'teamId' => $team['id'],
                'played' => 0,
                'won'    => 0,
            ]);
        }

        $ws->on('start', function (Server $server) use ($settingsTable, $statsTable): void {
            $this->debugLog("[Worker {$server->worker_id}] [Server] Started!");
        });

        $ws->on('WorkerStart', function (Server $server, int $workerId) use ($settingsTable, $statsTable): void {
            // $this->debugLog("[Worker] {$workerId} Started!");

            if ($server->taskworker) {
                $this->debugLog("[TaskWorker] {$workerId} Started!");

                return;
            }

            $this->debugLog("[Worker {$workerId}] Started!");

            if ($workerId === 0) {
                go(function () use ($settingsTable, $statsTable, $server): void {

                    $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Starting!");
    
                    while (true) {
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Starting game state and setting to waiting.");
    
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
                            'phaseDuration' => self::PHASE_DURATION_WAITING,
                            'createdAt' => time(),
                        ]);
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Sending message to everyone on worker 0.");

                        $dataToSend = [
                            'history' => $this->historyTable->get(),
                            'game' => $settingsTable->get('game'),
                            'stats' => $this->getAllStats($statsTable),
                        ];

                        for ($i = 0; $i < $this->workerQuantity; $i++) {
                            if ($i === $server->worker_id) {
                                continue;
                            }

                            $server->sendMessage(json_encode($dataToSend), $i);
                        }

                        foreach ($server->connections as $fd) {
                            $dataToSend = [
                                'history' => $this->historyTable->get(),
                                'game' => $settingsTable->get('game'),
                                'stats' => $this->getAllStats($statsTable),
                            ];

                            $server->push($fd, json_encode($dataToSend));
                        }
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Waiting for " . self::PHASE_DURATION_WAITING . " seconds for the next phase.");
    
                        sleep(self::PHASE_DURATION_WAITING);
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Setting game state to running.");
    
                        $settingsTable->set('game', [
                            'status' => 'running',
                            'phaseStart' => time(),
                            'phaseDuration' => self::PHASE_DURATION_RUNNING,  
                        ]);
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Sending game state to clients.");
    
                        $dataToSend = [
                            'history' => $this->historyTable->get(),
                            'game' => $settingsTable->get('game'),
                            'stats' => $this->getAllStats($statsTable),
                        ];

                        for ($i = 0; $i < $this->workerQuantity; $i++) {
                            if ($i === $server->worker_id) {
                                continue;
                            }

                            $server->sendMessage(json_encode($dataToSend), $i);
                        }

                        foreach ($server->connections as $fd) {
                            $dataToSend = [
                                'history' => $this->historyTable->get(),
                                'game' => $settingsTable->get('game'),
                                'stats' => $this->getAllStats($statsTable),
                            ];

                            $server->push($fd, json_encode($dataToSend));
                        }
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Waiting for " . self::PHASE_DURATION_RUNNING . " seconds for the next phase.");
    
                        sleep(self::PHASE_DURATION_RUNNING);
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Setting game state to finished.");
    
                        $settingsTable->set('game', [
                            'status' => 'finished',
                            'phaseStart' => time(),
                            'phaseDuration' => self::PHASE_DURATION_FINISHED,
                        ]);
    
                        $this->historyTable->add($settingsTable->get('game'));
                        
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
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Sending game state to clients.");
    
                        $dataToSend = [
                            'history' => $this->historyTable->get(),
                            'game' => $settingsTable->get('game'),
                            'stats' => $this->getAllStats($statsTable),
                        ];

                        for ($i = 0; $i < $this->workerQuantity; $i++) {
                            if ($i === $server->worker_id) {
                                continue;
                            }

                            $server->sendMessage(json_encode($dataToSend), $i);
                        }

                        foreach ($server->connections as $fd) {
                            $dataToSend = [
                                'history' => $this->historyTable->get(),
                                'game' => $settingsTable->get('game'),
                                'stats' => $this->getAllStats($statsTable),
                            ];

                            $server->push($fd, json_encode($dataToSend));
                        }
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Waiting for " . self::PHASE_DURATION_FINISHED . " seconds for the next phase.");
                        sleep(self::PHASE_DURATION_FINISHED);
    
                        $this->debugLog("[Worker {$server->worker_id}] [Gameloop] Game loop finished. Restarting...");
                    }
                });
            }
        });

        $ws->on('pipeMessage', function (Server $server, int $srcWorkerId, mixed $message): void {
            $this->debugLog("[Worker {$server->worker_id}] [Server] " . count($server->connections) . " Sending message to all (except {$srcWorkerId})");

            foreach ($server->connections as $fd) {
                var_dump('dumper', $fd);
                $server->push($fd, $message);
            }
        });

        // $ws->on('handshake', function (Request $request, Response $response) use ($ws): bool {
        //     $secWebSocketKey = $request->header['sec-websocket-key'];
        //     $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

        //     // At this stage if the socket request does not meet custom requirements, you can ->end() it here and return false...

        //     // Websocket handshake connection algorithm verification
        //     if (
        //         0 === preg_match($patten, $secWebSocketKey) 
        //         || 16 !== strlen(base64_decode($secWebSocketKey))
        //     ) {
        //         $response->end();
        //         return false;
        //     }

        //     $result = $this->playersTable->add(
        //         fd: $request->fd,
        //         userId: $request->server['path_info'],
        //     );

        //     if (! $result) {
        //         $this->debugLog("[Worker {$ws->worker_id}] [Server] Player has connected but we are full or player is already connected: {$request->fd}");
        //         $response->end();
        //         return false;
        //     }

        //     $key = base64_encode(
        //         sha1("{$request->header['sec-websocket-key']}258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true)
        //     );

        //     $headers = [
        //         'Upgrade' => 'websocket',
        //         'Connection' => 'Upgrade',
        //         'Sec-WebSocket-Accept' => $key,
        //         'Sec-WebSocket-Version' => '13',
        //     ];

        //     // WebSocket connection to 'ws://127.0.0.1:9501/'
        //     // Failed: Error during WebSocket handshake:
        //     // Response must not include 'Sec-WebSocket-Protocol' header if not present in request: websocket
        //     if(isset($request->header['sec-websocket-protocol'])) {
        //         $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
        //     }

        //     foreach($headers as $key => $val) {
        //         $response->header($key, $val);
        //     }

        //     $response->status(101);
        //     $response->end();

        //     return true;
        // });

        $ws->on('open', function (Server $server, Request $request): void {
            echo "Open: " . time() . "\n";
            $this->debugLog("[Worker {$server->worker_id}] [Server] Player has connected: {$request->server['path_info']} - {$request->fd}");

            $result = $this->playersTable->add(
                fd: $request->fd,
                userId: $request->server['path_info'],
            );

            if (! $result) {
                $this->debugLog("[Worker {$server->worker_id}] [Server] Player has connected but we are full or player is already connected: {$request->fd}");
                // $server->close($request->fd);
            }
        });

        $ws->on('message', function (Server $server, Frame $frame) use ($settingsTable, $statsTable): void {
            // $this->debugLog("[Server] Received message: {$frame->data}");

            // $player = $this->playersTable->findByFd($frame->fd);

            // if ($player['connected'] === 0) {
            //     $this->debugLog("[Worker {$server->worker_id}] [Server] Player is not connected: {$frame->fd}");
            //     return;
            // }

            if ($frame->data === 'send-state') {
                $this->debugLog("[Worker {$server->worker_id}] [Server] The client request the state, so we are sending it: {$frame->fd}");

                $dataToSend = [
                    'history' => $this->historyTable->get(),
                    'game' => $settingsTable->get('game'),
                    'stats' => $this->getAllStats($statsTable),
                ];
                $server->push($frame->fd, json_encode($dataToSend));
                return;
            }

            if ($frame->data === 'vote-home') {
                
                // Pega o estado atual do jogo
                $game = $settingsTable->get('game');
                // Vefica se o jogo está em andamento
                if ($game['status'] !== 'running') {
                    $this->debugLog("[Worker {$server->worker_id}] [Server] O Jogador: {$frame->fd} tentou votar mas o jogo não está em andamento.");
                    return;
                }

                $result = $this->playersTable->vote($frame->fd);

                if (! $result) {
                    $this->debugLog("[Worker {$server->worker_id}] [Server] Jogador {$frame->fd} tentou votar mas está em cooldown.");
                    return;
                }

                $this->debugLog("[Worker {$server->worker_id}] [Server] O jogador {$frame->fd} votou no time de casa.");
            
                $settingsTable->incr('game', 'homeVotes');

                $dataToSend = [
                    'history' => $this->historyTable->get(),
                    'game' => $settingsTable->get('game'),
                    'stats' => $this->getAllStats($statsTable),
                ];

                for ($i = 0; $i < $this->workerQuantity; $i++) {
                    if ($i === $server->worker_id) {
                        continue;
                    }

                    $server->sendMessage(json_encode($dataToSend), $i);
                }

                foreach ($server->connections as $fd) {
                    $server->push($fd, json_encode($dataToSend));
                }

                return;
            }

            if ($frame->data === 'vote-away') {
                // Pega o estado atual do jogo
                $game = $settingsTable->get('game');

                // Vefica se o jogo está em andamento
                if ($game['status'] !== 'running') {
                    $this->debugLog("[Worker {$server->worker_id}] [Server] O Jogador: {$frame->fd} tentou votar mas o jogo não está em andamento.");
                    return;
                }
                
                $result = $this->playersTable->vote($frame->fd);

                if (! $result) {
                    $this->debugLog("[Worker {$server->worker_id}] [Server] Jogador {$frame->fd} tentou votar mas está em cooldown.");
                    return;
                }
            
                $this->debugLog("[Worker {$server->worker_id}] [Server] O jogador {$frame->fd} votou no time de fora.");

                $settingsTable->incr('game', 'awayVotes');
                
                $dataToSend = [
                    'history' => $this->historyTable->get(),
                    'game' => $settingsTable->get('game'),
                    'stats' => $this->getAllStats($statsTable),
                ];

                for ($i = 0; $i < $this->workerQuantity; $i++) {
                    if ($i === $server->worker_id) {
                        continue;
                    }

                    $server->sendMessage(json_encode($dataToSend), $i);
                }

                foreach ($server->connections as $fd) {
                    $server->push($fd, json_encode($dataToSend));
                }

                return;
            }
        });

        $ws->on('close', function ($server, int $fd) use ($ws): void {
            $this->debugLog("[Worker {$server->worker_id} - {$ws->worker_id}] [Server] Player has disconnected: {$fd}");

            $this->playersTable->remove($fd);
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
