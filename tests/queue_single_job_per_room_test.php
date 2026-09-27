<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$required = [
    'Doppelte Jobs desselben Raums dürfen nicht entstehen',
    '$this->HasJobForTarget($targetID)',
    "fn(array \$queued): bool => (int) (\$queued['TargetVariable'] ?? 0) !== \$targetID",
    "isset(\$Job['RetargetType'])"
];

foreach ($required as $needle) {
    if (!str_contains($php, $needle)) {
        fwrite(STDERR, "Queue-Invariante fehlt: $needle\n");
        exit(1);
    }
}

echo "Single job per room invariant OK\n";
