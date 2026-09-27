<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$abortStart = strpos($php, 'public function Abort(): void');
$abortEnd = strpos($php, 'public function ClearError(): void', $abortStart);
$abort = substr($php, $abortStart, $abortEnd - $abortStart);

if (!str_contains($abort, 'Kühlfahrt manuell abgebrochen; Endlage nicht bestätigt.')) {
    fwrite(STDERR, "Abgebrochene Kühlfahrt wird nicht als unbestätigte Endlage markiert.\n");
    exit(1);
}

$applyStart = strpos($php, 'public function ApplyChanges(): void');
$applyEnd = strpos($php, 'public function MessageSink', $applyStart);
$apply = substr($php, $applyStart, $applyEnd - $applyStart);

if (!str_contains($apply, 'Kühlfahrt durch Übernehmen/Update unterbrochen; Endlage nicht bestätigt.')) {
    fwrite(STDERR, "ApplyChanges markiert unterbrochene Kühlfahrt nicht.\n");
    exit(1);
}

echo "Cooling abort truthfulness OK\n";
