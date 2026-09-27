<?php
declare(strict_types=1);

$html = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.html');
if ($html === false) {
    fwrite(STDERR, "module.html konnte nicht gelesen werden.\n");
    exit(1);
}

$required = [
    'height: 100%;',
    'overflow: hidden;',
    'grid-template-rows: var(--symcon-title-zone) minmax(0, 1fr);',
    '.symcon-title-shield',
    'background: var(--surface);',
    '.content-scroll',
    'overflow-y: auto;',
    "titleShield.className = 'symcon-title-shield';",
    "content.className = 'content-scroll';",
    'root.appendChild(titleShield);',
    'root.appendChild(content);',
    'buildMode(content);'
];

foreach ($required as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Titel-/Scrollschutz fehlt: $needle\n");
        exit(1);
    }
}

if (str_contains($html, 'body { overflow: auto; }')) {
    fwrite(STDERR, "body darf nicht mehr scrollen.\n");
    exit(1);
}

if (str_contains($html, '#app { padding: 48px') || str_contains($html, '#app { padding: 44px')) {
    fwrite(STDERR, "Alte Padding-Scheinlösung für den Kachelkopf ist wieder vorhanden.\n");
    exit(1);
}

echo "Visualization scroll/header containment OK\n";
