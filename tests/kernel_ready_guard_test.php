<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

if (substr_count($php, 'IPS_GetKernelRunlevel() !== KR_READY') < 2) {
    fwrite(STDERR, "Kernel-Ready-Schutz fehlt in RequestAction/Sendepfad.\n");
    exit(1);
}

echo "Kernel ready guard OK\n";
