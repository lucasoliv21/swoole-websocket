<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$server = new App\MyServer();
$server->main();
