<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

if (!str_contains($php, '$jobOwnsTarget = $this->HasJobForTarget($SenderID);')) {
    fwrite(STDERR, "MessageSink berücksichtigt yielded Queue-Jobs nicht.\n");
    exit(1);
}

echo "MessageSink job ownership OK\n";
