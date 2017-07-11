<?php

require_once __DIR__.'/vendor/autoload.php';

$redis = new \Predis\Client('unix:/var/run/redis/redis.sock');

$file = fopen('/tmp/login.log', 'r+');
ftruncate($file, 0);

echo 'ready'.PHP_EOL;

while (true) {
    $input = stream_get_line($file, 99999);
    if (empty($input)) {
        usleep(1000);
        continue;
    }

    list($succeeded, $login, $user_id, $ip) = explode(',', $input);
      if ($succeeded) {
      $redis->hset('LoginFailuresByLogin', $login, 0);
      $redis->hset('LoginFailuresByIp', $ip, 0);

      $redis->hset(
          'LoginSuccessLog',
          $user_id,
          serialize([
              (new DateTime())->format('Y-m-d H:i:s'),
              $ip
          ])
      );
    } else {
      $redis->hincrby('LoginFailuresByLogin', $login, 1);
      $redis->hincrby('LoginFailuresByIp', $ip, 1);
    }
}
