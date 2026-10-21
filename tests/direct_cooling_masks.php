<?php
declare(strict_types=1);
require __DIR__ . '/../LCNClimateControl/DirectCooling.php';
$GLOBALS['lastMask'] = null;
function IPS_FunctionExists(string $name): bool { return true; }
function IPS_SemaphoreEnter(string $name, int $timeout): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}
function LCN_SetRelay(int $instance, string $mask): bool { $GLOBALS['lastMask'] = [$instance, $mask]; return true; }
class Probe {
    use LCNDirectCooling;
    private const GLOBAL_LCN_SEND_SEMAPHORE = 'TEST';
    private function IsOperationalLCNModule(int $id): bool { return $id === 100; }
    private function SendDebug(string $name, string $message, int $format): void {}
    public function send(int $channel, bool $open, bool $openOn): array {
        $mapping = ['RelayModule' => 100,'RelayChannel' => $channel,'OpenRelayOn' => $openOn];
        if (!$this->DirectCoolingRelaySet($mapping,$open)) { throw new RuntimeException('Send failed'); }
        return $GLOBALS['lastMask'];
    }
}
$p = new Probe();
$count=0;
for($ch=1; $ch<=8; $ch++) {
    foreach([false,true] as $openOn) {
        foreach([false,true] as $open) {
            [$id,$mask]=$p->send($ch,$open,$openOn);
            if($id!==100 || strlen($mask)!==8) throw new RuntimeException('ID / mask error');
            for($i=0;$i<8;$i++){
                $want=$i+1===$ch ? (($open===$openOn)?'1':'0') : '-';
                if($mask[$i]!==$want) throw new RuntimeException("Channel pollution ch=$ch i=$i mask=$mask");
            }
            $count++;
        }
    }
}
echo "OK: $count mask-isolation cases\n";
