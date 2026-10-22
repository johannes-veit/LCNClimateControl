<?php
declare(strict_types=1);

/**
 * Cooperative bus pacing for every LCN command originating from Climate Control.
 *
 * The same GLOBAL_LCN_SEND_SEMAPHORE is already taken by all command paths.
 * Keep the lock briefly after the command/read so the next climate sender
 * cannot enter the bus immediately. No new Symcon variables or scripts.
 *
 * Note: independent third-party modules not using the same semaphore
 * cannot be throttled by this trait.
 */
trait LCNClimateTrafficPacing
{
    private const LCN_SEND_GAP_MS = 750;

    private function ReleaseLCNBusSlot(): void
    {
        try {
            // IPS_Sleep uses milliseconds (IP-Symcon 9); shorter than
            // long job timers, and only one sleep per bus command.
            IPS_Sleep(self::LCN_SEND_GAP_MS);
        } finally {
            IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
        }
    }
}
