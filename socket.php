<?php
require('vendor/autoload.php');

use WebSocket\Client;

$client = new Client("ws://localhost:9222/devtools/page/8317cd68-f29f-44f0-a1bd-ea0fd5b825aa");

while ($line = fgets(STDIN)) {
    $client->send($line);
    var_dump($client->receive());
}
