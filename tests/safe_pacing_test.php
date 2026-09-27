<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
$form = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/form.json');

if ($php === false || $form === false) {
    fwrite(STDERR, "Quelldateien konnten nicht gelesen werden.\n");
    exit(1);
}

if (!str_contains($php, 'return max(900, min(3000')) {
    fwrite(STDERR, "Runtime-Mindestwartezeit 900 ms fehlt.\n");
    exit(1);
}

if (!str_contains($form, '"minimum": 900')) {
    fwrite(STDERR, "Formular erlaubt weiterhin zu kurze Raumwartezeiten.\n");
    exit(1);
}

if (!str_contains($php, "SetTimerInterval('Worker', 250)")) {
    fwrite(STDERR, "Konservative globale Worker-Taktung fehlt.\n");
    exit(1);
}

echo "Safe pacing OK\n";
