<?php
// Frankfurtopoly multiplayer API (one JSON state file with flock, no database).
//
//   GET  api.php?v=N        → state (or {"same":true} if the version is still N)
//   POST api.php {action…}  → lobby: host, join, leave, start, end, lobby
//                              game:  everything fp_act() understands (roll, buy, decline, bid, …)
//
// Headers: X-FP-Name (Daniel|Lea|Kira), X-FP-Token (issued by host/join, proves the seat is yours).

require __DIR__ . '/engine.php';

const FP_STALE_PLAYER = 30;   // seconds without a request before a name can be taken over from another device
const FP_IDLE_RESET = 21600;  // 6 h without any action resets everything

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

function empty_state() {
    return ['version' => 1, 'phase' => 'none', 'host' => null, 'players' => [], 'game' => null,
            'result' => null, 'updated' => time()];
}
function player_idx($st, $n) { foreach ($st['players'] as $i => $p) if ($p['name'] === $n) return $i; return -1; }
function new_token() { return bin2hex(random_bytes(16)); }

$st = json_decode(stream_get_contents($fp), true);
if (!is_array($st) || !isset($st['phase'])) $st = empty_state();
$now = time();
$dirty = false;
if ($st['phase'] !== 'none' && $now - $st['updated'] > FP_IDLE_RESET) { $v = $st['version']; $st = empty_state(); $st['version'] = $v + 1; $dirty = true; }

$me = player_idx($st, $name);
$authed = $me >= 0 && $token !== '' && hash_equals($st['players'][$me]['token'], $token);
if ($authed) {
    $st['players'][$me]['seen'] = $now;
    $dirty = true;
}

// Online markers only bump the version when they flip, so polling clients aren't woken for nothing.
foreach ($st['players'] as $i => $p) {
    $on = $now - $p['seen'] <= FP_STALE_PLAYER;
    if ($on !== $p['online']) { $st['players'][$i]['online'] = $on; $st['version']++; $dirty = true; }
}

$issued = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['action'])) fail(400, 'Erwartet {"action": …}');
    $action = $body['action'];
    $err = null;
    $changed = true;
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
            $issued = new_token();
            $st['players'][] = ['name' => $name, 'token' => $issued, 'seen' => $now, 'online' => true];
            if ($action === 'host') { $st['phase'] = 'lobby'; $st['host'] = $name; }
            break;
        case 'leave':
            if (!$authed) { $err = 'Du bist nicht in der Runde'; break; }
            array_splice($st['players'], $me, 1);
            if ($st['phase'] === 'playing') {
                fp_leave($st['game'], $name);
                if ($st['game']['stage'] === 'over') { $st['phase'] = 'finished'; $st['result'] = fp_ranking($st['game']); }
            }
            if (!$st['players']) { $v = $st['version']; $st = empty_state(); $st['version'] = $v; break; }
            if ($st['host'] === $name) $st['host'] = $st['players'][0]['name'];
            break;
        case 'start':
            if (!$authed || $st['host'] !== $name) { $err = 'Nur der Host kann starten'; break; }
            if ($st['phase'] !== 'lobby') { $err = 'Keine Lobby'; break; }
            if (count($st['players']) < 2) { $err = 'Mindestens 2 Spieler'; break; }
            $st['game'] = fp_new_game(array_column($st['players'], 'name'));
            $st['phase'] = 'playing'; $st['result'] = null;
            break;
        case 'end':
            if (!$authed || $st['host'] !== $name) { $err = 'Nur der Host kann beenden'; break; }
            if ($st['phase'] === 'playing') {
                fp_log($st['game'], "$name beendet das Spiel.");
                $st['phase'] = 'finished'; $st['result'] = fp_ranking($st['game']);
            } else {
                $v = $st['version']; $st = empty_state(); $st['version'] = $v;
            }
            break;
        case 'lobby':
            // After a finished game: same players back to the lobby for a new round.
            if (!$authed || $st['host'] !== $name || $st['phase'] !== 'finished') { $err = 'Nicht möglich'; break; }
            $st['phase'] = 'lobby'; $st['game'] = null; $st['result'] = null;
            break;
        default:
            if (!$authed) { $err = 'Du bist nicht in der Runde'; break; }
            if ($st['phase'] !== 'playing') { $err = 'Es läuft kein Spiel'; break; }
            $err = fp_act($st['game'], $name, $action, $body);
            if ($err === null && $st['game']['stage'] === 'over') { $st['phase'] = 'finished'; $st['result'] = fp_ranking($st['game']); }
    }
    if ($err !== null) {
        if ($dirty) save($fp, $st);
        fail(409, $err);
    }
    $st['version']++; $st['updated'] = $now; $dirty = true;
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
foreach ($st['players'] as $p) $players[] = ['name' => $p['name'], 'online' => $p['online']];
$out = ['version' => $st['version'], 'phase' => $st['phase'], 'host' => $st['host'], 'players' => $players,
        'game' => $st['game'], 'result' => $st['result'], 'me' => $name, 'joined' => $authed || $issued !== null];
if ($out['game']) unset($out['game']['decks']);
if ($issued !== null) $out['token'] = $issued;
echo json_encode($out, JSON_UNESCAPED_UNICODE);
