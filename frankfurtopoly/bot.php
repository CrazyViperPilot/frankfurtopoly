<?php
// Computer opponents: decide the next action for whichever bot has to act. Pure functions on the game array.
// api.php runs one bot action per request (at most about one per second), so humans can follow along.

const FP_BOT_NAMES = ['Bembel', 'Handkäs'];
const FP_BOT_RESERVE = 100;   // money a bot keeps when buying / bidding
const FP_BOT_BUILD_RESERVE = 250;

// Returns [botName, action, args] or null if no bot has to act.
function fp_bot_next($g, $bots) {
    if ($g['stage'] === 'over') return null;
    $t = $g['trade'];
    if ($t && in_array($t['to'], $bots, true)) return [$t['to'], fp_bot_trade_ok($g, $t) ? 'accept' : 'reject', []];
    $st = $g['stage'];
    if ($st === 'auction') {
        $a = $g['auction']; $f = fp_field($a['field']);
        foreach ($bots as $b) {
            if ($g['out'][$b] || in_array($b, $a['passed'], true) || $a['leader'] === $b) continue;
            $limit = min($f['price'], $g['money'][$b] - FP_BOT_RESERVE);
            $next = $a['bid'] + 10;
            return $next <= $limit ? [$b, 'bid', ['amount' => $next]] : [$b, 'pass', []];
        }
        return null;
    }
    if ($st === 'debt') {
        $d = $g['debts'][0]['from'];
        return in_array($d, $bots, true) ? fp_bot_raise($g, $d) : null;
    }
    $cur = fp_cur($g);
    if (!in_array($cur, $bots, true)) return null;
    if ($st === 'roll') {
        if ($g['jail'][$cur] >= 0) {
            if ($g['jailCards'][$cur]) return [$cur, 'usecard', []];
            if ($g['money'][$cur] >= FP_JAIL_FINE + 400) return [$cur, 'payjail', []];
        }
        return [$cur, 'roll', []];
    }
    if ($st === 'buy') {
        $f = fp_field($g['buyField']);
        return [$cur, $g['money'][$cur] - $f['price'] >= FP_BOT_RESERVE ? 'buy' : 'decline', []];
    }
    if ($st === 'end') {
        foreach ($g['props'] as $i => $pr) {
            if (!$pr || $pr['o'] !== $cur) continue;
            if ($pr['m'] && $g['money'][$cur] - fp_unmortgage_cost($i) >= 400 && fp_bot_try($g, $cur, 'unmortgage', $i)) return [$cur, 'unmortgage', ['field' => $i]];
        }
        foreach ($g['props'] as $i => $pr) {
            if (!$pr || $pr['o'] !== $cur || fp_field($i)['type'] !== 'street') continue;
            $house = fp_board()['groups'][fp_field($i)['group']]['house'];
            if ($g['money'][$cur] - $house >= FP_BOT_BUILD_RESERVE && fp_bot_try($g, $cur, 'build', $i)) return [$cur, 'build', ['field' => $i]];
        }
        return [$cur, 'endturn', []];
    }
    return null;
}

// Dry run of a property action on a copy of the game.
function fp_bot_try($g, $p, $action, $i) { return fp_manage($g, $p, $action, $i) === null; }

// In debt: sell houses first, then mortgage, else go bankrupt.
function fp_bot_raise($g, $p) {
    foreach ($g['props'] as $i => $pr) if ($pr && $pr['o'] === $p && $pr['h'] > 0 && fp_bot_try($g, $p, 'sell', $i)) return [$p, 'sell', ['field' => $i]];
    foreach ($g['props'] as $i => $pr) if ($pr && $pr['o'] === $p && !$pr['m'] && fp_bot_try($g, $p, 'mortgage', $i)) return [$p, 'mortgage', ['field' => $i]];
    return [$p, 'bankrupt', []];
}

function fp_bot_value($fields, $money, $g) {
    $v = $money;
    foreach ($fields as $i) $v += $g['props'][$i]['m'] ? intdiv(fp_field($i)['price'], 2) : fp_field($i)['price'];
    return $v;
}

// A bot accepts when it gains at least 25 % more value and doesn't hand over the last street of a colour group.
function fp_bot_trade_ok($g, $t) {
    $gain = fp_bot_value($t['give'], $t['giveMoney'], $g);
    $loss = fp_bot_value($t['get'], $t['getMoney'], $g);
    if ($t['getMoney'] > $g['money'][$t['to']]) return false;
    foreach ($t['get'] as $i) {
        $f = fp_field($i);
        if ($f['type'] !== 'street') continue;
        $c = $g;
        foreach ($t['get'] as $j) $c['props'][$j]['o'] = $t['from'];
        if (fp_owns_group($c, $t['from'], $f['group'])) return false;
    }
    return $gain >= $loss * 1.25 && $gain > 0;
}
