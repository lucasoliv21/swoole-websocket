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
            echo "server started\n";

            go(function () use ($settingsTable, $server): void {

                while (true) {
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

                    foreach ($server->connections as $fd) {
                        $server->push($fd, json_encode($settingsTable->get('game')));
                    }

                    sleep(3);

                    // echo "Server rolled \n";

                    $settingsTable->set('game', ['status' => 'running']);

                    foreach ($server->connections as $fd) {
                        $server->push($fd, json_encode($settingsTable->get('game')));
                    }

                    sleep(3);

                    // echo "Server finished \n";

                    $settingsTable->set('game', ['status' => 'finished']);

                    foreach ($server->connections as $fd) {
                        $server->push($fd, json_encode($settingsTable->get('game')));
                    }

                    sleep(3);
                }
            });
        });

        $ws->on('open', function (Server $server, Request $request): void {
            echo "connection open: {$request->fd}\n";
        });

        $ws->on('message', function (Server $server, Frame $frame): void {
            echo "received message: {$frame->data}\n";
            $server->push($frame->fd, json_encode(["hello", "world"]));
        });

        $ws->on('close', function (Server $server, int $fd): void {
            echo "connection close: {$fd}\n";
        });

        $ws->start();
    }
}
