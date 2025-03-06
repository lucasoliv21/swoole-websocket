<?php

require __DIR__ . '/../vendor/autoload.php';

use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

// Create a WebSocket Server object and listen on 0.0.0.0:9502.
$ws = new Server('0.0.0.0', 9502);

$ws->on('start', function (Server $server): void { 
  	echo "Servidor WebSocket iniciado em ws://0.0.0.0:9502\n"; 
});

// Listen to the WebSocket connection open event.
$ws->on('Open', function (Server $server, Request $request): void {
    $server->push($request->fd, "hello, welcome\n");
});

// Listen to the WebSocket message event.
$ws->on('Message', function (Server $server, Frame $frame): void {
    echo "Message: {$frame->data}\n";

    $server->push($frame->fd, "server: {$frame->data}");

    foreach($server->connections as $fd)
    {
      	$server->push($fd, "hello world\n");
    }
});

// Listen to the WebSocket connection close event.
$ws->on('Close', function (Server $ws, int $fd): void {
    echo "client-{$fd} is closed\n";
});

$ws->start();
