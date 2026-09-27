<?php
declare(strict_types=1);

$html = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.html');
if ($html === false) {
    fwrite(STDERR, "module.html konnte nicht gelesen werden.\n");
    exit(1);
}

if (str_contains($html, 'animation: pulse')) {
    fwrite(STDERR, "Busy-Anzeige pulsiert weiterhin dauerhaft.\n");
    exit(1);
}

if (!str_contains($html, '.busy-dot.on')) {
    fwrite(STDERR, "Busy-Indikator fehlt.\n");
    exit(1);
}

echo "Visualization busy indicator OK\n";
