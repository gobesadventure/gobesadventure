<?php
// GOBE’S ADVENTURE — tiny multiplayer backend (players presence + chat)
// Storage: JSON file with file locking. Works on shared hosting (Hostinger).
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$file = __DIR__ . '/goblin_mp.json';
$in = json_decode(file_get_contents('php://input'), true);
if (!$in || !isset($in['a'])) { echo '{}'; exit; }

$fp = fopen($file, 'c+');
if (!$fp) { echo '{}'; exit; }
flock($fp, LOCK_EX);
$raw = stream_get_contents($fp);
$db = $raw ? json_decode($raw, true) : null;
if (!is_array($db)) $db = array('players' => array(), 'chat' => array(), 'cid' => 0);

$now = time();
$a = $in['a'];

if ($a === 'state') {
    $id = substr(preg_replace('/[^a-z0-9_]/i', '', $in['id'] ?? ''), 0, 24);
    if ($id) {
        $db['players'][$id] = array(
            'name'  => strtoupper(substr($in['name'] ?? '?', 0, 14)),
            'skin'  => substr($in['skin'] ?? 'NOVA', 0, 10),
            'x'     => round(floatval($in['x'] ?? 0), 2),
            'y'     => round(floatval($in['y'] ?? 0), 2),
            'level' => max(1, min(6, intval($in['level'] ?? 1))),
            'fish'  => !empty($in['fish']) ? 1 : 0,
            't'     => $now
        );
    }
} elseif ($a === 'chat') {
    $text = trim(substr($in['text'] ?? '', 0, 140));
    if ($text !== '') {
        $db['cid'] = intval($db['cid']) + 1;
        $db['chat'][] = array(
            'i'     => $db['cid'],
            'name'  => strtoupper(substr($in['name'] ?? '?', 0, 14)),
            'level' => max(1, min(6, intval($in['level'] ?? 1))),
            'text'  => $text,
            'to'    => !empty($in['to']) ? strtoupper(substr($in['to'], 0, 14)) : null,
            't'     => $now
        );
        if (count($db['chat']) > 60) $db['chat'] = array_slice($db['chat'], -60);
    }
}

// prune players idle > 15s
foreach ($db['players'] as $k => $p) {
    if ($now - $p['t'] > 15) unset($db['players'][$k]);
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($db));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

$last = intval($in['last'] ?? 0);
$chatOut = array();
foreach ($db['chat'] as $c) {
    if ($c['i'] > $last) $chatOut[] = $c;
}
echo json_encode(array('players' => $db['players'], 'chat' => $chatOut));
