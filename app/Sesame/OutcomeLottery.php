<?php

namespace App\Sesame;

/**
 * Draws one outcome per recipient. The draw is a pure function of the run
 * seed and the recipient address, so concurrent ingest workers agree with
 * each other and a run replays exactly from its recorded seed.
 */
final class OutcomeLottery
{
    public const array PERMANENT_SUBTYPES = ['General' => 70, 'NoEmail' => 15, 'Suppressed' => 10, 'OnAccountSuppressionList' => 5];

    public const array TRANSIENT_SUBTYPES = ['General' => 40, 'MailboxFull' => 30, 'MessageTooLarge' => 10, 'ContentRejected' => 15, 'AttachmentRejected' => 5];

    public const array DELAY_TYPES = ['MailboxFull' => 30, 'RecipientServerError' => 30, 'TransientCommunicationFailure' => 40];

    /**
     * @param  array<string, float>  $rates  percentages, keyed like config('sesame.presets.*')
     * @param  array<string, array{0: int|float, 1: int|float}>  $timing  seconds
     */
    public static function draw(int $seed, string $recipient, array $rates, array $timing): Outcome
    {
        $bytes = hash('sha256', $seed.'|'.strtolower($recipient), true);
        $u = static fn (int $i): float => (unpack('N', substr($bytes, $i * 4, 4))[1] ?? 0) / 4294967296;

        $kind = self::forced($recipient) ?? self::bucket($u(0) * 100, $rates);

        $outcome = new Outcome($kind);
        $outcome->duplicate = $u(4) * 100 < ($rates['duplicate'] ?? 0);
        $outcome->outOfOrder = $u(5) * 100 < ($rates['out_of_order'] ?? 0);

        $between = static fn (string $key, float $x): int => (int) round(1000 * ($timing[$key][0] + ($timing[$key][1] - $timing[$key][0]) * $x));

        match ($kind) {
            Outcome::HARD_BOUNCE => [$outcome->subType = self::weighted(self::PERMANENT_SUBTYPES, $u(1)), $outcome->finalDelayMs = $between('bounce', $u(2))],
            Outcome::SOFT_BOUNCE => [$outcome->subType = self::weighted(self::TRANSIENT_SUBTYPES, $u(1)), $outcome->finalDelayMs = $between('bounce', $u(2))],
            Outcome::COMPLAINT => [
                $outcome->finalDelayMs = $between('delivery', $u(2)),
                $outcome->complaintDelayMs = $between('complaint', $u(3)),
                // SES includes a feedback type only when the ISP attaches a report.
                $outcome->subType = $u(1) < 0.85 ? 'abuse' : null,
            ],
            Outcome::DELAY => [
                $outcome->subType = self::weighted(self::DELAY_TYPES, $u(1)),
                $outcome->delayNoticeMs = $between('delivery', $u(2)),
                $outcome->finalDelayMs = $between('delivery', $u(2)) + $between('delay_final', $u(3)),
                // A delayed message ends delivered or, sometimes, bounced.
                $outcome->delayThenBounce = $u(6) < 0.25,
            ],
            Outcome::DELIVERED => $outcome->finalDelayMs = $between('delivery', $u(2)),
            Outcome::REJECT => null,
            default => throw new \LogicException("Unknown outcome [{$kind}]."),
        };

        return $outcome;
    }

    /** Recipient local parts that force an outcome, mirroring SES's mailbox simulator. */
    public static function forced(string $recipient): ?string
    {
        $local = strtolower(strstr($recipient, '@', true) ?: $recipient);
        $base = explode('+', $local, 2)[0];

        return match ($base) {
            'bounce' => Outcome::HARD_BOUNCE,
            'complaint' => Outcome::COMPLAINT,
            'delay' => Outcome::DELAY,
            'success' => Outcome::DELIVERED,
            default => null,
        };
    }

    /** @param array<string, float> $rates */
    private static function bucket(float $roll, array $rates): string
    {
        foreach ([Outcome::REJECT => 'reject', Outcome::HARD_BOUNCE => 'hard_bounce', Outcome::SOFT_BOUNCE => 'soft_bounce', Outcome::COMPLAINT => 'complaint', Outcome::DELAY => 'delay'] as $kind => $key) {
            $roll -= $rates[$key] ?? 0;
            if ($roll < 0) {
                return $kind;
            }
        }

        return Outcome::DELIVERED;
    }

    /** @param array<string, int> $weights */
    private static function weighted(array $weights, float $u): string
    {
        $roll = $u * array_sum($weights);
        foreach ($weights as $name => $weight) {
            $roll -= $weight;
            if ($roll < 0) {
                return $name;
            }
        }

        return array_key_last($weights);
    }
}
