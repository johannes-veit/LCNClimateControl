<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$required = [
    "private const GLOBAL_LCN_SEND_SEMAPHORE = 'LCN_BUS_SEND_GLOBAL';",
    'private const SEND_RETRY_MAX = 2;',
    'private const SEND_RETRY_DELAY_MS = 750;',
    'RetryNotBeforeMs',
    'SendFailures',
    "IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)",
    "IPS_FunctionExists('LCN_SendCommand')",
    'IsOperationalLCNModule'
];

foreach ($required as $needle) {
    if (!str_contains($php, $needle)) {
        fwrite(STDERR, "Sendestabilitätsmerkmal fehlt: $needle\n");
        exit(1);
    }
}

echo "Send retry and bus lock OK\n";
