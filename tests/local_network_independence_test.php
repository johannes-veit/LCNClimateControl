<?php
declare(strict_types=1);

$html = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.html');
if ($html === false) {
    fwrite(STDERR, "module.html konnte nicht gelesen werden.\n");
    exit(1);
}

if (str_contains($html, 'navigator.onLine')) {
    fwrite(STDERR, "Lokale SymBox-Bedienung hängt wieder vom Browser-Internetstatus ab.\n");
    exit(1);
}

if (!str_contains($html, 'requestAction(action.ident, action.value)')) {
    fwrite(STDERR, "HTML-SDK requestAction fehlt.\n");
    exit(1);
}

echo "Local network independence OK\n";
