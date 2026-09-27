<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$html = file_get_contents($root . '/LCNClimateControl/module.html');
$php = file_get_contents($root . '/LCNClimateControl/module.php');

if ($html === false || $php === false) {
    fwrite(STDERR, "Quelldateien konnten nicht gelesen werden.\n");
    exit(1);
}

$requiredHtml = [
    'function dispatchAction(action)',
    'function isActionAcknowledged(message, action)',
    'function applyMessage(message)',
    "message.type === 'temperature'",
    "message.type === 'row'",
    "message.type === 'meta'",
    'ui.modeHeat.disabled = false',
    'ui.modeCool.disabled = false'
];

foreach ($requiredHtml as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Interaktionsschutz fehlt: $needle\n");
        exit(1);
    }
}

$requiredPhp = [
    'PendingMode',
    'ApplyModeChange',
    'YieldCurrentJob',
    'DiscardCurrentJob',
    'PushVisualizationMeta',
    'PushVisualizationRow',
    'PushVisualizationTemperature'
];

foreach ($requiredPhp as $needle) {
    if (!str_contains($php, $needle)) {
        fwrite(STDERR, "Backend-Interaktionsschutz fehlt: $needle\n");
        exit(1);
    }
}

if (str_contains($php, "Betriebsart kann während eines laufenden LCN-Auftrags nicht gewechselt werden.")) {
    fwrite(STDERR, "Alter Busy-Abbruch ist wieder vorhanden.\n");
    exit(1);
}

echo "Visualization interaction regression OK\n";
