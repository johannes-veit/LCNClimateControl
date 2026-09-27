<?php
declare(strict_types=1);

$html = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.html');
if ($html === false) {
    fwrite(STDERR, "module.html konnte nicht gelesen werden.\n");
    exit(1);
}

if (str_contains($html, 'LCN Heizung / Kühlung')) {
    fwrite(STDERR, "Die HTML-Kachel darf den Instanznamen nicht selbst zeichnen.\n");
    exit(1);
}

if (!str_contains($html, '--symcon-title-zone: 62px;')) {
    fwrite(STDERR, "Feste Desktop-Titelzone fehlt.\n");
    exit(1);
}

if (!str_contains($html, '--symcon-title-zone: 58px;')) {
    fwrite(STDERR, "Feste mobile Titelzone fehlt.\n");
    exit(1);
}

if (!str_contains($html, '.symcon-title-shield')) {
    fwrite(STDERR, "Opaker Titelschutz fehlt.\n");
    exit(1);
}

echo "Visualization header spacing OK\n";
