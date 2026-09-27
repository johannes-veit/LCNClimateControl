<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$required = [
    'private function IsOperationalTargetVariable',
    'FeedbackRetryNotBeforeMs',
    'FeedbackFailures',
    'S1Target-Rückmeldung ist nicht betriebsbereit',
    "ModuleInfo']['ModuleID'",
    "ConnectionID"
];

foreach ($required as $needle) {
    if (!str_contains($php, $needle)) {
        fwrite(STDERR, "Feedback-Pfad-Schutz fehlt: $needle\n");
        exit(1);
    }
}

echo "Feedback path guard OK\n";
