<?php

namespace App\Sesame;

final class Outcome
{
    public const string DELIVERED = 'delivered';

    public const string HARD_BOUNCE = 'hard_bounce';

    public const string SOFT_BOUNCE = 'soft_bounce';

    public const string COMPLAINT = 'complaint';

    public const string DELAY = 'delay';

    public const string REJECT = 'reject';

    /** Bounce subtype, complaint feedback type or delay type. */
    public ?string $subType = null;

    /** Milliseconds after acceptance of the final Delivery or Bounce event. */
    public int $finalDelayMs = 0;

    public int $complaintDelayMs = 0;

    public int $delayNoticeMs = 0;

    public bool $delayThenBounce = false;

    public bool $duplicate = false;

    public bool $outOfOrder = false;

    public function __construct(public readonly string $kind) {}
}
