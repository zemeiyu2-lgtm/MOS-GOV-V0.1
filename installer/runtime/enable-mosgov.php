<?php
declare(strict_types=1);

$autoload = __DIR__ . '/../ChurchCRM/src/vendor/autoload.php';
$config = __DIR__ . '/../ChurchCRM/src/Include/Config.php';

if (!is_file($autoload) || !is_file($config)) {
    fwrite(STDERR, "ChurchCRM runtime files are incomplete.\n");
    exit(10);
}

require_once $autoload;
require_once $config;

\ChurchCRM\dto\SystemConfig::setValue('plugin.mos-gov.enabled', '1');

if (!\ChurchCRM\dto\SystemConfig::getBooleanValue('plugin.mos-gov.enabled')) {
    fwrite(STDERR, "MOS-GOV could not be enabled.\n");
    exit(11);
}

echo "MOS-GOV enabled.\n";
