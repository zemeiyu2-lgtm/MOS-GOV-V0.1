<?php

/**
 * MOS-GOV V0.1 — Phase 2 integration test (temporary diagnostic).
 *
 * Uses the full ChurchCRM bootstrap (same pattern as cli/timerjobs.php).
 * Exercises the OFFICIAL PluginManager lifecycle only — no shortcuts.
 *
 * Run: docker exec docker-webserver-1 php /var/www/html/plugins/community/mos-gov/tests/integration_phase2.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../../../Include/LoadConfigs.php';

use ChurchCRM\dto\SystemURLs;
use ChurchCRM\Plugin\PluginManager;
use ChurchCRM\Utils\LoggerUtils;
use ChurchCRM\Utils\VersionUtils;
use Propel\Runtime\Propel;
use Slim\Factory\AppFactory;

echo "ChurchCRM version: " . VersionUtils::getInstalledVersion() . PHP_EOL;
echo "MariaDB version: " . Propel::getConnection()->query('SELECT VERSION()')->fetchColumn() . PHP_EOL;

PluginManager::init(SystemURLs::getDocumentRoot() . '/plugins');

$pluginId = 'mos-gov';

function section(string $name): void
{
    echo PHP_EOL . "=== {$name} ===" . PHP_EOL;
}

// ---------------------------------------------------------------- status
section('1. STATUS before enable');
echo 'discovered: ' . (PluginManager::getPluginMetadata($pluginId) !== null ? 'yes' : 'no') . PHP_EOL;
echo 'isActive: ' . var_export(PluginManager::isPluginActive($pluginId), true) . PHP_EOL;
echo 'quarantined: ' . var_export(PluginManager::isPluginQuarantined($pluginId), true) . PHP_EOL;
echo 'quarantineReason: ' . var_export(PluginManager::getQuarantineReason($pluginId), true) . PHP_EOL;
$verification = PluginManager::getVerificationStatus($pluginId);
echo 'verified: ' . var_export($verification['verified'], true) . ' (' . $verification['source'] . ') ' . ($verification['reason'] ?? '') . PHP_EOL;

// ---------------------------------------------------------------- enable
section('2. enablePlugin (official PluginManager path)');
try {
    $result = PluginManager::enablePlugin($pluginId);
    echo 'enablePlugin returned: ' . var_export($result, true) . PHP_EOL;
} catch (\Throwable $e) {
    echo 'ENABLE FAILED: ' . get_class($e) . PHP_EOL;
    echo 'message: ' . $e->getMessage() . PHP_EOL;
}

section('3. STATUS after enable');
echo 'isActive: ' . var_export(PluginManager::isPluginActive($pluginId), true) . PHP_EOL;
echo 'quarantined: ' . var_export(PluginManager::isPluginQuarantined($pluginId), true) . PHP_EOL;
$plugin = PluginManager::getPlugin($pluginId);
echo 'instance loaded: ' . ($plugin !== null ? 'yes (' . get_class($plugin) . ')' : 'NO') . PHP_EOL;
if ($plugin !== null) {
    // boot() is invoked by loadPlugin(); confirm the instance is functional.
    echo 'boot() already ran via loadPlugin; getId(): ' . $plugin->getId() . PHP_EOL;
    echo 'isEnabled(): ' . var_export($plugin->isEnabled(), true) . PHP_EOL;
    echo 'isConfigured(): ' . var_export($plugin->isConfigured(), true) . PHP_EOL;
}

// ---------------------------------------------------------------- routes
section('4. registerPluginRoutes into a test Slim app');
try {
    $app = AppFactory::create();
    PluginManager::registerPluginRoutes($app);
    $routes = $app->getRouteCollector()->getRoutes();
    echo 'routes registered by active plugins: ' . count($routes) . PHP_EOL;
    foreach ($routes as $route) {
        echo '  ' . implode('|', $route->getMethods()) . ' ' . $route->getPattern() . PHP_EOL;
    }
} catch (\Throwable $e) {
    echo 'ROUTE REGISTRATION ERROR: ' . get_class($e) . PHP_EOL;
    echo 'message: ' . $e->getMessage() . PHP_EOL;
}

// ---------------------------------------------------------------- schema
section('5. gov_* tables currently in database');
$conn = Propel::getConnection();
$stmt = $conn->query("SHOW TABLES LIKE 'gov_%'");
$rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
echo count($rows) === 0 ? "(none)" : implode(', ', $rows) . PHP_EOL;

echo PHP_EOL . "PHASE2 DONE" . PHP_EOL;
