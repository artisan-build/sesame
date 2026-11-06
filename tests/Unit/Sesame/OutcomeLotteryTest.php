<?php

use App\Sesame\Outcome;
use App\Sesame\OutcomeLottery;

$healthy = ['hard_bounce' => 0.8, 'soft_bounce' => 1.0, 'complaint' => 0.05, 'delay' => 0.5, 'reject' => 0.01, 'duplicate' => 0.2, 'out_of_order' => 0.5];
$timing = ['send' => [0, 0], 'delivery' => [1, 5], 'bounce' => [1, 30], 'complaint' => [30, 600], 'delay_final' => [60, 900]];

it('replays the same outcomes from the same seed and differs across seeds', function () use ($healthy, $timing) {
    $a = $b = $c = [];
    for ($i = 0; $i < 3000; $i++) {
        $a[] = serialize(OutcomeLottery::draw(42, "load-{$i}@load.crm.test", $healthy, $timing));
        $b[] = serialize(OutcomeLottery::draw(42, "load-{$i}@load.crm.test", $healthy, $timing));
        $c[] = serialize(OutcomeLottery::draw(43, "load-{$i}@load.crm.test", $healthy, $timing));
    }
    expect($a)->toBe($b)->and($a)->not->toBe($c);
});

it('draws outcomes near the preset rates', function () use ($timing) {
    // Large rates so a 20,000-recipient sample separates them cleanly.
    $rates = ['hard_bounce' => 10, 'soft_bounce' => 5, 'complaint' => 2, 'delay' => 3, 'reject' => 1];
    $counts = array_fill_keys([Outcome::HARD_BOUNCE, Outcome::SOFT_BOUNCE, Outcome::COMPLAINT, Outcome::DELAY, Outcome::REJECT, Outcome::DELIVERED], 0);
    for ($i = 0; $i < 20000; $i++) {
        $counts[OutcomeLottery::draw(7, "r{$i}@load.crm.test", $rates, $timing)->kind]++;
    }
    expect($counts[Outcome::HARD_BOUNCE] / 200)->toBeGreaterThan(9.2)->toBeLessThan(10.8)
        ->and($counts[Outcome::SOFT_BOUNCE] / 200)->toBeGreaterThan(4.4)->toBeLessThan(5.6)
        ->and($counts[Outcome::COMPLAINT] / 200)->toBeGreaterThan(1.6)->toBeLessThan(2.4)
        ->and($counts[Outcome::DELAY] / 200)->toBeGreaterThan(2.5)->toBeLessThan(3.5)
        ->and($counts[Outcome::REJECT] / 200)->toBeGreaterThan(0.7)->toBeLessThan(1.3)
        ->and($counts[Outcome::DELIVERED] / 200)->toBeGreaterThan(77)->toBeLessThan(81);
});

it('forces outcomes by local part like the SES mailbox simulator', function () use ($timing) {
    $all = ['hard_bounce' => 0, 'soft_bounce' => 0, 'complaint' => 0, 'delay' => 0, 'reject' => 100];
    expect(OutcomeLottery::draw(1, 'bounce+x@load.crm.test', $all, $timing)->kind)->toBe(Outcome::HARD_BOUNCE)
        ->and(OutcomeLottery::draw(1, 'complaint+7@load.crm.test', $all, $timing)->kind)->toBe(Outcome::COMPLAINT)
        ->and(OutcomeLottery::draw(1, 'delay@load.crm.test', $all, $timing)->kind)->toBe(Outcome::DELAY)
        ->and(OutcomeLottery::draw(1, 'success+1@load.crm.test', $all, $timing)->kind)->toBe(Outcome::DELIVERED)
        // Only the part before "+" forces; "bouncer" is an ordinary address.
        ->and(OutcomeLottery::draw(1, 'bouncer@load.crm.test', $all, $timing)->kind)->toBe(Outcome::REJECT);
});

it('keeps event timing inside the configured windows', function () use ($healthy, $timing) {
    for ($i = 0; $i < 2000; $i++) {
        $o = OutcomeLottery::draw(5, "success+{$i}@load.crm.test", $healthy, $timing);
        expect($o->finalDelayMs)->toBeGreaterThanOrEqual(1000)->toBeLessThanOrEqual(5000);
        $c = OutcomeLottery::draw(5, "complaint+{$i}@load.crm.test", $healthy, $timing);
        expect($c->complaintDelayMs)->toBeGreaterThanOrEqual(30000)->toBeLessThanOrEqual(600000);
    }
});
