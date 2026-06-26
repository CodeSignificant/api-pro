<?php

define("VERSION", "1.0.0");
define("SERVER_ENC", "apipro@4ss");
define("DATA_ENC", "");


define('DB_HOST', "localhost");
define('DB_NAME', "xxxxxxxx");
define('DB_USER', "xxxxxxxxxx");
define('DB_PASSWORD', "xxxxxxxx");

define('MAILER', 'support@4ss.in');

// Redis Configuration
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PASS', null);
define('REDIS_DB', 0);

define('LOG_ENABLED', true);
define('TESTER_ENABLED', true);
define('LOG_VIEWER_PASSWORD', 'admin123');

define('TOKEN_DRIVER', 'redis');                 
define('TOKEN_MULTIPLE_DEVICE_LOGIN', true);
define('TOKEN_MAX_DEVICES', 5);
define('TOKEN_ALLOW_CONCURRENT', true);
define('SESSION_TIME', 1800);
define('DB_WRITE', 'update');                     // Global DB schema sync mode: 'create', 'force', 'update', or false to disable

// Allowed CORS Origins (comma-separated list of domains)
define("CORS", "http://localhost:3000, http://127.0.0.1:3000, https://crm.4ss.in, https://crm.doland.in");
