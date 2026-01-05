<?php
/**
 * ApiPro autoload
 * Always resolve runtime from PROJECT ROOT
 */

if (defined('APIPRO_AUTOLOADED')) return;
define('APIPRO_AUTOLOADED', true);

$ROOT = getcwd();

/* Load project settings */
$settings = $ROOT . '/setting.properties.php';
if (file_exists($settings)) {
    require_once $settings;
}

/* Error handling */
ini_set('log_errors', 1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('error_log', $ROOT . '/error.log');

/* Load ApiPro core classes (from vendor) */
$core = __DIR__ . '/Core';

require_once $core . '/Node.php';
require_once $core . '/Token.php';
require_once $core . '/ProSql.php';
require_once $core . '/ProNode.php';
require_once $core . '/DataResponse.php';
