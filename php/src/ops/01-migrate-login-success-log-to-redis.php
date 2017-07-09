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


$st = $mysql->prepare('
select l.user_id, l.ip, l.created_at
from login_log as l,
     (select user_id, created_at from login_log where succeeded = 1 group by user_id having created_at = max(created_at)) as t
where
    l.user_id = t.user_id
    and l.created_at = t.created_at
');
$st->execute();

foreach($st as $u) {
    $redis->hset('LoginSuccessLog', $u['user_id'], serialize([
        $u['created_at'],
        $u['ip'],
    ]));
}

echo 'done';
