<?php
// Frankfurtopoly multiplayer API (one JSON state file with flock, no database).
//
//   GET  api.php?v=N        → state (or {"same":true} if the version is still N)
//   POST api.php {action…}  → lobby: host, join, leave, addbot, removebot, start, end, lobby, chat
//                              game:  everything fp_act() understands (roll, buy, decline, bid, …)
//
// Headers: X-FP-Name (Daniel|Lea|Kira), X-FP-Token (issued by host/join, proves the seat is yours).
// Computer opponents act server-side: at most one bot action per request and per FP_BOT_DELAY seconds.

require __DIR__ . '/engine.php';
require __DIR__ . '/bot.php';

const FP_STALE_PLAYER = 30;   // seconds without a request before a name can be taken over from another device
const FP_IDLE_RESET = 21600;  // 6 h without any action resets everything
const FP_MAX_PLAYERS = 4;
const FP_BOT_DELAY = 1.0;
const FP_AUTO_END = 30;      // seconds after which a finished turn (stage 'end') ends by itself
const FP_AUCTION_IDLE = 20;  // seconds without a new bid after which humans who haven't bid drop out
const FP_TRADE_IDLE = 30;    // seconds until an unanswered trade offer expires
const FP_AUTO_ROLL = 60;     // seconds without rolling after which the server rolls for the player
const FP_CHAT_TTL = 300;      // chat messages are deleted after 5 minutes
const FP_CHAT_MAX = 200;      // characters per message

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail($code, $message) {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = isset($_SERVER['HTTP_X_FP_NAME']) ? $_SERVER['HTTP_X_FP_NAME'] : '';
if (!in_array($name, FP_NAMES, true)) fail(400, 'Unbekannter Spielername');
$token = isset($_SERVER['HTTP_X_FP_TOKEN']) ? $_SERVER['HTTP_X_FP_TOKEN'] : '';

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true)) fail(500, 'Datenordner nicht anlegbar');
$fp = fopen($dataDir . '/state.json', 'c+');
if (!$fp) fail(500, 'Spielstand nicht lesbar');
flock($fp, LOCK_EX);

function empty_state($old = null) {
    return ['version' => $old ? $old['version'] + 1 : 1, 'phase' => 'none', 'host' => null, 'players' => [], 'game' => null,
            'result' => null, 'updated' => time(), 'chat' => $old ? $old['chat'] : [], 'chatN' => $old ? $old['chatN'] : 0,
            'logSeen' => 0, 'botAt' => 0, 'endKey' => '', 'endAt' => 0, 'aucKey' => '', 'aucAt' => 0, 'tradeKey' => '', 'tradeAt' => 0, 'rollKey' => '', 'rollAt' => 0];
}
function player_idx($st, $n) { foreach ($st['players'] as $i => $p) if ($p['name'] === $n) return $i; return -1; }
function new_token() { return bin2hex(random_bytes(16)); }
function bot_names($st) { $r = []; foreach ($st['players'] as $p) if (!empty($p['bot'])) $r[] = $p['name']; return $r; }
function human_count($st) { return count($st['players']) - count(bot_names($st)); }
function chat_add(&$st, $who, $text) {
    $st['chat'][] = ['id' => ++$st['chatN'], 't' => time(), 'n' => $who, 'x' => $text];
    $st['chat'] = array_slice($st['chat'], -80);
}
// Every game log line also becomes a (system) chat message.
function sync_log(&$st) {
    if (!$st['game']) return;
    $n = isset($st['game']['logN']) ? $st['game']['logN'] : 0;
    $new = min($n - $st['logSeen'], count($st['game']['log']));
    if ($new > 0) foreach (array_slice($st['game']['log'], -$new) as $line) chat_add($st, null, $line);
    $st['logSeen'] = $n;
}
function finish_if_over(&$st) {
    if ($st['phase'] === 'playing' && $st['game']['stage'] === 'over') { $st['phase'] = 'finished'; $st['result'] = fp_ranking($st['game']); }
}

$st = json_decode(stream_get_contents($fp), true);
if (!is_array($st) || !isset($st['phase'])) $st = empty_state();
$st += ['chat' => [], 'chatN' => 0, 'logSeen' => 0, 'botAt' => 0, 'endKey' => '', 'endAt' => 0, 'aucKey' => '', 'aucAt' => 0, 'tradeKey' => '', 'tradeAt' => 0, 'rollKey' => '', 'rollAt' => 0];
$now = time();
$dirty = false;
if ($st['phase'] !== 'none' && $now - $st['updated'] > FP_IDLE_RESET) { $st = empty_state($st); $dirty = true; }
$before = count($st['chat']);
$st['chat'] = array_values(array_filter($st['chat'], function ($m) use ($now) { return $now - $m['t'] < FP_CHAT_TTL; }));
if (count($st['chat']) !== $before) $dirty = true;

$me = player_idx($st, $name);
$authed = $me >= 0 && $token !== '' && hash_equals($st['players'][$me]['token'], $token);
if ($authed) {
    $st['players'][$me]['seen'] = $now;
    $dirty = true;
}

// Online markers only bump the version when they flip, so polling clients aren't woken for nothing.
foreach ($st['players'] as $i => $p) {
    if (!empty($p['bot'])) continue;
    $on = $now - $p['seen'] <= FP_STALE_PLAYER;
    if ($on !== $p['online']) { $st['players'][$i]['online'] = $on; $st['version']++; $dirty = true; }
}

$issued = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['action'])) fail(400, 'Erwartet {"action": …}');
    $action = $body['action'];
    $err = null;
    $isHost = $authed && $st['host'] === $name;
    switch ($action) {
        case 'host':
        case 'join':
            if ($action === 'host' && $st['phase'] !== 'none') { $err = 'Es gibt schon eine Runde'; break; }
            if ($action === 'join' && $st['phase'] === 'none') { $err = 'Niemand hostet gerade'; break; }
            if ($me >= 0) {
                // Same seat again (reload / other device): allowed with the token or after the old device went quiet.
                if (!$authed && $now - $st['players'][$me]['seen'] <= FP_STALE_PLAYER) { $err = "$name ist schon an einem anderen Gerät aktiv"; break; }
                $issued = new_token();
                $st['players'][$me]['token'] = $issued; $st['players'][$me]['seen'] = $now; $st['players'][$me]['online'] = true;
                break;
            }
            if ($st['phase'] === 'playing' || $st['phase'] === 'finished') { $err = 'Die Runde läuft schon'; break; }
            if (count($st['players']) >= FP_MAX_PLAYERS) { $err = 'Die Runde ist voll – der Host kann einen Computergegner entfernen'; break; }
            $issued = new_token();
            $st['players'][] = ['name' => $name, 'token' => $issued, 'seen' => $now, 'online' => true];
            if ($action === 'host') { $st['phase'] = 'lobby'; $st['host'] = $name; chat_add($st, null, "$name hostet eine Runde."); }
            else chat_add($st, null, "$name spielt mit.");
            break;
        case 'leave':
            if (!$authed) { $err = 'Du bist nicht in der Runde'; break; }
            array_splice($st['players'], $me, 1);
            chat_add($st, null, "$name hat die Runde verlassen.");
            if ($st['phase'] === 'playing') { fp_leave($st['game'], $name); sync_log($st); finish_if_over($st); }
            if (human_count($st) === 0) { $st = empty_state($st); break; }
            if ($st['host'] === $name) foreach ($st['players'] as $p) if (empty($p['bot'])) { $st['host'] = $p['name']; break; }
            break;
        case 'addbot':
            if (!$isHost || $st['phase'] !== 'lobby') { $err = 'Nur der Host in der Lobby'; break; }
            if (count($st['players']) >= FP_MAX_PLAYERS) { $err = 'Maximal ' . FP_MAX_PLAYERS . ' Spieler'; break; }
            $free = array_values(array_diff(FP_BOT_NAMES, bot_names($st)));
            if (!$free) { $err = 'Keine weiteren Computergegner'; break; }
            $st['players'][] = ['name' => $free[0], 'token' => '', 'seen' => $now, 'online' => true, 'bot' => true];
            chat_add($st, null, "Computergegner {$free[0]} spielt mit.");
            break;
        case 'removebot':
            if (!$isHost || $st['phase'] !== 'lobby') { $err = 'Nur der Host in der Lobby'; break; }
            $i = player_idx($st, isset($body['bot']) ? $body['bot'] : '');
            if ($i < 0 || empty($st['players'][$i]['bot'])) { $err = 'Kein Computergegner'; break; }
            array_splice($st['players'], $i, 1);
            break;
        case 'start':
            if (!$isHost) { $err = 'Nur der Host kann starten'; break; }
            if ($st['phase'] !== 'lobby') { $err = 'Keine Lobby'; break; }
            if (count($st['players']) < 2) { $err = 'Mindestens 2 Spieler'; break; }
            $st['game'] = fp_new_game(array_column($st['players'], 'name'));
            $st['phase'] = 'playing'; $st['result'] = null; $st['logSeen'] = 0;
            chat_add($st, null, 'Das Spiel beginnt!');
            sync_log($st);
            break;
        case 'end':
            if (!$isHost) { $err = 'Nur der Host kann beenden'; break; }
            if ($st['phase'] === 'playing') {
                fp_log($st['game'], "$name beendet das Spiel.");
                sync_log($st);
                $st['phase'] = 'finished'; $st['result'] = fp_ranking($st['game']);
                chat_add($st, null, $st['result'][0]['name'] . ' gewinnt nach Vermögen.');
            } else {
                $st = empty_state($st);
            }
            break;
        case 'lobby':
            // After a finished game: same players back to the lobby for a new round.
            if (!$isHost || $st['phase'] !== 'finished') { $err = 'Nicht möglich'; break; }
            $st['phase'] = 'lobby'; $st['game'] = null; $st['result'] = null;
            break;
        case 'chat':
            $text = trim(isset($body['text']) && is_string($body['text']) ? $body['text'] : '');
            if (!$authed) { $err = 'Erst der Runde beitreten'; break; }
            if ($text === '') { $err = 'Leere Nachricht'; break; }
            chat_add($st, $name, function_exists('mb_substr') ? mb_substr($text, 0, FP_CHAT_MAX) : substr($text, 0, FP_CHAT_MAX));
            break;
        default:
            if (!$authed) { $err = 'Du bist nicht in der Runde'; break; }
            if ($st['phase'] !== 'playing') { $err = 'Es läuft kein Spiel'; break; }
            $err = fp_act($st['game'], $name, $action, $body);
            if ($err === null) {
                sync_log($st);
                if ($st['game']['stage'] === 'over') chat_add($st, null, $st['game']['winner'] . ' gewinnt Frankfurtopoly!');
                finish_if_over($st);
            }
    }
    if ($err !== null) {
        if ($dirty) save($fp, $st);
        fail(409, $err);
    }
    $st['version']++; $st['updated'] = $now; $dirty = true;
    $st['botAt'] = microtime(true) + FP_BOT_DELAY;   // give humans a moment to see what happened
    // Anything the player on turn does restarts the auto-end countdown.
    if ($st['phase'] === 'playing' && fp_cur($st['game']) === $name) { $st['endKey'] = ''; $st['rollKey'] = ''; }
} elseif ($st['phase'] === 'playing' && $st['game']['stage'] === 'roll' && $st['rollKey'] !== '' && $now >= $st['rollAt']
          && !in_array(fp_cur($st['game']), bot_names($st), true)) {
    // Nobody rolled for FP_AUTO_ROLL seconds: the server rolls (in the JVA this is the attempt to roll a double).
    $cur = fp_cur($st['game']);
    fp_log($st['game'], "$cur: automatisch gewürfelt.");
    fp_act($st['game'], $cur, 'roll', []);
    sync_log($st);
    if ($st['game']['stage'] === 'over') chat_add($st, null, $st['game']['winner'] . ' gewinnt Frankfurtopoly!');
    finish_if_over($st);
    $st['rollKey'] = '';
    $st['version']++; $st['updated'] = $now; $st['botAt'] = microtime(true) + FP_BOT_DELAY; $dirty = true;
} elseif ($st['phase'] === 'playing' && $st['game']['trade'] && $st['tradeKey'] !== '' && $now >= $st['tradeAt']) {
    // Unanswered trade offer expires.
    $t = $st['game']['trade'];
    $st['game']['trade'] = null;
    fp_log($st['game'], "Das Angebot von {$t['from']} an {$t['to']} ist abgelaufen.");
    sync_log($st);
    $st['tradeKey'] = '';
    $st['version']++; $st['updated'] = $now; $dirty = true;
} elseif ($st['phase'] === 'playing' && $st['game']['stage'] === 'auction' && $st['aucKey'] !== '' && $now >= $st['aucAt']) {
    // Auction: humans who haven't bid for FP_AUCTION_IDLE seconds drop out (the high bidder stays; bots decide themselves).
    $a = $st['game']['auction']; $bots = bot_names($st);
    foreach (fp_active($st['game']) as $n) {
        if (in_array($n, $bots, true) || $n === $a['leader'] || in_array($n, $a['passed'], true)) continue;
        if ($st['game']['stage'] !== 'auction') break;
        fp_log($st['game'], "$n hat " . FP_AUCTION_IDLE . ' s nicht geboten:');
        fp_act($st['game'], $n, 'pass', []);
    }
    sync_log($st);
    $st['aucKey'] = '';
    $st['version']++; $st['updated'] = $now; $st['botAt'] = microtime(true) + FP_BOT_DELAY; $dirty = true;
} elseif ($st['phase'] === 'playing' && $st['game']['stage'] === 'end' && $st['endKey'] !== '' && $now >= $st['endAt']
          && !in_array(fp_cur($st['game']), bot_names($st), true)) {
    // Turn finished but nobody pressed "Zug beenden" for FP_AUTO_END seconds.
    $cur = fp_cur($st['game']);
    fp_log($st['game'], "$cur: Zug automatisch beendet.");
    fp_act($st['game'], $cur, 'endturn', []);
    sync_log($st);
    $st['version']++; $st['updated'] = $now; $st['botAt'] = microtime(true) + FP_BOT_DELAY; $dirty = true;
} elseif ($st['phase'] === 'playing' && microtime(true) >= $st['botAt']) {
    $bots = bot_names($st);
    $next = $bots ? fp_bot_next($st['game'], $bots) : null;
    if ($next) {
        list($bot, $act, $args) = $next;
        $err = fp_act($st['game'], $bot, $act, $args);
        // Safety net: never let a bot block the game.
        if ($err !== null) foreach (['endturn', 'pass', 'reject', 'decline', 'roll', 'bankrupt'] as $alt) if (fp_act($st['game'], $bot, $alt, []) === null) break;
        sync_log($st);
        if ($st['game']['stage'] === 'over') chat_add($st, null, $st['game']['winner'] . ' gewinnt Frankfurtopoly!');
        finish_if_over($st);
        $st['version']++; $st['updated'] = $now; $st['botAt'] = microtime(true) + FP_BOT_DELAY; $dirty = true;
    }
}

// Trade countdown: starts with every new offer.
if ($st['phase'] === 'playing' && $st['game']['trade']) {
    $key = json_encode($st['game']['trade']);
    if ($st['tradeKey'] !== $key) { $st['tradeKey'] = $key; $st['tradeAt'] = $now + FP_TRADE_IDLE; $dirty = true; }
} elseif ($st['tradeKey'] !== '') {
    $st['tradeKey'] = ''; $dirty = true;
}

// Auction countdown: restarts with every new high bid.
if ($st['phase'] === 'playing' && $st['game']['stage'] === 'auction') {
    $a = $st['game']['auction'];
    $key = $a['field'] . '|' . $a['bid'] . '|' . $a['leader'];
    if ($key !== $st['aucKey']) { $st['aucKey'] = $key; $st['aucAt'] = $now + FP_AUCTION_IDLE; $dirty = true; }
} elseif ($st['aucKey'] !== '') {
    $st['aucKey'] = ''; $dirty = true;
}

// Countdown for the auto roll: starts when a human player has to roll.
if ($st['phase'] === 'playing' && $st['game']['stage'] === 'roll' && !in_array(fp_cur($st['game']), bot_names($st), true)) {
    if ($st['rollKey'] === '' || $st['rollKey'] !== fp_cur($st['game'])) { $st['rollKey'] = fp_cur($st['game']); $st['rollAt'] = $now + FP_AUTO_ROLL; $dirty = true; }
} elseif ($st['rollKey'] !== '') {
    $st['rollKey'] = ''; $dirty = true;
}

// Countdown for the auto end: starts when a human player's turn reaches stage 'end'.
if ($st['phase'] === 'playing' && $st['game']['stage'] === 'end') {
    $key = fp_cur($st['game']) . '|' . $st['game']['turn'] . '|' . (isset($st['game']['logN']) ? $st['game']['logN'] : 0);
    if ($st['endKey'] === '' || explode('|', $st['endKey'])[0] !== fp_cur($st['game'])) {
        $st['endKey'] = $key; $st['endAt'] = $now + FP_AUTO_END; $dirty = true;
    }
} elseif ($st['endKey'] !== '') {
    $st['endKey'] = ''; $dirty = true;
}

function save($fp, $st) {
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($st, JSON_UNESCAPED_UNICODE));
    fflush($fp);
}
if ($dirty) save($fp, $st);
flock($fp, LOCK_UN);
fclose($fp);

if (isset($_GET['v']) && (int) $_GET['v'] === $st['version'] && $issued === null) { echo '{"same":true}'; exit; }

$players = [];
foreach ($st['players'] as $p) $players[] = ['name' => $p['name'], 'online' => $p['online'], 'bot' => !empty($p['bot'])];
$out = ['version' => $st['version'], 'phase' => $st['phase'], 'host' => $st['host'], 'players' => $players,
        'game' => $st['game'], 'result' => $st['result'], 'me' => $name, 'joined' => $authed || $issued !== null,
        'chat' => $st['chat'], 'now' => $now, 'autoEnd' => $st['endKey'] !== '' ? $st['endAt'] : null,
        'auctionEnd' => $st['aucKey'] !== '' ? $st['aucAt'] : null, 'tradeEnd' => $st['tradeKey'] !== '' ? $st['tradeAt'] : null,
        'rollEnd' => $st['rollKey'] !== '' ? $st['rollAt'] : null];
if ($out['game']) unset($out['game']['decks']);
if ($issued !== null) $out['token'] = $issued;
echo json_encode($out, JSON_UNESCAPED_UNICODE);
