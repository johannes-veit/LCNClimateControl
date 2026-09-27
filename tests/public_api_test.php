<?php
declare(strict_types=1);

class IPSModuleStrict {}

require_once dirname(__DIR__) . '/LCNClimateControl/module.php';

$ref = new ReflectionClass(LCNClimateControl::class);
foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->getDeclaringClass()->getName() !== LCNClimateControl::class) {
        continue;
    }
    if (!$method->hasReturnType()) {
        fwrite(STDERR, "Public method without return type: {$method->getName()}\n");
        exit(1);
    }
    foreach ($method->getParameters() as $parameter) {
        if (!$parameter->hasType()) {
            fwrite(STDERR, "Untyped public parameter: {$method->getName()}::\${$parameter->getName()}\n");
            exit(1);
        }
    }
}
echo "Public API type hints OK\n";
