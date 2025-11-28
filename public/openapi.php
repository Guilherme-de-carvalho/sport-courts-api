<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenApi\Generator;


$scanDirs = [
    __DIR__ . '/../src/OpenApi.php',
];

$openapi = Generator::scan($scanDirs);

header('Content-Type: application/json; charset=utf-8');
echo $openapi->toJson();