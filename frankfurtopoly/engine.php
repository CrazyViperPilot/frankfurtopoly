<?php
// Frankfurtopoly rules engine: pure functions on the game array $g (no I/O except reading board.json).
// The server is authoritative; api.php stores $g as JSON and calls fp_act() for every move.
//
// Stages of a turn ($g['stage']):
//   roll    – current player rolls (or leaves the JVA by paying / using a card)
//   buy     – current player buys $g['buyField'] or sends it to auction
//   auction – everyone still in the game bids on $g['auction']['field']
//   debt    – $g['debts'][0]['from'] owes money and must raise it (mortgage / sell houses) or go bankrupt
//   end     – current player may still build/trade, then ends the turn
//   over    – game finished, $g['winner'] set

const FP_NAMES = ['Daniel', 'Lea', 'Kira'];
const FP_START_MONEY = 1500;
const FP_GO_MONEY = 200;
const FP_JAIL = 10;
const FP_JAIL_FINE = 50;
const FP_HOUSES = 32;
const FP_HOTELS = 12;

function fp_board() {
    static $b = null;
    if ($b === null) $b = json_decode(file_get_contents(__DIR__ . '/board.json'), true);
    return $b;
}
function fp_field($i) { return fp_board()['fields'][$i]; }
function fp_is_prop($i) { $t = fp_field($i)['type']; return $t === 'street' || $t === 'school' || $t === 'utility'; }

// Dice; tests can queue fixed throws in $GLOBALS['FP_DICE'] = [[3, 4], …].
function fp_dice() {
    if (!empty($GLOBALS['FP_DICE'])) return array_shift($GLOBALS['FP_DICE']);
    return [random_int(1, 6), random_int(1, 6)];
}
function fp_shuffled($n) { $a = range(0, $n - 1); shuffle($a); return $a; }

function fp_new_game($names) {
    shuffle($names);
    $g = ['order' => $names, 'turn' => 0, 'stage' => 'roll', 'money' => [], 'pos' => [], 'jail' => [],
          'jailCards' => [], 'out' => [], 'props' => array_fill(0, 40, null), 'dice' => null, 'dbl' => 0,
          'again' => false, 'buyField' => null, 'auction' => null, 'debts' => [], 'trade' => null,
          'houses' => FP_HOUSES, 'hotels' => FP_HOTELS, 'card' => null, 'log' => [], 'winner' => null,
          'decks' => ['chance' => fp_shuffled(16), 'chest' => fp_shuffled(16)], 'cards' => 0];
    foreach ($names as $n) {
        $g['money'][$n] = FP_START_MONEY; $g['pos'][$n] = 0; $g['jail'][$n] = -1;
        $g['jailCards'][$n] = []; $g['out'][$n] = false;
    }
    fp_log($g, $names[0] . ' beginnt.');
    return $g;
}

function fp_log(&$g, $t) { $g['logN'] = (isset($g['logN']) ? $g['logN'] : 0) + 1; $g['log'][] = $t; $g['log'] = array_slice($g['log'], -40); }
function fp_cur($g) { return $g['order'][$g['turn']]; }
function fp_active($g) { $r = []; foreach ($g['order'] as $n) if (!$g['out'][$n]) $r[] = $n; return $r; }
function fp_eur($v) { return number_format($v, 0, ',', '.') . ' €'; }

function fp_group_fields($group) {
    $r = [];
    foreach (fp_board()['fields'] as $i => $f) if ($f['type'] === 'street' && $f['group'] === $group) $r[] = $i;
    return $r;
}
function fp_owns_group($g, $p, $group) {
    foreach (fp_group_fields($group) as $i) if (!$g['props'][$i] || $g['props'][$i]['o'] !== $p) return false;
    return true;
}
function fp_count_type($g, $p, $type) {
    $c = 0;
    foreach (fp_board()['fields'] as $i => $f) if ($f['type'] === $type && $g['props'][$i] && $g['props'][$i]['o'] === $p) $c++;
    return $c;
}

// Rent for landing on $i. $mode: 'double' (card: next school) or 'util10' (card: next utility).
function fp_rent($g, $i, $diceSum, $mode = null) {
    $f = fp_field($i); $pr = $g['props'][$i]; $o = $pr['o'];
    if ($f['type'] === 'street') {
        if ($pr['h'] > 0) return $f['rent'][$pr['h']];
        return $f['rent'][0] * (fp_owns_group($g, $o, $f['group']) ? 2 : 1);
    }
    if ($f['type'] === 'school') {
        $r = 25 * (1 << (fp_count_type($g, $o, 'school') - 1));
        return $mode === 'double' ? $r * 2 : $r;
    }
    $mult = ($mode === 'util10' || fp_count_type($g, $o, 'utility') === 2) ? 10 : 4;
    return $mult * $diceSum;
}

function fp_charge(&$g, $from, $to, $amount, $why) {
    if ($amount <= 0) return;
    $g['debts'][] = ['from' => $from, 'to' => $to, 'amount' => $amount, 'why' => $why];
}

// Pays queued debts in order; stops at the first one that can't be paid (stage 'debt').
function fp_settle(&$g) {
    while ($g['debts']) {
        $d = $g['debts'][0];
        if ($g['out'][$d['from']] || ($d['to'] !== null && $g['out'][$d['to']])) { array_shift($g['debts']); continue; }
        if ($g['money'][$d['from']] < $d['amount']) { $g['stage'] = 'debt'; return false; }
        $g['money'][$d['from']] -= $d['amount'];
        if ($d['to'] !== null) $g['money'][$d['to']] += $d['amount'];
        fp_log($g, $d['from'] . ' zahlt ' . fp_eur($d['amount']) . ($d['to'] !== null ? ' an ' . $d['to'] : '') . ' (' . $d['why'] . ')');
        array_shift($g['debts']);
    }
    return true;
}

// Called whenever a step of the turn is done: settle debts, then let the player roll again or end.
function fp_continue(&$g) {
    if ($g['stage'] === 'over') return;
    if (!fp_settle($g)) return;
    if ($g['out'][fp_cur($g)]) { fp_next_turn($g); return; }
    $g['stage'] = $g['again'] ? 'roll' : 'end';
}

function fp_next_turn(&$g) {
    $n = count($g['order']);
    for ($k = 1; $k <= $n; $k++) {
        $t = ($g['turn'] + $k) % $n;
        if (!$g['out'][$g['order'][$t]]) { $g['turn'] = $t; break; }
    }
    $g['dbl'] = 0; $g['again'] = false; $g['stage'] = 'roll'; $g['buyField'] = null;
}

// Records the last movement for the dashed line on the board: segments of one player's turn.
// kind: walk (forward along the track), back (backwards), jump (straight to the JVA).
function fp_seg(&$g, $p, $from, $to, $kind) {
    if ($from === $to) return;
    if (empty($g['lastMove']) || $g['lastMove']['who'] !== $p) $g['lastMove'] = ['who' => $p, 'segs' => []];
    $g['lastMove']['segs'][] = ['from' => $from, 'to' => $to, 'kind' => $kind];
}

function fp_to_jail(&$g, $p) {
    fp_seg($g, $p, $g['pos'][$p], FP_JAIL, 'jump');
    $g['pos'][$p] = FP_JAIL; $g['jail'][$p] = 0; $g['again'] = false; $g['dbl'] = 0;
    fp_log($g, "$p muss in die JVA.");
}

function fp_move_to(&$g, $p, $to, $passGo = true) {
    fp_seg($g, $p, $g['pos'][$p], $to, 'walk');
    if ($passGo && $to < $g['pos'][$p]) { $g['money'][$p] += FP_GO_MONEY; fp_log($g, "$p kommt über Los: +" . fp_eur(FP_GO_MONEY)); }
    $g['pos'][$p] = $to;
}

// Resolve the field the player stands on. Either sets stage 'buy' (decision pending) or continues the turn.
function fp_resolve(&$g, $p, $mode = null) {
    $i = $g['pos'][$p]; $f = fp_field($i);
    $diceSum = $g['dice'] ? $g['dice'][0] + $g['dice'][1] : 7;
    if (fp_is_prop($i)) {
        $pr = $g['props'][$i];
        if ($pr === null) { $g['buyField'] = $i; $g['stage'] = 'buy'; return; }
        if ($pr['o'] !== $p && !$pr['m']) {
            if ($mode === 'util10') {
                $d = fp_dice(); $diceSum = $d[0] + $d[1];
                fp_log($g, "$p würfelt für die Miete: {$d[0]}+{$d[1]}");
            }
            fp_charge($g, $p, $pr['o'], fp_rent($g, $i, $diceSum, $mode), 'Miete ' . $f['name']);
        }
    } elseif ($f['type'] === 'tax') {
        fp_charge($g, $p, null, $f['amount'], $f['name']);
    } elseif ($f['type'] === 'chance' || $f['type'] === 'chest') {
        fp_draw_card($g, $p, $f['type']);
        return;
    } elseif ($f['type'] === 'gotojail') {
        fp_to_jail($g, $p);
    }
    fp_continue($g);
}

function fp_draw_card(&$g, $p, $deck) {
    $idx = array_shift($g['decks'][$deck]);
    $c = fp_board()[$deck][$idx];
    if ($c['a'] !== 'jailcard') $g['decks'][$deck][] = $idx;
    $g['card'] = ['deck' => $deck, 'text' => $c['text'], 'who' => $p, 'n' => ++$g['cards']];
    fp_log($g, $p . ' zieht: ' . $c['text']);
    switch ($c['a']) {
        case 'move': fp_move_to($g, $p, $c['v']); fp_resolve($g, $p); return;
        case 'back':
            $to = ($g['pos'][$p] + 40 - $c['v']) % 40;
            fp_seg($g, $p, $g['pos'][$p], $to, 'back');
            $g['pos'][$p] = $to; fp_resolve($g, $p); return;
        case 'school':
        case 'utility':
            $type = $c['a'];
            for ($k = 1; $k <= 40; $k++) { $t = ($g['pos'][$p] + $k) % 40; if (fp_field($t)['type'] === $type) break; }
            fp_move_to($g, $p, $t);
            fp_resolve($g, $p, $type === 'school' ? 'double' : 'util10');
            return;
        case 'jail': fp_to_jail($g, $p); break;
        case 'jailcard': $g['jailCards'][$p][] = $deck; break;
        case 'money':
            if ($c['v'] > 0) $g['money'][$p] += $c['v']; else fp_charge($g, $p, null, -$c['v'], 'Karte');
            break;
        case 'repairs':
            $sum = 0;
            foreach ($g['props'] as $pr) if ($pr && $pr['o'] === $p) $sum += $pr['h'] === 5 ? $c['v'][1] : $pr['h'] * $c['v'][0];
            fp_charge($g, $p, null, $sum, 'Renovierung');
            break;
        case 'payeach': foreach (fp_active($g) as $q) if ($q !== $p) fp_charge($g, $p, $q, $c['v'], 'Karte'); break;
        case 'collecteach': foreach (fp_active($g) as $q) if ($q !== $p) fp_charge($g, $q, $p, $c['v'], 'Karte'); break;
    }
    fp_continue($g);
}

function fp_roll(&$g, $p) {
    $d = fp_dice(); $g['dice'] = $d; $sum = $d[0] + $d[1]; $double = $d[0] === $d[1];
    $g['lastMove'] = ['who' => $p, 'segs' => []];
    fp_log($g, "$p würfelt {$d[0]}+{$d[1]}" . ($double ? ' (Pasch)' : ''));
    if ($g['jail'][$p] >= 0) {
        if ($double) {
            $g['jail'][$p] = -1; $g['again'] = false;
            fp_log($g, "$p kommt mit einem Pasch aus der JVA.");
        } else {
            $g['jail'][$p]++;
            if ($g['jail'][$p] < 3) { $g['again'] = false; $g['stage'] = 'end'; return; }
            $g['jail'][$p] = -1; $g['again'] = false;
            fp_charge($g, $p, null, FP_JAIL_FINE, 'JVA, dritter Versuch');
            if (!fp_settle($g)) return;
        }
    } else {
        $g['again'] = $double;
        if ($double && ++$g['dbl'] === 3) { fp_log($g, "$p würfelt den dritten Pasch."); fp_to_jail($g, $p); fp_continue($g); return; }
    }
    fp_move_to($g, $p, ($g['pos'][$p] + $sum) % 40);
    fp_resolve($g, $p);
}

function fp_finish_auction(&$g) {
    $a = $g['auction'];
    $left = array_values(array_diff(fp_active($g), $a['passed']));
    if ($a['leader'] !== null && ($left === [$a['leader']] || !$left)) {
        $g['money'][$a['leader']] -= $a['bid'];
        $g['props'][$a['field']] = ['o' => $a['leader'], 'h' => 0, 'm' => false];
        fp_log($g, $a['leader'] . ' ersteigert ' . fp_field($a['field'])['name'] . ' für ' . fp_eur($a['bid']));
    } elseif ($a['leader'] === null && !$left) {
        fp_log($g, 'Niemand bietet für ' . fp_field($a['field'])['name'] . '.');
    } else {
        return;
    }
    $g['auction'] = null;
    fp_continue($g);
}

// Removes a player: everything goes to the creditor (a player) or back to the bank (null).
function fp_bankrupt(&$g, $p, $to) {
    foreach ($g['props'] as $i => $pr) {
        if (!$pr || $pr['o'] !== $p) continue;
        if ($pr['h'] > 0) {
            $house = fp_board()['groups'][fp_field($i)['group']]['house'];
            $g['money'][$p] += intdiv($house * $pr['h'], 2);
            if ($pr['h'] === 5) $g['hotels']++; else $g['houses'] += $pr['h'];
        }
        $g['props'][$i] = $to === null ? null : ['o' => $to, 'h' => 0, 'm' => $pr['m']];
    }
    if ($to !== null) {
        $g['money'][$to] += $g['money'][$p];
        $g['jailCards'][$to] = array_merge($g['jailCards'][$to], $g['jailCards'][$p]);
    } else {
        foreach ($g['jailCards'][$p] as $deck) $g['decks'][$deck][] = array_search('jailcard', array_column(fp_board()[$deck], 'a'), true);
    }
    $g['money'][$p] = 0; $g['jailCards'][$p] = []; $g['out'][$p] = true;
    fp_log($g, "$p ist pleite" . ($to !== null ? " – alles geht an $to." : '.'));
    $g['debts'] = array_values(array_filter($g['debts'], function ($d) use ($p) { return $d['from'] !== $p && $d['to'] !== $p; }));
    if ($g['trade'] && ($g['trade']['from'] === $p || $g['trade']['to'] === $p)) $g['trade'] = null;
    $left = fp_active($g);
    if (count($left) === 1) {
        $g['winner'] = $left[0]; $g['stage'] = 'over';
        fp_log($g, $left[0] . ' gewinnt Frankfurtopoly!');
        return;
    }
    if ($g['auction']) {
        if ($g['auction']['leader'] === $p) { $g['auction']['leader'] = null; $g['auction']['bid'] = 0; }
        fp_finish_auction($g);
        return;
    }
    // Only the bankrupt player's own turn or a debt chain moves on; another player's pending decision stays.
    if (fp_cur($g) === $p) { $g['buyField'] = null; fp_continue($g); }
    elseif ($g['stage'] === 'debt') fp_continue($g);
}

function fp_net_worth($g, $p) {
    $w = $g['money'][$p];
    foreach ($g['props'] as $i => $pr) {
        if (!$pr || $pr['o'] !== $p) continue;
        $f = fp_field($i);
        $w += $pr['m'] ? intdiv($f['price'], 2) : $f['price'];
        if ($pr['h'] > 0) $w += $pr['h'] * fp_board()['groups'][$f['group']]['house'];
    }
    return $w;
}

function fp_unmortgage_cost($i) { return (int) round(fp_field($i)['price'] * 0.55); }

function fp_group_has_houses($g, $i) {
    $f = fp_field($i);
    if ($f['type'] !== 'street') return false;
    foreach (fp_group_fields($f['group']) as $j) if ($g['props'][$j] && $g['props'][$j]['h'] > 0) return true;
    return false;
}

function fp_check_trade_side($g, $owner, $fields, $money) {
    if (!is_int($money) || $money < 0 || $money > $g['money'][$owner]) return 'Nicht genug Geld für den Handel';
    foreach ($fields as $i) {
        if (!is_int($i) || $i < 0 || $i > 39 || !$g['props'][$i] || $g['props'][$i]['o'] !== $owner) return 'Feld gehört nicht ' . $owner;
        if (fp_group_has_houses($g, $i)) return 'Erst die Häuser in der Farbgruppe verkaufen';
    }
    return null;
}

// Property management (build / sell / mortgage / unmortgage). Returns error text or null.
function fp_manage(&$g, $p, $action, $i) {
    if (!is_int($i) || $i < 0 || $i > 39 || !fp_is_prop($i)) return 'Kein Grundstück';
    $pr = $g['props'][$i]; $f = fp_field($i);
    if (!$pr || $pr['o'] !== $p) return 'Das Feld gehört dir nicht';
    $house = $f['type'] === 'street' ? fp_board()['groups'][$f['group']]['house'] : 0;
    $group = $f['type'] === 'street' ? fp_group_fields($f['group']) : [$i];
    $hs = []; foreach ($group as $j) $hs[] = $g['props'][$j] ? $g['props'][$j]['h'] : 0;
    switch ($action) {
        case 'build':
            if ($f['type'] !== 'street') return 'Hier kann man nicht bauen';
            if (!fp_owns_group($g, $p, $f['group'])) return 'Du brauchst alle Straßen der Farbgruppe';
            foreach ($group as $j) if ($g['props'][$j]['m']) return 'In der Gruppe ist eine Hypothek';
            if ($pr['h'] >= 5) return 'Hier steht schon ein Hotel';
            if ($pr['h'] > min($hs)) return 'Gleichmäßig bauen: erst die anderen Straßen der Gruppe';
            if ($g['money'][$p] < $house) return 'Nicht genug Geld';
            if ($pr['h'] === 4) {
                if ($g['hotels'] < 1) return 'Keine Hotels mehr in der Bank';
                $g['hotels']--; $g['houses'] += 4;
            } else {
                if ($g['houses'] < 1) return 'Keine Häuser mehr in der Bank';
                $g['houses']--;
            }
            $g['money'][$p] -= $house; $g['props'][$i]['h']++;
            fp_log($g, "$p baut auf " . $f['name'] . ($g['props'][$i]['h'] === 5 ? ' ein Hotel' : ' ein Haus'));
            return null;
        case 'sell':
            if ($pr['h'] < 1) return 'Hier steht nichts';
            if ($pr['h'] < max($hs)) return 'Gleichmäßig verkaufen: erst die anderen Straßen der Gruppe';
            if ($pr['h'] === 5) {
                if ($g['houses'] < 4) return 'Die Bank hat keine 4 Häuser zum Tauschen';
                $g['houses'] -= 4; $g['hotels']++;
            } else {
                $g['houses']++;
            }
            $g['props'][$i]['h']--; $g['money'][$p] += intdiv($house, 2);
            fp_log($g, "$p verkauft auf " . $f['name'] . ' für ' . fp_eur(intdiv($house, 2)));
            return null;
        case 'mortgage':
            if ($pr['m']) return 'Schon belastet';
            if (fp_group_has_houses($g, $i)) return 'Erst die Häuser in der Farbgruppe verkaufen';
            $g['props'][$i]['m'] = true; $g['money'][$p] += intdiv($f['price'], 2);
            fp_log($g, "$p nimmt eine Hypothek auf " . $f['name'] . ' (+' . fp_eur(intdiv($f['price'], 2)) . ')');
            return null;
        case 'unmortgage':
            if (!$pr['m']) return 'Keine Hypothek';
            $cost = fp_unmortgage_cost($i);
            if ($g['money'][$p] < $cost) return 'Nicht genug Geld';
            $g['props'][$i]['m'] = false; $g['money'][$p] -= $cost;
            fp_log($g, "$p löst die Hypothek auf " . $f['name'] . ' ab (' . fp_eur($cost) . ')');
            return null;
    }
    return 'Unbekannte Aktion';
}

// Main entry: $p does $action with $a (decoded JSON body). Returns error text or null.
function fp_act(&$g, $p, $action, $a) {
    if ($g['stage'] === 'over') return 'Das Spiel ist vorbei';
    if (!isset($g['money'][$p]) || $g['out'][$p]) return 'Du spielst nicht (mehr) mit';
    $cur = fp_cur($g); $mine = $cur === $p; $st = $g['stage'];
    $i = isset($a['field']) ? $a['field'] : null;
    switch ($action) {
        case 'roll':
            if (!$mine || $st !== 'roll') return 'Du kannst gerade nicht würfeln';
            fp_roll($g, $p); return null;
        case 'payjail':
            if (!$mine || $st !== 'roll' || $g['jail'][$p] < 0) return 'Nicht möglich';
            if ($g['money'][$p] < FP_JAIL_FINE) return 'Nicht genug Geld';
            $g['money'][$p] -= FP_JAIL_FINE; $g['jail'][$p] = -1;
            fp_log($g, "$p zahlt " . fp_eur(FP_JAIL_FINE) . ' und verlässt die JVA.');
            return null;
        case 'usecard':
            if (!$mine || $st !== 'roll' || $g['jail'][$p] < 0 || !$g['jailCards'][$p]) return 'Nicht möglich';
            $deck = array_shift($g['jailCards'][$p]);
            $g['decks'][$deck][] = array_search('jailcard', array_column(fp_board()[$deck], 'a'), true);
            $g['jail'][$p] = -1;
            fp_log($g, "$p nutzt die Freikarte und verlässt die JVA.");
            return null;
        case 'buy':
            if (!$mine || $st !== 'buy') return 'Nicht möglich';
            $f = fp_field($g['buyField']);
            if ($g['money'][$p] < $f['price']) return 'Nicht genug Geld – versteigern oder erst Hypotheken aufnehmen';
            $g['money'][$p] -= $f['price'];
            $g['props'][$g['buyField']] = ['o' => $p, 'h' => 0, 'm' => false];
            fp_log($g, "$p kauft " . $f['name'] . ' für ' . fp_eur($f['price']));
            $g['buyField'] = null;
            fp_continue($g); return null;
        case 'decline':
            if (!$mine || $st !== 'buy') return 'Nicht möglich';
            $g['auction'] = ['field' => $g['buyField'], 'bid' => 0, 'leader' => null, 'passed' => []];
            $g['buyField'] = null; $g['stage'] = 'auction';
            fp_log($g, fp_field($g['auction']['field'])['name'] . ' wird versteigert.');
            return null;
        case 'bid':
            if ($st !== 'auction') return 'Keine Versteigerung';
            if (in_array($p, $g['auction']['passed'], true)) return 'Du bist ausgestiegen';
            $amt = isset($a['amount']) ? $a['amount'] : 0;
            if (!is_int($amt) || $amt <= $g['auction']['bid']) return 'Gebot muss höher sein';
            if ($amt > $g['money'][$p]) return 'So viel Geld hast du nicht';
            $g['auction']['bid'] = $amt; $g['auction']['leader'] = $p;
            fp_log($g, "$p bietet " . fp_eur($amt));
            fp_finish_auction($g); return null;
        case 'pass':
            if ($st !== 'auction') return 'Keine Versteigerung';
            if ($g['auction']['leader'] === $p) return 'Du hast das Höchstgebot';
            if (!in_array($p, $g['auction']['passed'], true)) $g['auction']['passed'][] = $p;
            fp_log($g, "$p steigt aus.");
            fp_finish_auction($g); return null;
        case 'endturn':
            if (!$mine || $st !== 'end') return 'Nicht möglich';
            fp_next_turn($g); return null;
        case 'build':
        case 'sell':
        case 'mortgage':
        case 'unmortgage':
            $debtor = $st === 'debt' && $g['debts'][0]['from'] === $p;
            if ($debtor) { if ($action === 'build' || $action === 'unmortgage') return 'Erst die Schulden bezahlen'; }
            elseif (!$mine || ($st !== 'roll' && $st !== 'end')) return 'Nur in deinem Zug';
            $err = fp_manage($g, $p, $action, $i);
            if ($err === null && $debtor) fp_continue($g);
            return $err;
        case 'bankrupt':
            if ($st !== 'debt' || $g['debts'][0]['from'] !== $p) return 'Nicht möglich';
            fp_bankrupt($g, $p, $g['debts'][0]['to']); return null;
        case 'offer':
            if (!$mine || ($st !== 'roll' && $st !== 'end')) return 'Handeln nur in deinem Zug';
            $to = isset($a['to']) ? $a['to'] : '';
            if ($to === $p || !isset($g['out'][$to]) || $g['out'][$to]) return 'Ungültiger Handelspartner';
            $t = ['from' => $p, 'to' => $to, 'give' => array_values(isset($a['give']) ? (array) $a['give'] : []),
                  'get' => array_values(isset($a['get']) ? (array) $a['get'] : []),
                  'giveMoney' => isset($a['giveMoney']) ? $a['giveMoney'] : 0, 'getMoney' => isset($a['getMoney']) ? $a['getMoney'] : 0];
            if (!$t['give'] && !$t['get'] && !$t['giveMoney'] && !$t['getMoney']) return 'Leeres Angebot';
            $err = fp_check_trade_side($g, $p, $t['give'], $t['giveMoney']) ?: fp_check_trade_side($g, $to, $t['get'], $t['getMoney']);
            if ($err) return $err;
            $g['trade'] = $t;
            fp_log($g, "$p macht $to ein Angebot.");
            return null;
        case 'accept':
        case 'reject':
            $t = $g['trade'];
            if (!$t || ($t['to'] !== $p && !($action === 'reject' && $t['from'] === $p))) return 'Kein Angebot';
            $g['trade'] = null;
            if ($action === 'reject') { fp_log($g, $t['from'] === $p ? "$p zieht das Angebot zurück." : "$p lehnt ab."); return null; }
            $err = fp_check_trade_side($g, $t['from'], $t['give'], $t['giveMoney']) ?: fp_check_trade_side($g, $t['to'], $t['get'], $t['getMoney']);
            if ($err) return $err;
            foreach ($t['give'] as $j) $g['props'][$j]['o'] = $t['to'];
            foreach ($t['get'] as $j) $g['props'][$j]['o'] = $t['from'];
            $g['money'][$t['from']] += $t['getMoney'] - $t['giveMoney'];
            $g['money'][$t['to']] += $t['giveMoney'] - $t['getMoney'];
            fp_log($g, "$p nimmt das Angebot von {$t['from']} an.");
            return null;
    }
    return 'Unbekannte Aktion';
}

// Removes a player who leaves the game (assets back to the bank).
function fp_leave(&$g, $p) {
    if ($g['stage'] === 'over' || !isset($g['out'][$p]) || $g['out'][$p]) return;
    $g['debts'] = array_values(array_filter($g['debts'], function ($d) use ($p) { return $d['from'] !== $p; }));
    fp_log($g, "$p verlässt das Spiel.");
    fp_bankrupt($g, $p, null);
}

// Ranking by net worth (used when the host ends the game early).
function fp_ranking($g) {
    $r = [];
    foreach ($g['order'] as $n) $r[] = ['name' => $n, 'worth' => $g['out'][$n] ? 0 : fp_net_worth($g, $n), 'out' => $g['out'][$n]];
    usort($r, function ($a, $b) { return $b['worth'] - $a['worth']; });
    return $r;
}
