<?php

use App\Models\LoyaltyCard;
use App\Models\LoyaltyMovement;
use App\Services\LoyaltyEngine;
use Carbon\CarbonImmutable;

const ADIDAS_RULE = ['threshold' => 400000, 'tranche' => 10000, 'per_tranche' => 1, 'bonus' => 50, 'tiers' => [['points' => 300, 'percent' => 10], ['points' => 350, 'percent' => 15]], 'validity' => 12, 'reset' => true];
const CARRE_RULE = ['threshold' => 650000, 'tranche' => 15000, 'per_tranche' => 1, 'bonus' => 75, 'tiers' => [['points' => 450, 'percent' => 10], ['points' => 550, 'percent' => 15]], 'validity' => 12, 'reset' => true];

function ticket(string $date, int $amount, ?int $percent = null): array
{
    static $id = 0;

    return ['date' => $date, 'kind' => 'ticket', 'amount' => $amount, 'percent' => $percent, 'id' => ++$id, 'discount' => 0];
}

function manualDiscount(string $date, int $percent = 10, int $amount = 5000): array
{
    return ['date' => $date, 'kind' => 'manual', 'amount' => 0, 'percent' => $percent, 'id' => null, 'discount' => $amount];
}

function play(array $events, array $rule = ADIDAS_RULE, string $ends = '2026-12-31', string $today = '2026-11-04'): array
{
    return LoyaltyEngine::replay($rule, $ends, $events, CarbonImmutable::parse($today));
}

it('stays out below the threshold and tells what is missing', function () {
    [$card, $log] = play([ticket('2026-02-01', 399999)]);

    expect($card['status'])->toBe(LoyaltyCard::STATUS_NONE)
        ->and($card['points'])->toBe(0)
        ->and($card['amount_to_threshold'])->toBe(1)
        ->and($log)->toBe([]);
});

it('activates exactly at the threshold', function () {
    [$card] = play([ticket('2026-02-01', 400000)]);

    expect($card['status'])->toBe(LoyaltyCard::STATUS_ACTIVE)
        ->and($card['points'])->toBe(40 + 50)
        ->and($card['activated_on'])->toBe('2026-02-01')
        ->and($card['expires_on'])->toBe('2027-02-01')
        ->and($card['amount_to_threshold'])->toBe(0);
});

it('gives 43 + 50 points for 430 000 at the first brand', function () {
    expect(play([ticket('2026-02-01', 430000)])[0]['points'])->toBe(93);
});

it('gives 43 + 75 points for 650 000 at the third brand', function () {
    expect(play([ticket('2026-02-01', 650000)], CARRE_RULE)[0]['points'])->toBe(118);
});

it('counts tranches on the running total, not ticket by ticket', function () {
    [$card] = play([ticket('2026-02-01', 400000), ticket('2026-03-01', 7000), ticket('2026-03-02', 7000)]);

    // 414 000 : 41 tranches. Ticket par ticket, ce serait 40.
    expect($card['points'])->toBe(41 + 50);
});

it('reaches the highest tier earned and tells the next one', function () {
    [$card, $log] = play([ticket('2026-02-01', 2500000)]);
    expect($card['points'])->toBe(300)
        ->and($card['discount_percent'])->toBe(10)
        ->and($card['next_tier_points'])->toBe(350)
        ->and($card['points_to_next_tier'])->toBe(50)
        ->and(collect($log)->where('type', LoyaltyMovement::TIER)->count())->toBe(1);

    [$top] = play([ticket('2026-02-01', 3000000)]);
    expect($top['discount_percent'])->toBe(15)->and($top['next_tier_points'])->toBeNull();
});

it('puts points back to zero when the discount is used, the total restarting from that ticket, without a new bonus', function () {
    [$card, $log] = play([ticket('2026-02-01', 2500000), ticket('2026-03-01', 50000, 10), ticket('2026-03-15', 20000)]);

    expect($card['status'])->toBe(LoyaltyCard::STATUS_ACTIVE)
        ->and($card['cycle_amount'])->toBe(70000)
        ->and($card['points'])->toBe(7)
        ->and($card['discount_percent'])->toBeNull()
        ->and($card['discounts_used'])->toBe(1)
        ->and(collect($log)->where('type', LoyaltyMovement::BONUS)->count())->toBe(1)
        ->and(collect($log)->firstWhere('type', LoyaltyMovement::DISCOUNT_USED)['points'])->toBe(-300);
});

it('keeps the points when the rule says so', function () {
    [$card] = play([ticket('2026-02-01', 2500000), ticket('2026-03-01', 50000, 10)], [...ADIDAS_RULE, 'reset' => false]);

    expect($card['points'])->toBe(305)->and($card['discounts_used'])->toBe(1);
});

it('does the same for the button, and counts one use when the ticket arrives the same day', function () {
    [$card] = play([ticket('2026-02-01', 2500000), manualDiscount('2026-03-01'), ticket('2026-03-01', 50000, 10)]);

    expect($card['discounts_used'])->toBe(1)->and($card['points'])->toBe(5);
});

it('ignores a discount for a customer who is not an active member', function () {
    [$card, $log] = play([ticket('2026-02-01', 100000), ticket('2026-02-10', 50000, 10)]);

    expect($card['status'])->toBe(LoyaltyCard::STATUS_NONE)
        ->and($card['cycle_amount'])->toBe(150000)
        ->and($log[0]['type'])->toBe(LoyaltyMovement::DISCOUNT_IGNORED);
});

it('expires after the validity, keeps the last day valid, and needs the threshold again with a new bonus', function () {
    $start = ticket('2026-01-10', 500000);

    expect(play([$start], today: '2027-01-10')[0]['status'])->toBe(LoyaltyCard::STATUS_ACTIVE);

    [$expired, $log] = play([$start], ends: '2027-12-31', today: '2027-01-11');
    expect($expired['status'])->toBe(LoyaltyCard::STATUS_EXPIRED)
        ->and($expired['points'])->toBe(0)
        ->and($expired['amount_to_threshold'])->toBe(400000)
        ->and(collect($log)->last()['points'])->toBe(-100);

    [$back] = play([$start, ticket('2027-03-01', 300000), ticket('2027-04-01', 100000)], ends: '2027-12-31', today: '2027-05-01');
    expect($back['status'])->toBe(LoyaltyCard::STATUS_ACTIVE)
        ->and($back['points'])->toBe(40 + 50)
        ->and($back['activated_on'])->toBe('2027-04-01')
        ->and($back['expires_on'])->toBe('2028-04-01');
});

it('takes points away on a return', function () {
    [$card, $log] = play([ticket('2026-02-01', 500000), ticket('2026-02-20', -60000)]);

    expect($card['points'])->toBe(44 + 50)
        ->and($card['status'])->toBe(LoyaltyCard::STATUS_ACTIVE)
        ->and(collect($log)->firstWhere('type', LoyaltyMovement::RETURN)['points'])->toBe(-6);
});

it('never lets the running total go negative', function () {
    [$card] = play([ticket('2026-02-01', 2500000), ticket('2026-03-01', 10000, 10), ticket('2026-03-05', -60000), ticket('2026-03-06', 20000)]);

    expect($card['cycle_amount'])->toBe(20000)->and($card['points'])->toBe(2);
});

it('gives nothing after the end of the programme but keeps the card usable', function () {
    [$card] = play([ticket('2026-02-01', 500000), ticket('2027-01-05', 90000), ticket('2027-01-06', 10000, 10)], today: '2027-01-10');

    expect($card['program_amount'])->toBe(500000)
        ->and($card['points'])->toBe(0)
        ->and($card['discounts_used'])->toBe(1)
        ->and($card['status'])->toBe(LoyaltyCard::STATUS_ACTIVE);
});

it('gives the same result when played twice', function () {
    $events = [ticket('2026-02-01', 500000), ticket('2026-03-01', 50000, 10), ticket('2026-04-01', -20000)];

    expect(play($events))->toEqual(play($events));
});
