<?php
// Rule tests for the Frankfurtopoly engine: php tests/engine_test.php
require __DIR__ . '/../frankfurtopoly/engine.php';
require __DIR__ . '/../frankfurtopoly/bot.php';

$fails = 0; $count = 0;
function check($cond, $msg) { global $fails, $count; $count++; if (!$cond) { $fails++; echo "FAIL: $msg\n"; } }
function game() {
    $g = fp_new_game(['Daniel', 'Lea']);
    $g['order'] = ['Daniel', 'Lea']; $g['turn'] = 0;
    return $g;
}
function dice(...$d) { $GLOBALS['FP_DICE'] = $d; }
function own(&$g, $p, ...$fields) { foreach ($fields as $i) $g['props'][$i] = ['o' => $p, 'h' => 0, 'm' => false]; }

// Board
$b = fp_board();
check(count($b['fields']) === 40, '40 Felder');
check(count($b['chance']) === 16 && count($b['chest']) === 16, '16 Karten je Stapel');
$names = array_column($b['fields'], 'name');
foreach (['Emma-Kann-Grundschule', 'Stadtgymnasium', 'Neues Gymnasium', 'Goethe-Gymnasium', 'Mainova', 'Main-Wasserwerk',
          'Europa-Allee', 'Mainzer Landstraße', 'Pariser Straße', 'Tel-Aviv-Platz'] as $n) check(in_array($n, $names, true), "Feld $n");
check(fp_field(5)['type'] === 'school' && fp_field(35)['type'] === 'school', 'Schulen auf Bahnhofsfeldern');

// Buy
$g = game(); dice([1, 2]);
check(fp_act($g, 'Lea', 'roll', []) !== null, 'Lea darf nicht würfeln');
check(fp_act($g, 'Daniel', 'roll', []) === null, 'Daniel würfelt');
check($g['pos']['Daniel'] === 3 && $g['stage'] === 'buy', 'Mainzer Landstraße kaufbar');
fp_act($g, 'Daniel', 'buy', []);
check($g['money']['Daniel'] === 1440 && $g['props'][3]['o'] === 'Daniel', 'gekauft');
check($g['stage'] === 'end', 'Zug endet');
fp_act($g, 'Daniel', 'endturn', []);
check(fp_cur($g) === 'Lea' && $g['stage'] === 'roll', 'Lea ist dran');

// Rent, doubled with full group, houses
$g = game(); own($g, 'Lea', 3); dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['money']['Daniel'] === 1496 && $g['money']['Lea'] === 1504, 'Miete 4');
$g = game(); own($g, 'Lea', 1, 3); dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['money']['Daniel'] === 1492, 'doppelte Miete bei voller Gruppe');
$g = game(); own($g, 'Lea', 1, 3); $g['props'][3]['h'] = 2; dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['money']['Daniel'] === 1440, 'Miete mit 2 Häusern = 60');

// Schools and utilities
$g = game(); own($g, 'Lea', 5, 15, 25); dice([2, 3]);
fp_act($g, 'Daniel', 'roll', []);
check($g['money']['Daniel'] === 1400, 'Schule bei 3 Schulen = 100');
$g = game(); own($g, 'Lea', 12); dice([6, 6]);
fp_act($g, 'Daniel', 'roll', []);
check($g['money']['Daniel'] === 1500 - 48 && $g['stage'] === 'roll', 'Mainova 4x12, Pasch: nochmal');
$g = game(); own($g, 'Lea', 12, 28); dice([6, 6]);
fp_act($g, 'Daniel', 'roll', []);
check($g['money']['Daniel'] === 1500 - 120, 'beide Werke 10x');

// Tax, Go
$g = game(); dice([1, 3]);
fp_act($g, 'Daniel', 'roll', []);
check($g['money']['Daniel'] === 1300, 'Einkommensteuer 200');
$g = game(); $g['pos']['Daniel'] = 38; dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['pos']['Daniel'] === 1 && $g['money']['Daniel'] === 1700 && $g['stage'] === 'buy', 'über Los +200');

// Jail
$g = game(); $g['pos']['Daniel'] = 27; dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['pos']['Daniel'] === 10 && $g['jail']['Daniel'] === 0 && $g['stage'] === 'end', 'Ab in die JVA');
fp_act($g, 'Daniel', 'endturn', []); fp_act($g, 'Lea', 'endturn', []);
$g['stage'] = 'roll'; $g['turn'] = 0;
dice([1, 2]); fp_act($g, 'Daniel', 'roll', []);
check($g['pos']['Daniel'] === 10 && $g['jail']['Daniel'] === 1 && $g['stage'] === 'end', 'kein Pasch: bleibt');
$g['stage'] = 'roll'; dice([2, 2]); fp_act($g, 'Daniel', 'roll', []);
check($g['pos']['Daniel'] === 14 && $g['jail']['Daniel'] === -1 && $g['stage'] === 'buy' && !$g['again'], 'Pasch: frei, kein Extrawurf');
$g = game(); fp_to_jail($g, 'Daniel'); $g['jail']['Daniel'] = 2; dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['money']['Daniel'] === 1450 && $g['pos']['Daniel'] === 13, '3. Versuch: 50 zahlen und ziehen');
$g = game(); fp_to_jail($g, 'Daniel');
check(fp_act($g, 'Daniel', 'payjail', []) === null && $g['jail']['Daniel'] === -1 && $g['money']['Daniel'] === 1450, 'freikaufen');
$g = game(); dice([1, 1], [2, 2], [3, 3]);
fp_act($g, 'Daniel', 'roll', []); fp_act($g, 'Daniel', 'decline', []); fp_act($g, 'Daniel', 'pass', []); fp_act($g, 'Lea', 'pass', []);
check($g['stage'] === 'roll', 'nach Pasch nochmal würfeln');
fp_act($g, 'Daniel', 'roll', []); fp_act($g, 'Daniel', 'decline', []); fp_act($g, 'Daniel', 'pass', []); fp_act($g, 'Lea', 'pass', []);
fp_act($g, 'Daniel', 'roll', []);
check($g['pos']['Daniel'] === 10 && $g['jail']['Daniel'] === 0, 'dritter Pasch: JVA');

// Auction
$g = game(); dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []); fp_act($g, 'Daniel', 'decline', []);
check($g['stage'] === 'auction', 'Versteigerung');
check(fp_act($g, 'Lea', 'bid', ['amount' => 10]) === null, 'Lea bietet');
check(fp_act($g, 'Lea', 'pass', []) !== null, 'Höchstbietende kann nicht aussteigen');
check(fp_act($g, 'Daniel', 'bid', ['amount' => 5]) !== null, 'zu niedriges Gebot');
fp_act($g, 'Daniel', 'pass', []);
check($g['props'][3]['o'] === 'Lea' && $g['money']['Lea'] === 1490 && $g['stage'] === 'end', 'Lea ersteigert für 10');

// Building
$g = game(); own($g, 'Daniel', 1, 3);
check(fp_act($g, 'Daniel', 'build', ['field' => 1]) === null, 'Haus bauen');
check(fp_act($g, 'Daniel', 'build', ['field' => 1]) !== null, 'gleichmäßig bauen');
fp_act($g, 'Daniel', 'build', ['field' => 3]);
check($g['money']['Daniel'] === 1400 && $g['houses'] === 30, 'Häuser bezahlt und aus der Bank');
check(fp_act($g, 'Daniel', 'mortgage', ['field' => 1]) !== null, 'keine Hypothek mit Häusern');
for ($k = 0; $k < 3; $k++) { fp_act($g, 'Daniel', 'build', ['field' => 1]); fp_act($g, 'Daniel', 'build', ['field' => 3]); }
fp_act($g, 'Daniel', 'build', ['field' => 1]);
check($g['props'][1]['h'] === 5 && $g['hotels'] === 11 && $g['houses'] === 28, 'Hotel: 4 Häuser zurück');
check(fp_act($g, 'Daniel', 'sell', ['field' => 3]) !== null, 'gleichmäßig verkaufen');
fp_act($g, 'Daniel', 'sell', ['field' => 1]);
check($g['props'][1]['h'] === 4 && $g['money']['Daniel'] === 1500 - 9 * 50 + 25, 'Hotel verkauft zum halben Preis');
$g = game(); own($g, 'Daniel', 1);
check(fp_act($g, 'Daniel', 'build', ['field' => 1]) !== null, 'ohne volle Gruppe kein Haus');
fp_act($g, 'Daniel', 'mortgage', ['field' => 1]);
check($g['money']['Daniel'] === 1530 && $g['props'][1]['m'], 'Hypothek +30');
fp_act($g, 'Daniel', 'unmortgage', ['field' => 1]);
check($g['money']['Daniel'] === 1497 && !$g['props'][1]['m'], 'Hypothek ablösen 33');

// Cards
$g = game(); $g['decks']['chance'] = [5]; $g['pos']['Daniel'] = 4; own($g, 'Lea', 5); dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['pos']['Daniel'] === 15 && $g['stage'] === 'buy', 'nächste Schule (Stadtgymnasium frei)');
$g = game(); $g['decks']['chance'] = [5]; $g['pos']['Daniel'] = 33; own($g, 'Lea', 5); dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['pos']['Daniel'] === 5 && $g['money']['Daniel'] === 1500 + 200 - 50, 'nächste Schule über Los, doppelte Miete');
$g = game(); $g['decks']['chest'] = [6]; dice([1, 1]);
fp_act($g, 'Daniel', 'roll', []);
check($g['money']['Daniel'] === 1510 && $g['money']['Lea'] === 1490, 'Geburtstag');
$g = game(); $g['decks']['chance'] = [9]; $g['pos']['Daniel'] = 4; dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['jailCards']['Daniel'] === ['chance'] && count($g['decks']['chance']) === 0, 'Freikarte behalten');
fp_to_jail($g, 'Daniel'); $g['stage'] = 'roll';
fp_act($g, 'Daniel', 'usecard', []);
check($g['jail']['Daniel'] === -1 && $g['decks']['chance'] === [9], 'Freikarte zurück in den Stapel');
$g = game(); $g['decks']['chance'] = [10]; $g['pos']['Daniel'] = 4; dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['pos']['Daniel'] === 4 && $g['money']['Daniel'] === 1300, '3 zurück auf Einkommensteuer');

// Debt and bankruptcy
$g = game(); own($g, 'Lea', 37, 39); $g['props'][39]['h'] = 5; $g['money']['Daniel'] = 100; own($g, 'Daniel', 1);
$g['pos']['Daniel'] = 36; dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
check($g['stage'] === 'debt' && $g['debts'][0]['amount'] === 2000, 'Schulden bei Hotel auf der Zeil');
check(fp_act($g, 'Daniel', 'endturn', []) !== null, 'mit Schulden kein Zugende');
fp_act($g, 'Daniel', 'mortgage', ['field' => 1]);
check($g['stage'] === 'debt' && $g['money']['Daniel'] === 130, 'Hypothek reicht nicht');
fp_act($g, 'Daniel', 'bankrupt', []);
check($g['out']['Daniel'] && $g['winner'] === 'Lea' && $g['stage'] === 'over' && $g['props'][1]['o'] === 'Lea' && $g['money']['Lea'] === 1630, 'Pleite: Lea gewinnt und bekommt alles');
$g = game(); own($g, 'Lea', 3); $g['money']['Daniel'] = 2; own($g, 'Daniel', 1); dice([1, 2]);
fp_act($g, 'Daniel', 'roll', []);
fp_act($g, 'Daniel', 'mortgage', ['field' => 1]);
check($g['stage'] === 'end' && $g['money']['Daniel'] === 28 && !$g['debts'], 'Schulden nach Hypothek automatisch bezahlt');

// Three players: one leaves, game goes on
$g = fp_new_game(['Daniel', 'Lea', 'Kira']); $g['order'] = ['Daniel', 'Lea', 'Kira']; $g['turn'] = 0;
own($g, 'Lea', 3);
fp_leave($g, 'Lea');
check($g['out']['Lea'] && $g['props'][3] === null && $g['stage'] === 'roll' && fp_cur($g) === 'Daniel', 'Lea geht, Feld an die Bank');
fp_leave($g, 'Daniel');
check($g['winner'] === 'Kira' && $g['stage'] === 'over', 'letzter Spieler gewinnt');

// Trade
$g = game(); own($g, 'Daniel', 1); own($g, 'Lea', 3);
check(fp_act($g, 'Daniel', 'offer', ['to' => 'Lea', 'give' => [1], 'get' => [3], 'giveMoney' => 0, 'getMoney' => 0]) === null, 'Angebot');
check(fp_act($g, 'Daniel', 'accept', []) !== null, 'nur Empfänger nimmt an');
fp_act($g, 'Lea', 'accept', []);
check($g['props'][1]['o'] === 'Lea' && $g['props'][3]['o'] === 'Daniel', 'Tausch ausgeführt');
check(fp_act($g, 'Daniel', 'offer', ['to' => 'Lea', 'give' => [], 'get' => [], 'giveMoney' => 2000, 'getMoney' => 0]) !== null, 'nicht genug Geld');

// Bots: whole games between computer opponents run without errors and without negative money
$finished = 0; $botErrors = 0; $negative = 0;
for ($run = 0; $run < 20; $run++) {
    $bots = ['Bembel', 'Handkäs', 'Kira'];
    $g = fp_new_game($bots);
    for ($k = 0; $k < 20000 && $g['stage'] !== 'over'; $k++) {
        $n = fp_bot_next($g, $bots);
        if (!$n) { $botErrors++; break; }
        if (fp_act($g, $n[0], $n[1], $n[2]) !== null) { $botErrors++; break; }
        foreach ($g['money'] as $m) if ($m < 0) $negative++;
    }
    if ($g['stage'] === 'over') $finished++;
}
check($botErrors === 0, "Bots handeln immer gültig ($botErrors Fehler)");
check($negative === 0, 'nie negatives Geld');
check($finished >= 10, "Bot-Spiele enden ($finished von 20)");
$g = game(); own($g, 'Daniel', 1); own($g, 'Lea', 3);
$g['trade'] = ['from' => 'Daniel', 'to' => 'Lea', 'give' => [1], 'get' => [3], 'giveMoney' => 0, 'getMoney' => 0];
check(!fp_bot_trade_ok($g, $g['trade']), 'Bot gibt keine Straße her, die eine Gruppe vollmacht');
$g['trade'] = ['from' => 'Daniel', 'to' => 'Lea', 'give' => [], 'get' => [3], 'giveMoney' => 200, 'getMoney' => 0];
$g['props'][1] = null;
check(fp_bot_trade_ok($g, $g['trade']), 'Bot nimmt gutes Angebot an');

echo $fails ? "$fails von $count Tests fehlgeschlagen\n" : "Alle $count Tests ok\n";
exit($fails ? 1 : 0);
