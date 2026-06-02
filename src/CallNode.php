<?php

declare(strict_types=1);

namespace NoiseByNorthwest\SpxMcp;

/** @internal */
final class CallNode
{
    public int $calls = 0;

    /** Accumulated metric value across all calls to this node. */
    public int $value = 0;

    /**
     * Metric value captured at the most recent call START; transient state used
     * during the streaming pass to compute this call's delta on its matching END.
     */
    public int $startValue = 0;

    /** @var array<int, self> keyed by fnIdx; -1 reserved for the synthetic root */
    public array $children = [];

    public function __construct(public readonly int $fnIdx) {}
}
