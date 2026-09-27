<?php
declare(strict_types=1);

$html = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.html');
if ($html === false) {
    fwrite(STDERR, "module.html konnte nicht gelesen werden.\n");
    exit(1);
}

$required = [
    '.mode-line {',
    'position: sticky;',
    'top: 0;',
    'z-index: 40;',
    'background: var(--surface);',
    'border-bottom: 1px solid var(--line);',
    '.content-scroll'
];

foreach ($required as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Sticky-Betriebsart-Merkmal fehlt: $needle\n");
        exit(1);
    }
}

echo "Visualization sticky mode OK\n";
