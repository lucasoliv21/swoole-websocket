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

                    $settingsTable->set('game', [
                        'id' => uniqid(),
                        'status' => 'waiting',
                        'homeName' => 'Real Madrid',
                        'homeVotes' => 0,
                        'homeFlag' => '',
                        'awayName' => 'Barcelona',
                        'awayVotes' => 0,
                        'awayFlag' => '',
                        'createdAt' => time(),
                    ]);

                    $this->debugLog("[Gameloop] Sending game state to clients.");

                    foreach ($server->connections as $fd) {
                        $server->push($fd, json_encode($settingsTable->get('game')));
                    }

                    $this->debugLog("[Gameloop] Waiting for 3 seconds for the next phase.");

                    sleep(3);

                    $this->debugLog("[Gameloop] Setting game state to running.");

                    $settingsTable->set('game', ['status' => 'running']);

                    $this->debugLog("[Gameloop] Sending game state to clients.");

                    foreach ($server->connections as $fd) {
                        $server->push($fd, json_encode($settingsTable->get('game')));
                    }

                    $this->debugLog("[Gameloop] Waiting for 3 seconds for the next phase.");

                    sleep(3);

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

        $ws->on('message', function (Server $server, Frame $frame): void {
            echo "received message: {$frame->data}\n";
            $server->push($frame->fd, json_encode(["hello", "world"]));
        });

        $ws->on('close', function (Server $server, int $fd): void {
            echo "connection close: {$fd}\n";
            $this->debugLog("[Server] Connection close: {$fd}");
        });

        $ws->start();
    }

    private function debugLog(... $items): void
    {
        if (! $this->debugLog) {
            return;
        }

        foreach ($items as $item) {
            echo "[DEBUG] ";
            print_r($item);
            echo "\n";
        }
    }
}
