<?php
require_once 'limonade/lib/limonade.php';
require_once 'vendor/autoload.php';

function configure() {
  option('base_uri', '/');
  option('session', 'isu4_qualifier_session');

  $host = getenv('ISU4_DB_HOST') ?: 'localhost';
  $port = getenv('ISU4_DB_PORT') ?: 3306;
  $dbname = getenv('ISU4_DB_NAME') ?: 'isu4_qualifier';
  $username = getenv('ISU4_DB_USER') ?: 'root';
  $password = getenv('ISU4_DB_PASSWORD');
  $db = null;
  try {
    $db = new PDO(
      'mysql:host=' . $host . ';port=' . $port. ';dbname=' . $dbname,
      $username,
      $password,
      [ PDO::ATTR_PERSISTENT => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET `utf8`',
      ]
    );
  } catch (PDOException $e) {
    halt("Connection faild: $e");
  }
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  option('db_conn', $db);

  $config = [
    'user_lock_threshold' => getenv('ISU4_USER_LOCK_THRESHOLD') ?: 3,
    'ip_ban_threshold' => getenv('ISU4_IP_BAN_THRESHOLD') ?: 10
  ];
  option('config', $config);

  $redis = new Predis\Client();
  option('redis', $redis);
}

function uri_for($path) {
  $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?: $_SERVER['HTTP_HOST'];
  return 'http://' . $host . $path;
}

function get($key) {
  return set($key);
}

function before() {
  layout('base.html.php');
}

function calculate_password_hash($password, $salt) {
  return hash('sha256', $password . ':' . $salt);
}

function login_log($succeeded, $login, $user_id=null) {
  $db = option('db_conn');
  $redis = option('redis');

  if ($succeeded) {
      $redis->hset('LoginFailuresByLogin', $login, 0);
      $redis->hset('LoginFailuresByIp', $_SERVER['REMOTE_ADDR'], 0);

      $lastLogin = $redis->hget('LoginSuccessLog', $user_id);
      if ($lastLogin) {
          $_SESSION['last_login_log'] = unserialize($lastLogin);
      }
      $redis->hset(
          'LoginSuccessLog',
          $user_id,
          serialize([
              (new DateTime())->format('Y-m-d H:i:s'),
              $_SERVER['REMOTE_ADDR']
          ])
      );
  } else {
      $redis->hincrby('LoginFailuresByLogin', $login, 1);
      $redis->hincrby('LoginFailuresByIp', $_SERVER['REMOTE_ADDR'], 1);
  }

  $stmt = $db->prepare('INSERT INTO login_log (`created_at`, `user_id`, `login`, `ip`, `succeeded`) VALUES (NOW(),:user_id,:login,:ip,:succeeded)');
  $stmt->bindValue(':user_id', $user_id);
  $stmt->bindValue(':login', $login);
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
  $stmt->bindValue(':succeeded', $succeeded ? 1 : 0);
  $stmt->execute();
}

function user_locked($user) {
  // ユーザIDごとに最後のログイン成功からのログイン試行数が閾値を超えるか
  $redis = option('redis');
  $failureCount = $redis->hget('LoginFailuresByLogin', $user['login']);
  if (!$failureCount) {
    return false;
  }

  return $failureCount >= option('config')['user_lock_threshold'];
}

# FIXME
function ip_banned() {
  $redis = option('redis');
  $failureCount = $redis->hget('LoginFailuresByIp', $_SERVER['REMOTE_ADDR']);
  if (!$failureCount) {
    return false;
  }

  return $failureCount >= option('config')['ip_ban_threshold'];
}

function attempt_login($login, $password) {
  $db = option('db_conn');
  $redis = option('redis');

  $user = unserialize($redis->get('User:'.$login));
  if (empty($user)) {
    login_log(false, $login);
    return ['error' => 'wrong_login'];
  }

  if (ip_banned()) {
    login_log(false, $login, isset($user['id']) ? $user['id'] : null);
    return ['error' => 'banned'];
  }

  if (user_locked($user)) {
    login_log(false, $login, $user['id']);
    return ['error' => 'locked'];
  }

  if (calculate_password_hash($password, $user['salt']) == $user['password_hash']) {
    login_log(true, $login, $user['id']);
    return ['user' => $user];
  }
  else {
    login_log(false, $login, $user['id']);
    return ['error' => 'wrong_password'];
  }
}

function current_user() {
  return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function last_login($userId) {
  $log = isset($_SESSION['last_login_log'])
      ? $_SESSION['last_login_log']
      : [];
  if (!$log) {
      return [
          'created_at' => null,
          'ip'         => null
      ];
  }

  return [
    'created_at' => $log[0],
    'ip'         => $log[1],
  ];
}

function banned_ips() {
  $threshold = option('config')['ip_ban_threshold'];
  $ips = [];

  $redis = option('redis');
  $failures = $redis->hgetall('LoginFailuresByIp');

  foreach ($failures as $ip => $c) {
      if ($c >= $threshold) {
          $ips[] = $ip;
      }
  }

  return $ips;
}

function locked_users() {
  $threshold = option('config')['user_lock_threshold'];
  $user_ids = [];

  $redis = option('redis');
  $failures = $redis->hgetall('LoginFailuresByLogin');

  foreach ($failures as $l => $c) {
      if ($c >= $threshold) {
          $user_ids[] = $l;
      }
  }

  return $user_ids;
}

dispatch_get('/', function() {
  return html('index.html.php');
});

dispatch_post('/login', function() {
  $result = attempt_login($_POST['login'], $_POST['password']);
  if (!empty($result['user'])) {
    session_regenerate_id(true);
    $_SESSION['user'] = $result['user'];
    return redirect_to('/mypage');
  }
  else {
    switch($result['error']) {
      case 'locked':
        flash('notice', 'This account is locked.');
        break;
      case 'banned':
        flash('notice', "You're banned.");
        break;
      default:
        flash('notice', 'Wrong username or password');
        break;
    }
    return redirect_to('/');
  }
});

dispatch_get('/mypage', function() {
  $user = current_user();

  if (empty($user)) {
    flash('notice', 'You must be logged in');
    return redirect_to('/');
  }
  else {
    set('user', $user);
    set('last_login', last_login($user['id']));
    return html('mypage.html.php');
  }
});

dispatch_get('/report', function() {
  return json_encode([
    'banned_ips' => banned_ips(),
    'locked_users' => locked_users()
  ]);
});

run();
