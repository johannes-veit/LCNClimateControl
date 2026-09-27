<?php
declare(strict_types=1);

$html = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.html');
if ($html === false) {
    fwrite(STDERR, "module.html konnte nicht gelesen werden.\n");
    exit(1);
}

$required = [
    'let actionQueue = [];',
    'function queueAction(action)',
    'actionQueue.push(action);',
    'actionQueue.findIndex(item => item.ident === action.ident)',
    'scheduleQueuedAction();',
    'rollbackOptimisticAction',
    '.forEach(item => rollbackOptimisticAction(item))',
    'action.previousValue = previous.previousValue'
];

foreach ($required as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Frontend-Queue-Merkmal fehlt: $needle\n");
        exit(1);
    }
}

if (str_contains($html, 'let queuedAction = null')) {
    fwrite(STDERR, "Alte Ein-Slot-Queue ist wieder vorhanden.\n");
    exit(1);
}

echo "Frontend action queue OK\n";
