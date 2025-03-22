<?php

declare(strict_types=1);

namespace App;

use App\Services\TeamService;
use App\Tables\HistoryTable;
use App\Tables\PlayersTable;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

final class MyServer
{
    private HistoryTable $historyTable;

    private PlayersTable $playersTable;

    private TeamService $teamService;

    private int $workerQuantity = 8;

    private const PHASE_DURATION_WAITING = 3;

    private const PHASE_DURATION_RUNNING = 3;

    private const PHASE_DURATION_FINISHED = 3;

    public function main(): void
    {
        $ws = new Server('0.0.0.0', 9502, SWOOLE_PROCESS);

        $ws->set([
            'hook_flags' => SWOOLE_HOOK_ALL,
            'worker_num' => $this->workerQuantity,
            'dispatch_mode' => SWOOLE_DISPATCH_FDMOD,
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

        $this->teamService = new TeamService();

        // Após criar a tabela $statsTable
        foreach ($this->teamService->getTeams() as $team) {
            $statsTable->set($team['name'], [
                'teamId' => $team['id'],
                'played' => 0,
                'won'    => 0,
            ]);
        }

        $ws->on('start', function (): void {
            debugLog("[Master] [Server] Started!");

            swoole_set_process_name("swoole-websocket: master");
        });

        $ws->on('ManagerStart', function (): void {
            debugLog("[Manager] [Server] Started!");

            swoole_set_process_name("swoole-websocket: manager");
        });

        $ws->on('WorkerStart', function (Server $server, int $workerId) use ($settingsTable, $statsTable): void {
            swoole_set_process_name("swoole-websocket: worker {$workerId}");

            if ($server->taskworker) {
                debugLog("[TaskWorker] {$workerId} Started!");

                return;
            }

            debugLog("[Worker {$workerId}] Started!");

            if ($workerId === 0) {
                go(function () use ($settingsTable, $statsTable, $server): void {

                    debugLog("[Worker {$server->worker_id}] [Gameloop] Starting!");
    
                    while (true) {
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Starting game state and setting to waiting.");
    
                        $teamHome = $this->teamService->findRandom();
                        $teamAway = $this->teamService->findRandom();
                        
                        while ($teamHome['id'] === $teamAway['id']) {
                            $teamAway = $this->teamService->findRandom();
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
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Sending message to everyone on worker 0.");

                        foreach ($server->connections as $fd) {
                            $server->push($fd, json_encode([
                                'history' => $this->historyTable->get(),
                                'game' => $settingsTable->get('game'),
                                'stats' => $this->getAllStats($statsTable),
                            ]));
                        }
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Waiting for " . self::PHASE_DURATION_WAITING . " seconds for the next phase.");
    
                        Coroutine::sleep(self::PHASE_DURATION_WAITING);
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Setting game state to running.");
    
                        $settingsTable->set('game', [
                            'status' => 'running',
                            'phaseStart' => time(),
                            'phaseDuration' => self::PHASE_DURATION_RUNNING,  
                        ]);
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Sending game state to clients.");

                        foreach ($server->connections as $fd) {
                            $server->push($fd, json_encode([
                                'history' => $this->historyTable->get(),
                                'game' => $settingsTable->get('game'),
                                'stats' => $this->getAllStats($statsTable),
                            ]));
                        }
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Waiting for " . self::PHASE_DURATION_RUNNING . " seconds for the next phase.");
    
                        Coroutine::sleep(self::PHASE_DURATION_RUNNING);
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Setting game state to finished.");
    
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
                        $winner = 'draw';

                        if ($homeVotes > $awayVotes) {
                            $statsHome['won']++;
                            $winner = $gameData['homeName'];
                        } elseif ($awayVotes > $homeVotes) {
                            $statsAway['won']++;
                            $winner = $gameData['awayName'];
                        } 
    
                        $statsTable->set($homeName, $statsHome);
                        $statsTable->set($awayName, $statsAway);
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Sending game state to clients.");

                        foreach ($server->connections as $fd) {
                            $server->push($fd, json_encode([
                                'history' => $this->historyTable->get(),
                                'game' => $settingsTable->get('game'),
                                'stats' => $this->getAllStats($statsTable),
                            ]));
                        }
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Waiting for " . self::PHASE_DURATION_FINISHED . " seconds for the next phase.");
                        
                        Coroutine::sleep(self::PHASE_DURATION_FINISHED);
    
                        debugLog("[Worker {$server->worker_id}] [Gameloop] Game finished. Starting game cleanup!");

                        $this->playersTable->givePrize($winner);
                        $this->playersTable->cleanUpAfterGame();

                        debugLog("[Worker {$server->worker_id}] [Gameloop] Game cleanup finished! Restarting...");
                    }
                });
            }
        });

        $ws->on('handshake', function (Request $request, Response $response) use ($ws): bool {
            $secWebSocketKey = $request->header['sec-websocket-key'];
            $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

            // At this stage if the socket request does not meet custom requirements, you can ->end() it here and return false...

            // Websocket handshake connection algorithm verification
            if (
                0 === preg_match($patten, $secWebSocketKey) 
                || 16 !== strlen(base64_decode($secWebSocketKey))
            ) {
                $response->end();
                return false;
            }

            $result = $this->playersTable->add(
                fd: $request->fd,
                userId: $request->server['path_info'],
            );

            if (! $result) {
                debugLog("[Worker {$ws->worker_id}] [Server] Player has connected but we are full or player is already connected: {$request->fd}");
                $response->end();
                return false;
            }

            $key = base64_encode(
                sha1("{$request->header['sec-websocket-key']}258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true)
            );

            $headers = [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $key,
                'Sec-WebSocket-Version' => '13',
            ];

            // WebSocket connection to 'ws://127.0.0.1:9501/'
            // Failed: Error during WebSocket handshake:
            // Response must not include 'Sec-WebSocket-Protocol' header if not present in request: websocket
            if(isset($request->header['sec-websocket-protocol'])) {
                $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
            }

            foreach($headers as $key => $val) {
                $response->header($key, $val);
            }

            $response->status(101);
            $response->end();

            return true;
        });

        // $ws->on('open', function (Server $server, Request $request): void {
        //     debugLog("[Worker {$server->worker_id}] [Server] Player has connected: {$request->server['path_info']} - {$request->fd}");

        //     $result = $this->playersTable->add(
        //         fd: $request->fd,
        //         userId: $request->server['path_info'],
        //     );

        //     if (! $result) {
        //         debugLog("[Worker {$server->worker_id}] [Server] Player has connected but we are full or player is already connected: {$request->fd}");
        //         $server->disconnect($request->fd);
        //     }
        // });

        $ws->on('message', function (Server $server, Frame $frame) use ($settingsTable, $statsTable): void {
            // debugLog("[Server] Received message: {$frame->data}");

            // $player = $this->playersTable->findByFd($frame->fd);

            // if ($player['connected'] === 0) {
            //     debugLog("[Worker {$server->worker_id}] [Server] Player is not connected: {$frame->fd}");
            //     return;
            // }

            if ($frame->data === 'send-state') {
                debugLog("[Worker {$server->worker_id}] [Server] The client request the state, so we are sending it: {$frame->fd}");

                $server->push($frame->fd, json_encode([
                    'history' => $this->historyTable->get(),
                    'game' => $settingsTable->get('game'),
                    'stats' => $this->getAllStats($statsTable),
                ]));

                return;
            }

            if ($frame->data === 'vote-home') {
                
                // Pega o estado atual do jogo
                $game = $settingsTable->get('game');
                // Vefica se o jogo está em andamento
                if ($game['status'] !== 'running') {
                    debugLog("[Worker {$server->worker_id}] [Server] O Jogador: {$frame->fd} tentou votar mas o jogo não está em andamento.");
                    return;
                }

                $result = $this->playersTable->vote($frame->fd);

                if (! $result) {
                    debugLog("[Worker {$server->worker_id}] [Server] Jogador {$frame->fd} tentou votar mas está em cooldown.");
                    return;
                }

                debugLog("[Worker {$server->worker_id}] [Server] O jogador {$frame->fd} votou no time de casa.");
            
                $settingsTable->incr('game', 'homeVotes');

                foreach ($server->connections as $fd) {
                    $server->push($fd, json_encode([
                        'history' => $this->historyTable->get(),
                        'game' => $settingsTable->get('game'),
                        'stats' => $this->getAllStats($statsTable),
                    ]));
                }

                return;
            }

            if ($frame->data === 'vote-away') {
                // Pega o estado atual do jogo
                $game = $settingsTable->get('game');

                // Vefica se o jogo está em andamento
                if ($game['status'] !== 'running') {
                    debugLog("[Worker {$server->worker_id}] [Server] O Jogador: {$frame->fd} tentou votar mas o jogo não está em andamento.");
                    return;
                }
                
                $result = $this->playersTable->vote($frame->fd);

                if (! $result) {
                    debugLog("[Worker {$server->worker_id}] [Server] Jogador {$frame->fd} tentou votar mas está em cooldown.");
                    return;
                }
            
                debugLog("[Worker {$server->worker_id}] [Server] O jogador {$frame->fd} votou no time de fora.");

                $settingsTable->incr('game', 'awayVotes');

                foreach ($server->connections as $fd) {
                    $server->push($fd, json_encode([
                        'history' => $this->historyTable->get(),
                        'game' => $settingsTable->get('game'),
                        'stats' => $this->getAllStats($statsTable),
                    ]));
                }

                return;
            }
        });

        $ws->on('close', function ($server, int $fd) use ($ws): void {
            debugLog("[Worker {$server->worker_id} - {$ws->worker_id}] [Server] Player has disconnected!!!: {$fd}");

            $this->playersTable->remove($fd);
        });

        $ws->start();
    }
    
    private function getAllStats(Table $statsTable): array
    {
        $stats = [];
        foreach ($this->teamService->getTeams() as $team) {
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
}
