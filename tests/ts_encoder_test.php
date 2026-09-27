<?php
declare(strict_types=1);

class IPSModuleStrict {}

require_once dirname(__DIR__) . '/LCNClimateControl/module.php';

$ref = new ReflectionClass(LCNClimateControl::class);
$obj = $ref->newInstanceWithoutConstructor();
$m = $ref->getMethod('BuildShortTSData');
$m->setAccessible(true);

$cases = [
    ['A', 7, 'K---00000010'],
    ['A', 8, 'K---00000001'],
    ['A', 4, 'K---00010000'],
    ['B', 1, '-K--10000000'],
    ['D', 8, '---K00000001']
];

foreach ($cases as [$table, $key, $expected]) {
    $actual = $m->invoke($obj, $table, $key);
    if ($actual !== $expected) {
        fwrite(STDERR, "$table$key: erwartet $expected, erhalten $actual\n");
        exit(1);
    }
}

echo "TS encoder OK\n";
