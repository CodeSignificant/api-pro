<?php
/**
 * ApiPro autoload
 * Always resolve runtime from PROJECT ROOT
 */

if (defined('APIPRO_AUTOLOADED')) return;
define('APIPRO_AUTOLOADED', true);
define('APIPRO_VERSION', '2.3.1');

$ROOT = getcwd();

/* Load project settings */
$settings = $ROOT . '/config.php';
if (file_exists($settings)) {
    require_once $settings;
}

/* Error handling */
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('error_log', $ROOT . '/prolog.log');

error_reporting(E_ALL);


/* Load ApiPro core classes (from vendor) */
$core = __DIR__;

require_once $core . '/Http/Attributes/Controller.php';
require_once $core . '/Http/Attributes/Get.php';
require_once $core . '/Http/Attributes/Post.php';
require_once $core . '/Http/Attributes/Put.php';
require_once $core . '/Http/Attributes/Patch.php';
require_once $core . '/Http/Attributes/Delete.php';
require_once $core . '/Http/Node.php';
require_once $core . '/Database/ProSql.php';
require_once $core . '/Database/ProRepository.php';
require_once $core . '/Security/Token.php';
require_once $core . '/Security/TokenRepository.php';
require_once $core . '/Security/TokenManager.php';
require_once $core . '/Security/Session.php';
require_once $core . '/Security/DataEncryption.php';

require_once $core . '/Http/ProNode.php';
require_once $core . '/Http/DataResponse.php';
require_once $core . '/Http/Log.php';
require_once $core . '/Cache/ProRedis.php';
require_once $core . '/Security/RateLimiter.php';
require_once $core . '/Security/ProLock.php';
require_once $core . '/Services/ProLogService.php';
require_once $core . '/Services/ProTestService.php';

// Internal Framework Services (only registered when LOG_ENABLED is true)
if (defined('LOG_ENABLED') && LOG_ENABLED === true) {
    $proLogService = ProNode::Service('/apipro/logs', new ProLogService());
    $proLogService->get('/viewer', 'viewer');
    $proLogService->post('/read', 'read');
    $proLogService->post('/clear', 'clear');

    // Backward compatibility alias for the old URL
    $aliasLogService = ProNode::Service('', new ProLogService());
    $aliasLogService->get('/logs.html', 'viewer');
}

if (defined('TESTER_ENABLED') && TESTER_ENABLED === true) {
    $proTestService = ProNode::Service('/apipro/tester', new ProTestService());
    $proTestService->get('/viewer', 'viewer');
    $proTestService->get('/routes', 'routes');
    
    // Alias for root access
    $aliasTestService = ProNode::Service('', new ProTestService());
    $aliasTestService->get('/test.html', 'viewer');
}

/* Auto-load Controllers */
spl_autoload_register(function ($class_name) use ($ROOT) {
    $controllerFile = $ROOT . '/lib/controller/' . $class_name . '.php';
    if (file_exists($controllerFile)) {
        require_once $controllerFile;
    }
});

