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

if (!str_contains($html, 'padding: 48px 14px 10px;')) {
    fwrite(STDERR, "Desktop-Sicherheitsabstand für den Symcon-Kachelkopf fehlt.\n");
    exit(1);
}

if (!str_contains($html, '#app { padding: 44px 8px 8px; }')) {
    fwrite(STDERR, "Mobile-Sicherheitsabstand für den Symcon-Kachelkopf fehlt.\n");
    exit(1);
}

echo "Visualization header spacing OK\n";
