<?php
// GOBE’S ADVENTURE — backend API: wallet auth, server profiles, $GOBE claims, AI mascot
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cfg = require __DIR__ . '/config.php';
$in = json_decode(file_get_contents('php://input'), true);
if (!$in || !isset($in['a'])) { echo json_encode(array('error' => 'bad-request')); exit; }

// ---------- helpers ----------
function out($x) { echo json_encode($x); exit; }

// base58 decode WITHOUT bcmath (byte-array big-int) — works on any PHP host
function b58decode($s) {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $bytes = array();
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $p = strpos($alphabet, $s[$i]);
        if ($p === false) return false;
        $carry = $p;
        for ($j = 0; $j < count($bytes); $j++) {
            $carry += $bytes[$j] * 58;
            $bytes[$j] = $carry & 0xff;
            $carry >>= 8;
        }
        while ($carry > 0) { $bytes[] = $carry & 0xff; $carry >>= 8; }
    }
    for ($i = 0; $i < $len && $s[$i] === '1'; $i++) $bytes[] = 0; // leading '1' = leading zero byte
    $bin = '';
    for ($i = count($bytes) - 1; $i >= 0; $i--) $bin .= chr($bytes[$i]);
    return $bin;
}

function verify_auth($auth, $cfg) {
    if (!is_array($auth) || empty($auth['wallet']) || empty($auth['ts']) || empty($auth['sig'])) return false;
    if (abs(time() - intval($auth['ts'])) > $cfg['AUTH_WINDOW']) return false;
    if (!function_exists('sodium_crypto_sign_verify_detached')) return false;
    $pk = b58decode($auth['wallet']);
    if ($pk === false || strlen($pk) !== 32) return false;
    $sig = base64_decode($auth['sig']);
    if (strlen($sig) !== 64) return false;
    $msg = "GOBE’S ADVENTURE LOGIN\nwallet: " . $auth['wallet'] . "\nts: " . $auth['ts'];
    try { return sodium_crypto_sign_verify_detached($sig, $msg, $pk); } catch (Exception $e) { return false; }
}

function load_db($file) {
    $fp = fopen($file, 'c+');
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $db = $raw ? json_decode($raw, true) : null;
    if (!is_array($db)) $db = array();
    return array($fp, $db);
}
function save_db($fp, $db) {
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($db));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
}

// the player's wallet address — identifies them. no signature needed (keeps it simple, works on any host).
// accepts $in['wallet'] or a legacy $in['auth']['wallet']; must look like a Solana pubkey.
function wallet_in($in) {
    $raw = $in['wallet'] ?? (isset($in['auth']['wallet']) ? $in['auth']['wallet'] : '');
    $w = preg_replace('/[^1-9A-HJ-NP-Za-km-z]/', '', $raw);
    return strlen($w) >= 32 ? $w : false;
}

// $GOBE points are deterministic from level — mirror the client (XP_THRESH / VPTS_REWARD).
// Computing them server-side makes claims idempotent & un-spoofable (no trusting client vpts).
function level_from_xp($xp) {
    $thr = array(0, 0, 120, 320, 620, 1000, 1500); // index = level
    $l = 1;
    for ($i = 2; $i < count($thr); $i++) if ($xp >= $thr[$i]) $l = $i;
    return $l;
}
function vpts_earned_for_level($level) {
    $t = 0;                                  // LV2 +10k, LV3 +20k, … cumulative
    for ($l = 2; $l <= $level; $l++) $t += 10000 * ($l - 1);
    return $t;
}

// Robust claim-receipt mailer: multipart text+HTML, full RFC headers, and the
// envelope-sender (-f) param — the single biggest fix for mail() not delivering
// on shared hosting (Hostinger etc). Logs every attempt for debugging.
function send_claim_mail($email, $name, $wallet, $amount, $cfg) {
    $from   = $cfg['MAIL_FROM'];
    $fromNm = $cfg['MAIL_FROM_NAME'];
    $site   = $cfg['SITE_NAME'];
    $url    = $cfg['SITE_URL'];
    $launch = $cfg['LAUNCH_INFO'];
    $amtStr = number_format($amount);
    $domain = preg_match('/@(.+)$/', $from, $m) ? $m[1] : 'gobesadventure.xyz';

    $ref = 'GOBLIN-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $wallet), 0, 8));
    $subject = 'Your GOBLIN claim — ' . $amtStr . ' $GOBE';

    $text = "GM " . $name . "!\r\n\r\n"
          . "Your \$GOBE claim has been recorded in " . $site . ". Summary:\r\n\r\n"
          . "  Points collected : " . $amtStr . " \$GOBE\r\n"
          . "  Wallet           : " . $wallet . "\r\n"
          . "  Referral code    : " . $ref . "\r\n\r\n"
          . "AIRDROP: your \$GOBE will be airdropped to this wallet 12 HOURS AFTER the token launch (launch: " . $launch . ", Solana). No further action is needed.\r\n\r\n"
          . "Share your referral code " . $ref . " - when friends join GOBE’S ADVENTURE with it, you both earn bonus rewards.\r\n\r\n"
          . "Keep grinding the grid - more levels, more \$GOBE.\r\n\r\n"
          . "- " . $site . "\r\n" . $url . "\r\n";

    $esc = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $html = '<!doctype html><html><body style="margin:0;background:#05080f;font-family:Arial,Helvetica,sans-serif;color:#e9fffb;">'
          . '<div style="max-width:520px;margin:0 auto;padding:28px 24px;">'
          . '<div style="font-size:22px;font-weight:800;letter-spacing:2px;color:#5eead4;">GOBE’S ADVENTURE</div>'
          . '<div style="height:2px;background:linear-gradient(90deg,#5eead4,transparent);margin:10px 0 22px;"></div>'
          . '<p style="font-size:15px;">GM <b>' . $esc($name) . '</b>! Your claim is locked in. You collected</p>'
          . '<div style="font-size:30px;font-weight:800;color:#5eead4;margin:6px 0 16px;">' . $amtStr . ' $GOBE</div>'
          . '<table style="width:100%;font-size:13px;color:#9fb6c2;border-collapse:collapse;">'
          . '<tr><td style="padding:5px 0;">Points collected</td><td style="padding:5px 0;color:#e9fffb;">' . $amtStr . ' $GOBE</td></tr>'
          . '<tr><td style="padding:5px 0;">Wallet</td><td style="padding:5px 0;color:#e9fffb;word-break:break-all;">' . $esc($wallet) . '</td></tr>'
          . '</table>'
          . '<div style="margin:18px 0;padding:14px 16px;background:rgba(252,211,77,0.08);border:1px solid rgba(252,211,77,0.35);border-radius:10px;">'
          . '<div style="font-size:12px;color:#fcd34d;font-weight:700;letter-spacing:1px;">&#9203; AIRDROP SCHEDULE</div>'
          . '<div style="font-size:13px;color:#e9fffb;line-height:1.6;margin-top:4px;">Your $GOBE will be airdropped to your wallet <b>12 hours after the token launch</b> (launch: ' . $esc($launch) . ', Solana). No further action is needed.</div>'
          . '</div>'
          . '<div style="margin:18px 0;padding:14px 16px;background:rgba(94,234,212,0.08);border:1px solid rgba(94,234,212,0.35);border-radius:10px;">'
          . '<div style="font-size:12px;color:#5eead4;font-weight:700;letter-spacing:1px;">&#127873; YOUR REFERRAL CODE</div>'
          . '<div style="font-size:22px;color:#fff;font-weight:800;letter-spacing:2px;margin:6px 0;">' . $esc($ref) . '</div>'
          . '<div style="font-size:12px;color:#9fb6c2;line-height:1.6;">Share it - when friends join GOBE’S ADVENTURE with your code, you both earn bonus rewards.</div>'
          . '</div>'
          . '<a href="' . $esc($url) . '" style="display:inline-block;margin-top:4px;background:#5eead4;color:#04130f;font-weight:700;text-decoration:none;padding:10px 20px;border-radius:8px;font-size:13px;">PLAY GOBE’S ADVENTURE &#9656;</a>'
          . '<div style="margin-top:24px;font-size:11px;color:#5d8d96;">' . $esc($site) . ' · ' . $esc($url) . '</div>'
          . '</div></body></html>';

    $boundary = 'goblin_' . md5($wallet . $amount . $from);
    $mid = '<' . $boundary . '@' . $domain . '>';
    $headers  = 'From: ' . $fromNm . ' <' . $from . ">\r\n";
    $headers .= 'Reply-To: ' . $from . "\r\n";
    $headers .= 'Return-Path: ' . $from . "\r\n";
    $headers .= 'Message-ID: ' . $mid . "\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= 'X-Mailer: GOBLIN-WORLD' . "\r\n";
    $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";

    $msg  = '--' . $boundary . "\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n";
    $msg .= '--' . $boundary . "\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n";
    $msg .= '--' . $boundary . "--\r\n";

    $sent = false; $via = 'none';
    // 1) SMTP (most reliable on shared hosting) — fill SMTP_* in config.php to enable
    if (!empty($cfg['SMTP_HOST']) && !empty($cfg['SMTP_USER'])) {
        $sent = smtp_send($cfg, $email, $subject, $headers, $msg);
        $via = $sent ? 'smtp' : 'smtp-fail';
    }
    // 2) fall back to PHP mail()
    if (!$sent && function_exists('mail')) {
        $sent = @mail($email, $subject, $msg, $headers, '-f' . $from);   // -f sets the envelope sender
        if (!$sent) $sent = @mail($email, $subject, $msg, $headers);     // retry without -f if the host blocks it
        $via = $sent ? 'mail' : 'mail-fail';
    }
    @file_put_contents($cfg['DATA_DIR'] . '/goblin_mail.log',
        date('c') . "\t" . ($sent ? 'OK' : 'FAIL') . "\t" . $via . "\t" . $email . "\t" . $amtStr . " \$GOBE\t" . $wallet . "\n",
        FILE_APPEND | LOCK_EX);
    return $sent;
}

// minimal SMTP sender (no library) — works with Hostinger/Gmail mailbox credentials
function smtp_send($cfg, $to, $subject, $headers, $body) {
    $host = $cfg['SMTP_HOST']; $port = intval($cfg['SMTP_PORT'] ?? 587);
    $user = $cfg['SMTP_USER']; $pass = $cfg['SMTP_PASS'] ?? ''; $from = $cfg['MAIL_FROM'];
    $fp = @fsockopen(($port == 465 ? 'ssl://' : '') . $host, $port, $e, $s, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) { $d = ''; while (($l = fgets($fp, 515)) !== false) { $d .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $d; };
    $cmd = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
    $ok = function ($r, $code) { return strpos($r, $code) === 0; };
    $read();
    $cmd('EHLO ' . $host);
    if ($port == 587) { // STARTTLS for port 587
        $r = $cmd('STARTTLS');
        if (!$ok($r, '220') || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) { fclose($fp); return false; }
        $cmd('EHLO ' . $host);
    }
    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $r = $cmd(base64_encode($pass));
    if (!$ok($r, '235')) { @fwrite($fp, "QUIT\r\n"); fclose($fp); return false; } // auth failed
    $cmd('MAIL FROM:<' . $from . '>');
    $cmd('RCPT TO:<' . $to . '>');
    $r = $cmd('DATA');
    if (!$ok($r, '354')) { @fwrite($fp, "QUIT\r\n"); fclose($fp); return false; }
    $payload = 'To: ' . $to . "\r\n" . 'Subject: ' . $subject . "\r\n" . $headers . "\r\n" . $body;
    $payload = preg_replace('/^\./m', '..', $payload); // SMTP dot-stuffing
    $r = $cmd($payload . "\r\n.");
    $cmd('QUIT'); fclose($fp);
    return $ok($r, '250');
}

$PLAYERS = $cfg['DATA_DIR'] . '/goblin_players.json';
$CLAIMS  = $cfg['DATA_DIR'] . '/goblin_claims.json';
$a = $in['a'];

// ---------- actions ----------
// quick diagnostic — POST {"a":"health"} to see what the host can/can't do.
if ($a === 'health') {
    out(array(
        'ok' => 1,
        'php' => PHP_VERSION,
        'bcmath' => function_exists('bcadd'),                          // needed for wallet auth
        'sodium' => function_exists('sodium_crypto_sign_verify_detached'), // needed for wallet auth
        'curl'   => function_exists('curl_init'),                      // needed for AI chat
        'mail'   => function_exists('mail'),                           // needed for claim email
        'data_writable' => is_writable($cfg['DATA_DIR']),              // needed to save profiles/claims
        'ai_key_set' => !empty($cfg['ANTHROPIC_API_KEY']),
    ));
}

if ($a === 'profile_get') {
    $w = preg_replace('/[^1-9A-HJ-NP-Za-km-z]/', '', $in['wallet'] ?? '');
    if (!$w) out(array('error' => 'no-wallet'));
    list($fp, $db) = load_db($PLAYERS);
    flock($fp, LOCK_UN); fclose($fp);
    out(array('profile' => isset($db[$w]) ? $db[$w] : null));
}

if ($a === 'profile_save') {
    $w = wallet_in($in); if (!$w) out(array('error' => 'no-wallet'));
    $p = $in['profile'] ?? null;
    if (!is_array($p) || empty($p['name'])) out(array('error' => 'bad-profile'));
    $name = strtoupper(substr(preg_replace('/[^A-Za-z0-9 _\-]/', '', $p['name']), 0, 12));
    list($fp, $db) = load_db($PLAYERS);
    foreach ($db as $ow => $op) {
        if ($ow !== $w && isset($op['name']) && $op['name'] === $name) {
            flock($fp, LOCK_UN); fclose($fp);
            out(array('error' => 'name-taken'));
        }
    }
    $prev = isset($db[$w]) ? $db[$w] : array();
    $xpSave = max(0, intval($p['xp'] ?? 0));
    // vpts can never exceed what the player's level has earned minus what they've claimed
    $vMax = max(0, vpts_earned_for_level(level_from_xp($xpSave)) - intval(isset($prev['claimedTotal']) ? $prev['claimedTotal'] : 0));
    $db[$w] = array(
        'name' => $name,
        'kit' => substr($p['kit'] ?? 'NOVA', 0, 10),
        'credits' => max(0, intval($p['credits'] ?? 0)),
        'xp' => $xpSave,
        'vpts' => min(max(0, intval($p['vpts'] ?? 0)), $vMax),
        'inv' => $p['inv'] ?? array('owned' => array(), 'hat' => null, 'plating' => null, 'pet' => false),
        'catch' => is_array($p['catch'] ?? null) ? array_map('intval', $p['catch']) : (isset($prev['catch']) ? $prev['catch'] : array()),
        'pets' => is_array($p['pets'] ?? null) ? array_values(array_slice(array_map('strval', $p['pets']), 0, 24)) : (isset($prev['pets']) ? $prev['pets'] : array()),
        'activePet' => isset($p['activePet']) && $p['activePet'] !== null ? substr((string)$p['activePet'], 0, 16) : null,
        'bestRace' => floatval($p['bestRace'] ?? 0),
        'email' => isset($prev['email']) ? $prev['email'] : '',
        'claimedTotal' => isset($prev['claimedTotal']) ? $prev['claimedTotal'] : 0,
        'updated' => time(),
    );
    save_db($fp, $db);
    out(array('ok' => 1));
}

if ($a === 'claim') {
    $w = wallet_in($in); if (!$w) out(array('error' => 'no-wallet'));
    $email = filter_var(trim($in['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    list($fp, $db) = load_db($PLAYERS);
    if (!$email && isset($db[$w]) && !empty($db[$w]['email'])) $email = $db[$w]['email'];
    if (!$email) { flock($fp, LOCK_UN); fclose($fp); out(array('error' => 'email-required')); }
    if (!isset($db[$w])) { // no saved profile yet — start one from what the client sent
        $nm = strtoupper(substr(preg_replace('/[^A-Za-z0-9 _\-]/', '', $in['name'] ?? 'PILOT'), 0, 12));
        $db[$w] = array('name' => $nm ?: 'PILOT', 'xp' => 0, 'vpts' => 0, 'claimedTotal' => 0, 'email' => $email, 'updated' => time());
    }
    // amount = points earned at the player's level minus what they've already claimed (claiming twice = 0)
    $earned = vpts_earned_for_level(level_from_xp(intval($db[$w]['xp'] ?? 0)));
    $already = intval($db[$w]['claimedTotal'] ?? 0);
    $amount = max(0, $earned - $already);
    if ($amount <= 0) $amount = max(0, intval($in['amount'] ?? 0)); // fallback to client points if profile lagged
    if ($amount <= 0) { flock($fp, LOCK_UN); fclose($fp); out(array('error' => 'nothing-to-claim')); }
    $db[$w]['email'] = $email;
    $db[$w]['vpts'] = 0;
    $db[$w]['claimedTotal'] = $already + $amount;
    $name = $db[$w]['name'];
    save_db($fp, $db);

    list($fp2, $claims) = load_db($CLAIMS);
    $claims[] = array('wallet' => $w, 'name' => $name, 'email' => $email, 'amount' => $amount, 'status' => 'pending-airdrop', 't' => time());
    save_db($fp2, $claims);

    $sent = send_claim_mail($email, $name, $w, $amount, $cfg);
    out(array('ok' => 1, 'amount' => $amount, 'emailSent' => $sent ? 1 : 0));
}

if ($a === 'leaderboard') {
    // public ranking of everyone who has deployed a unit
    list($fp, $db) = load_db($PLAYERS);
    flock($fp, LOCK_UN); fclose($fp);
    $rows = array();
    foreach ($db as $w => $p) {
        if (empty($p['name'])) continue;
        $xp = intval($p['xp'] ?? 0);
        $lv = level_from_xp($xp);
        $rows[] = array(
            'name'   => $p['name'],
            'level'  => $lv,
            'xp'     => $xp,
            'points' => vpts_earned_for_level($lv),
            'kit'    => isset($p['kit']) ? $p['kit'] : '',
        );
    }
    usort($rows, function ($a, $b) {
        if ($b['level'] !== $a['level']) return $b['level'] - $a['level'];
        if ($b['xp'] !== $a['xp']) return $b['xp'] - $a['xp'];
        return strcmp($a['name'], $b['name']);
    });
    out(array('top' => array_slice($rows, 0, 50), 'count' => count($rows)));
}

if ($a === 'admin') {
    // private list of wallets to airdrop — protected by ADMIN_KEY in config.php
    $key = $in['key'] ?? '';
    if (empty($cfg['ADMIN_KEY']) || !hash_equals((string)$cfg['ADMIN_KEY'], (string)$key)) out(array('error' => 'bad-key'));
    list($fp, $claims) = load_db($CLAIMS);
    flock($fp, LOCK_UN); fclose($fp);
    list($fp2, $players) = load_db($PLAYERS);
    flock($fp2, LOCK_UN); fclose($fp2);
    // everyone who has claimed (the airdrop list) — one row per wallet, total claimed
    $byWallet = array();
    foreach ($claims as $c) {
        $w = isset($c['wallet']) ? $c['wallet'] : '';
        if (!$w) continue;
        if (!isset($byWallet[$w])) $byWallet[$w] = array('wallet' => $w, 'name' => $c['name'] ?? '', 'email' => $c['email'] ?? '', 'amount' => 0, 't' => $c['t'] ?? 0);
        $byWallet[$w]['amount'] += intval($c['amount'] ?? 0);
        if (($c['t'] ?? 0) > $byWallet[$w]['t']) { $byWallet[$w]['t'] = $c['t']; if (!empty($c['email'])) $byWallet[$w]['email'] = $c['email']; }
    }
    $claimers = array_values($byWallet);
    usort($claimers, function ($a, $b) { return $b['amount'] - $a['amount']; });
    // everyone who has connected a wallet (whether they claimed or not)
    $connected = array();
    foreach ($players as $w => $p) {
        if (empty($p['name'])) continue;
        $lv = level_from_xp(intval($p['xp'] ?? 0));
        $connected[] = array('wallet' => $w, 'name' => $p['name'], 'level' => $lv, 'points' => vpts_earned_for_level($lv),
            'email' => isset($p['email']) ? $p['email'] : '', 'claimed' => intval($p['claimedTotal'] ?? 0));
    }
    usort($connected, function ($a, $b) { return $b['points'] - $a['points']; });
    out(array('claimers' => $claimers, 'connected' => $connected, 'claimCount' => count($claimers), 'walletCount' => count($connected)));
}

if ($a === 'ai') {
    $w = wallet_in($in); if (!$w) out(array('error' => 'no-wallet'));
    if (empty($cfg['ANTHROPIC_API_KEY'])) out(array('error' => 'ai-disabled'));
    $prompt = trim(substr($in['prompt'] ?? '', 0, 300));
    if ($prompt === '') out(array('error' => 'empty'));
    // simple per-wallet rate limit: 1 req / 4s
    list($fp, $db) = load_db($PLAYERS);
    $last = isset($db[$w]['aiT']) ? $db[$w]['aiT'] : 0;
    if (time() - $last < 4) { flock($fp, LOCK_UN); fclose($fp); out(array('error' => 'slow-down')); }
    if (isset($db[$w])) $db[$w]['aiT'] = time();
    save_db($fp, $db);

    $sys = "You are SHAMAN, the wise goblin shaman of GOBE’S ADVENTURE — a web3 multiplayer game of floating worlds on Solana. "
         . "Facts: \$GOBE token launches " . $cfg['LAUNCH_INFO'] . " on Solana; contract address revealed at launch. "
         . "Players earn credits (⌬) and XP by mining crystals/cores, building, fishing, joining work crews, missions and races; "
         . "leveling up unlocks new world clusters and grants \$GOBE point rewards (10,000 at LV2, +10k more each level, claimable for the airdrop). "
         . "Stay in character: friendly, playful, concise (max 60 words), light web3 slang (gm, ser, wagmi). Never invent contract addresses or prices.";
    $payload = json_encode(array(
        'model' => $cfg['AI_MODEL'],
        'max_tokens' => 220,
        'system' => $sys,
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
    ));
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'x-api-key: ' . $cfg['ANTHROPIC_API_KEY'],
            'anthropic-version: 2023-06-01',
        ),
    ));
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($res, true);
    $text = isset($j['content'][0]['text']) ? $j['content'][0]['text'] : null;
    out($text ? array('text' => $text) : array('error' => 'ai-failed'));
}

out(array('error' => 'unknown-action'));
