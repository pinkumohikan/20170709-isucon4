<?php

require_once __DIR__.'/../vendor/autoload.php';

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


$st = $mysql->prepare('select * from login_log order by created_at');
$st->execute();

foreach($st as $l) {
    if ($l['succeeded'] === '1') {
        $redis->hset('LoginFailuresByLogin', $l['login'], 0);
        $redis->hset('LoginFailuresByIp', $l['ip'], 0);
    } else {
        $redis->hincrby('LoginFailuresByLogin', $l['login'], 1);
        $redis->hincrby('LoginFailuresByIp', $l['ip'], 1);
    }
}

echo 'done';
