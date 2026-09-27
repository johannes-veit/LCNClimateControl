<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$start = strpos($php, 'private function StartNextJob(): ?array');
$end = strpos($php, 'private function RetargetOrEnqueueHeatingJob', $start);
$block = substr($php, $start, $end - $start);

$forbidden = [
    "\$job['Phase'] = self::PHASE_SEND",
    "\$job['Steps'] = 0",
    "\$job['NoChange'] = 0",
    "\$job['SentAtMs'] = 0"
];

foreach ($forbidden as $needle) {
    if (str_contains($block, $needle)) {
        fwrite(STDERR, "StartNextJob setzt Round-robin-Zustand zurück: $needle\n");
        exit(1);
    }
}

$required = [
    'private function HasJobForTarget(int $TargetID): bool',
    'private function RetargetOrEnqueueCoolingJob',
    "IPS_SemaphoreEnter('LCNC_' . \$this->InstanceID, 2000)",
    "SetTimerInterval('Worker', 250)"
];

foreach ($required as $needle) {
    if (!str_contains($php, $needle)) {
        fwrite(STDERR, "Mehrraum-Sicherheitsmerkmal fehlt: $needle\n");
        exit(1);
    }
}

echo "Scheduler state preservation OK\n";
