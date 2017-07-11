#!/bin/sh
set -x
set -e
cd $(dirname $0)

echo 'flushall' | redis-cli
sudo supervisorctl restart isucon_php

myuser=root
mydb=isu4_qualifier
myhost=127.0.0.1
myport=3306
mysql -h ${myhost} -P ${myport} -u ${myuser} -e "DROP DATABASE IF EXISTS ${mydb}; CREATE DATABASE ${mydb}"
mysql -h ${myhost} -P ${myport} -u ${myuser} ${mydb} < sql/schema.sql

mysql -h ${myhost} -P ${myport} -u ${myuser} ${mydb} < sql/dummy_users.sql
php72 /home/isucon/20170709-isucon4/php/src/ops/00-migrate-user-to-redis.php

mysql -h ${myhost} -P ${myport} -u ${myuser} ${mydb} < sql/dummy_log.sql
php72 /home/isucon/20170709-isucon4/php/src/ops/01-migrate-login-success-log-to-redis.php
php72 /home/isucon/20170709-isucon4/php/src/ops/02-migrate-login-failures-to-redis.php

curl localhost/prepare

rm -f /tmp/sess_*
