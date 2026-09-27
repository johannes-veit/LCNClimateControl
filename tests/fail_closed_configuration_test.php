<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$start = strpos($php, 'public function RequestAction');
$end = strpos($php, 'public function Worker', $start);
$block = substr($php, $start, $end - $start);

if (!str_contains($block, "GetBuffer('ConfigurationValid') === '0'")) {
    fwrite(STDERR, "Fehlerhafte Raumkonfiguration blockiert Bedienbefehle nicht.\n");
    exit(1);
}

if (!str_contains($block, 'Aus Sicherheitsgründen werden keine LCN-Befehle gesendet')) {
    fwrite(STDERR, "Fail-closed Fehlermeldung fehlt.\n");
    exit(1);
}

echo "Fail-closed configuration OK\n";
