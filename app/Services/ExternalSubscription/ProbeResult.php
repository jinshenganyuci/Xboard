<?php

namespace App\Services\ExternalSubscription;

/** A result belongs to one request; unsuccessful requests never carry a latency. */
final class ProbeResult
{
    private const MAX_LATENCY_MS = 4294967295;

    public readonly ?int $latencyMs;

    public function __construct(public readonly ?bool $online, ?int $latencyMs = null)
    {
        // Round sub-millisecond successes up for display; keep the value within the SQL column's range.
        $this->latencyMs = $online === true && $latencyMs !== null && $latencyMs >= 0 && $latencyMs <= self::MAX_LATENCY_MS
            ? max(1, $latencyMs) : null;
    }

    public static function fromSeconds(?bool $online, float $seconds): self
    {
        return new self($online, is_finite($seconds) && $seconds >= 0 && $seconds <= self::MAX_LATENCY_MS / 1000
            ? (int) round($seconds * 1000) : null);
    }
}
