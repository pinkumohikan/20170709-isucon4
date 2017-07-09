<?php

require_once '../vendor/autoload.php';

$redis = new \Predis\Client();
$mysql = new \PDO(
    'mysql:host=localhost;dbname=isu4_qualifier',
    'root',
    '',
    [
        PDO::ATTR_PERSISTENT => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET `utf8`',
    ]
);
$mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


$st = $mysql->prepare('select * from users');
$st->execute();

foreach($st as $u) {
    $key = 'User:'.$u['login'];
    $redis->set($key, serialize($u));
}

echo 'done';
