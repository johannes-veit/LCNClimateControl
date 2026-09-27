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
    'function patchUI()',
    'function patchHeatRow(row)',
    'function patchCoolRow(row)',
    'if (renderedMode !== nextMode || renderedSignature !== nextSignature)',
];

foreach ($requiredHtml as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Performance-Merkmal fehlt in module.html: $needle\n");
        exit(1);
    }
}

if (substr_count($html, 'root.replaceChildren();') !== 1) {
    fwrite(STDERR, "DOM darf nur beim Struktur-Neuaufbau ersetzt werden.\n");
    exit(1);
}

$requiredPhp = [
    "SetBuffer('RuntimeRooms'",
    "GetRuntimeRooms()",
    "LastVisualizationPayload",
    "RetargetOrEnqueueHeatingJob"
];

foreach ($requiredPhp as $needle) {
    if (!str_contains($php, $needle)) {
        fwrite(STDERR, "Performance-Merkmal fehlt in module.php: $needle\n");
        exit(1);
    }
}

echo "Visualization performance regression OK\n";
