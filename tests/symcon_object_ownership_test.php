<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

if (str_contains($php, 'IPS_SetHidden(')) {
    fwrite(STDERR, "Modul verändert weiterhin dynamisch die Benutzer-Eigenschaft Hidden.\n");
    exit(1);
}

if (str_contains($php, 'IPS_SetInfo(')) {
    fwrite(STDERR, "Modul verändert weiterhin dynamisch die Benutzer-Eigenschaft Info.\n");
    exit(1);
}

echo "Symcon object ownership OK\n";
