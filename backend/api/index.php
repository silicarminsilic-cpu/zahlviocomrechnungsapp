<?php
declare(strict_types=1);
/* Zahlvio API: Registrierung, Login, Kontodaten, Kontaktanfragen, Rechnungsversand. PHP 8.1+, SQLite. */
const MAX_BODY = 6 * 1024 * 1024;
const MAX_STATE = 5 * 1024 * 1024;
const SESSION_DAYS = 14;

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) { http_response_code(503); header('Content-Type: application/json'); echo '{"error":"not_configured"}'; exit; }
$CFG = require $cfgFile;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function out(int $code, array $data = []): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(int $code, string $err, string $msg = ''): never { out($code, ['error' => $err, 'message' => $msg]); }
function cfg(string $k, $d = null) { global $CFG; return $CFG[$k] ?? $d; }
function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (cfg('trust_proxy') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); }
function client_ip(): string {
  if (cfg('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function ip_key(): string { return substr(hash('sha256', client_ip() . '|zv'), 0, 24); }

function db(): PDO {
  static $pdo = null; if ($pdo) return $pdo;
  $path = cfg('db_path'); $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0750, true);
  $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
  $pdo->exec("CREATE TABLE IF NOT EXISTS users(
    id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE COLLATE NOCASE, pw TEXT NOT NULL,
    first TEXT NOT NULL DEFAULT '', last TEXT NOT NULL DEFAULT '', company TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT 'CH',
    verified INTEGER NOT NULL DEFAULT 0, vtoken TEXT, consent_at INTEGER, consent_ver TEXT, created INTEGER NOT NULL, last_login INTEGER);
  CREATE TABLE IF NOT EXISTS states(user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, json TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, updated INTEGER NOT NULL);
  CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, expires INTEGER NOT NULL, created INTEGER NOT NULL);
  CREATE TABLE IF NOT EXISTS resets(token TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, expires INTEGER NOT NULL);
  CREATE TABLE IF NOT EXISTS leads(id INTEGER PRIMARY KEY AUTOINCREMENT, json TEXT NOT NULL, created INTEGER NOT NULL);
  CREATE TABLE IF NOT EXISTS attempts(k TEXT NOT NULL, ts INTEGER NOT NULL);
  CREATE INDEX IF NOT EXISTS attempts_k ON attempts(k, ts);
  CREATE TABLE IF NOT EXISTS mails(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, ts INTEGER NOT NULL);
  CREATE INDEX IF NOT EXISTS mails_u ON mails(user_id, ts);");
  return $pdo;
}

/* Rate Limit: höchstens $max Ereignisse pro $window Sekunden für Schlüssel $k */
function limited(string $k, int $max, int $window): bool {
  $st = db()->prepare('SELECT COUNT(*) c FROM attempts WHERE k=? AND ts>?'); $st->execute([$k, time() - $window]);
  return (int)$st->fetch()['c'] >= $max;
}
function hit(string $k): void { db()->prepare('INSERT INTO attempts(k,ts) VALUES(?,?)')->execute([$k, time()]); if (random_int(1, 50) === 1) db()->prepare('DELETE FROM attempts WHERE ts<?')->execute([time() - 86400]); }

/* Anfrage prüfen: gleiche Herkunft und JSON-Header bei schreibenden Anfragen (CSRF-Schutz) */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
  if (($_SERVER['HTTP_X_ZV'] ?? '') !== '1') fail(403, 'forbidden');
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin !== '') {
    $oh = parse_url($origin, PHP_URL_HOST); $bh = parse_url(cfg('base_url', ''), PHP_URL_HOST);
    $sh = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    if ($oh !== $sh && $oh !== $bh && $oh !== 'www.' . $bh && 'www.' . $oh !== $bh) fail(403, 'forbidden');
  }
}
function body(): array {
  $raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
  if ($raw === false || strlen($raw) > MAX_BODY) fail(413, 'too_large');
  if ($raw === '') return [];
  $j = json_decode($raw, true);
  if (!is_array($j)) fail(400, 'bad_request');
  return $j;
}
function str($v, int $max = 200): string { return is_string($v) ? mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', $v)), 0, $max) : ''; }
function text($v, int $max = 4000): string { return is_string($v) ? mb_substr(str_replace("\0", '', trim($v)), 0, $max) : ''; }
function valid_email(string $e): bool { return strlen($e) <= 190 && (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }

/* ---------- Mail ---------- */
function mime_subject(string $s): string { return '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $s)) . '?='; }
function send_mail(string $to, string $subject, string $text, array $o = []): bool {
  $from = cfg('mail_from'); $fn = cfg('mail_from_name', 'Zahlvio');
  $hdr = ['MIME-Version: 1.0', 'From: ' . mime_subject($fn) . ' <' . $from . '>'];
  if (!empty($o['reply_to']) && valid_email($o['reply_to'])) $hdr[] = 'Reply-To: ' . $o['reply_to'];
  if (!empty($o['cc']) && valid_email($o['cc'])) $hdr[] = 'Cc: ' . $o['cc'];
  $textB64 = chunk_split(base64_encode($text));
  if (!empty($o['attach'])) {
    $b = 'zv_' . bin2hex(random_bytes(8));
    $hdr[] = 'Content-Type: multipart/mixed; boundary="' . $b . '"';
    $name = preg_replace('/[^\w\-. äöüÄÖÜ]/u', '_', $o['attach']['name']);
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n$textB64\r\n"
          . "--$b\r\nContent-Type: application/pdf; name=\"" . mime_subject($name) . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . mime_subject($name) . "\"\r\n\r\n"
          . chunk_split(base64_encode($o['attach']['data'])) . "\r\n--$b--";
  } else {
    $hdr[] = 'Content-Type: text/plain; charset=UTF-8'; $hdr[] = 'Content-Transfer-Encoding: base64'; $body = $textB64;
  }
  if (cfg('mail_mode', 'mail') === 'log') {
    @file_put_contents(dirname(cfg('db_path')) . '/mail.log', "TO: $to\nSUBJECT: $subject\n" . implode("\n", $hdr) . "\n\n$text\n" . (!empty($o['attach']) ? '[PDF ' . strlen($o['attach']['data']) . " Bytes]\n" : '') . "-----\n", FILE_APPEND);
    return true;
  }
  $ok = @mail($to, mime_subject($subject), $body, implode("\r\n", $hdr), '-f' . $from);
  if (!$ok) error_log('zahlvio: mail() fehlgeschlagen an ' . preg_replace('/^.*@/', '***@', $to));
  return $ok;
}

/* ---------- Sessions ---------- */
function set_cookie(string $val, int $exp): void {
  setcookie('zv', $val, ['expires' => $exp, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function start_session(int $uid): void {
  $tok = bin2hex(random_bytes(32)); $now = time();
  db()->prepare('INSERT INTO sessions(token,user_id,expires,created) VALUES(?,?,?,?)')->execute([hash('sha256', $tok), $uid, $now + SESSION_DAYS * 86400, $now]);
  db()->prepare('UPDATE users SET last_login=? WHERE id=?')->execute([$now, $uid]);
  set_cookie($tok, $now + SESSION_DAYS * 86400);
}
function current_user(): ?array {
  $c = $_COOKIE['zv'] ?? ''; if (!is_string($c) || !preg_match('/^[a-f0-9]{64}$/', $c)) return null;
  $st = db()->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires>?');
  $st->execute([hash('sha256', $c), time()]); $u = $st->fetch();
  return $u ?: null;
}
function need_user(): array { $u = current_user(); if (!$u) fail(401, 'unauthenticated'); return $u; }
function pub(array $u): array { return ['id' => (int)$u['id'], 'email' => $u['email'], 'first' => $u['first'], 'last' => $u['last'], 'company' => $u['company'], 'country' => $u['country'], 'verified' => (bool)$u['verified']]; }

function check_password(string $p): void {
  if (mb_strlen($p) < 12) fail(422, 'weak_password', 'Das Passwort braucht mindestens 12 Zeichen.');
  if (strlen($p) > 200) fail(422, 'weak_password', 'Das Passwort ist zu lang.');
  if (preg_match('/^(.)\1+$/', $p) || in_array(strtolower($p), ['passwort1234', 'passwordpassword', '123456789012', 'zahlvio12345'], true)) fail(422, 'weak_password', 'Bitte wähle ein weniger einfaches Passwort.');
}
function send_verify(array $u, string $tok): void {
  $url = cfg('base_url') . '/?verify=' . $tok;
  send_mail($u['email'], 'Bitte bestätige deine E-Mail-Adresse', "Hallo " . ($u['first'] ?: '') . ",\n\nbitte bestätige deine E-Mail-Adresse für Zahlvio:\n\n$url\n\nWenn du dich nicht registriert hast, kannst du diese Nachricht ignorieren.\n\nZahlvio, ein Angebot der Esche Consulting GmbH");
}

/* ---------- Router ---------- */
$a = $_GET['a'] ?? '';
try {
switch ($a) {
  case 'me': {
    $u = current_user(); out(200, ['user' => $u ? pub($u) : null, 'register' => (bool)cfg('allow_register', true), 'invite' => count((array)cfg('invite_codes', [])) > 0, 'site' => ['email' => cfg('contact_to', ''), 'privacy_email' => (cfg('privacy_to') ?: cfg('contact_to', '')), 'hoster' => cfg('hoster', ''), 'hoster_loc' => cfg('hoster_loc', ''), 'mail_provider' => cfg('mail_provider', '')]]);
  }
  case 'register': {
    if ($method !== 'POST') fail(405, 'method');
    if (!cfg('allow_register', true)) fail(403, 'closed', 'Die Registrierung ist derzeit geschlossen.');
    $b = body(); $ik = 'reg:' . ip_key();
    if (limited($ik, 6, 3600)) fail(429, 'rate_limited', 'Zu viele Versuche. Bitte versuche es später erneut.');
    hit($ik);
    $email = strtolower(str($b['email'] ?? '', 190)); $pw = (string)($b['password'] ?? '');
    $first = str($b['first'] ?? '', 80); $last = str($b['last'] ?? '', 80); $company = str($b['company'] ?? '', 120); $country = in_array($b['country'] ?? '', ['CH', 'DE', 'AT'], true) ? $b['country'] : 'CH';
    if (!valid_email($email)) fail(422, 'bad_email', 'Bitte gib eine gültige E-Mail-Adresse an.');
    if ($first === '' || $company === '') fail(422, 'missing', 'Bitte gib Vorname und Firma an.');
    if (empty($b['consent'])) fail(422, 'consent', 'Bitte akzeptiere AGB und Datenschutzerklärung.');
    $codes = array_filter(array_map(fn($c) => strtoupper(trim((string)$c)), (array)cfg('invite_codes', [])));
    if ($codes) {
      $given = strtoupper(trim(str($b['invite'] ?? '', 60))); $okc = false;
      foreach ($codes as $c) if ($given !== '' && hash_equals($c, $given)) $okc = true;
      if (!$okc) { hit($ik); fail(403, 'invite', 'Für die Registrierung brauchst du einen gültigen Einladungscode.'); }
    }
    check_password($pw);
    $st = db()->prepare('SELECT id FROM users WHERE email=?'); $st->execute([$email]);
    if ($st->fetch()) fail(409, 'exists', 'Mit dieser E-Mail-Adresse gibt es schon ein Konto. Melde dich an oder setze das Passwort zurück.');
    $vt = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO users(email,pw,first,last,company,country,verified,vtoken,consent_at,consent_ver,created) VALUES(?,?,?,?,?,?,0,?,?,?,?)')
      ->execute([$email, password_hash($pw, PASSWORD_DEFAULT), $first, $last, $company, $country, hash('sha256', $vt), time(), cfg('terms_version', ''), time()]);
    $uid = (int)db()->lastInsertId(); $u = db()->query('SELECT * FROM users WHERE id=' . $uid)->fetch();
    send_verify($u, $vt); start_session($uid);
    if (cfg('notify_new_accounts', true) && cfg('contact_to')) send_mail((string)cfg('contact_to'), 'Zahlvio: neues Konto', "Neues Konto bei Zahlvio\nFirma: $company\nName: $first $last\nE-Mail: $email\nLand: $country\n");
    out(200, ['user' => pub($u)]);
  }
  case 'login': {
    if ($method !== 'POST') fail(405, 'method');
    $b = body(); $email = strtolower(str($b['email'] ?? '', 190)); $pw = (string)($b['password'] ?? '');
    $k1 = 'login:' . ip_key(); $k2 = 'loginm:' . hash('sha256', $email);
    if (limited($k1, 30, 900) || limited($k2, 8, 900)) fail(429, 'rate_limited', 'Zu viele Anmeldeversuche. Bitte warte 15 Minuten.');
    $st = db()->prepare('SELECT * FROM users WHERE email=?'); $st->execute([$email]); $u = $st->fetch();
    $hash = $u ? $u['pw'] : password_hash('dummy-' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT); // gleicher Zeitaufwand, ob das Konto existiert oder nicht
    $ok = password_verify($pw, $hash) && $u;
    if (!$ok) { hit($k1); hit($k2); fail(401, 'bad_login', 'E-Mail oder Passwort stimmt nicht.'); }
    if (password_needs_rehash($u['pw'], PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET pw=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
    start_session((int)$u['id']); out(200, ['user' => pub($u)]);
  }
  case 'logout': {
    if ($method !== 'POST') fail(405, 'method');
    $c = $_COOKIE['zv'] ?? ''; if (is_string($c) && preg_match('/^[a-f0-9]{64}$/', $c)) db()->prepare('DELETE FROM sessions WHERE token=?')->execute([hash('sha256', $c)]);
    set_cookie('', time() - 3600); out(200, ['ok' => true]);
  }
  case 'verify': {
    if ($method !== 'POST') fail(405, 'method');
    $b = body(); $t = (string)($b['token'] ?? ''); if (!preg_match('/^[a-f0-9]{48}$/', $t)) fail(400, 'bad_token', 'Der Link ist ungültig.');
    $st = db()->prepare('UPDATE users SET verified=1, vtoken=NULL WHERE vtoken=?'); $st->execute([hash('sha256', $t)]);
    if ($st->rowCount() < 1) fail(400, 'bad_token', 'Der Link ist ungültig oder schon verwendet.');
    out(200, ['ok' => true]);
  }
  case 'resend-verify': {
    if ($method !== 'POST') fail(405, 'method'); $u = need_user(); if ($u['verified']) out(200, ['ok' => true]);
    $k = 'rv:' . $u['id']; if (limited($k, 3, 3600)) fail(429, 'rate_limited', 'Bitte warte etwas, bevor du erneut anforderst.'); hit($k);
    $vt = bin2hex(random_bytes(24)); db()->prepare('UPDATE users SET vtoken=? WHERE id=?')->execute([hash('sha256', $vt), $u['id']]); send_verify($u, $vt); out(200, ['ok' => true]);
  }
  case 'reset-request': {
    if ($method !== 'POST') fail(405, 'method');
    $b = body(); $email = strtolower(str($b['email'] ?? '', 190)); $k = 'rr:' . ip_key();
    if (limited($k, 5, 3600)) fail(429, 'rate_limited', 'Zu viele Anfragen. Bitte versuche es später erneut.'); hit($k);
    if (valid_email($email)) {
      $st = db()->prepare('SELECT * FROM users WHERE email=?'); $st->execute([$email]); $u = $st->fetch();
      if ($u) {
        $t = bin2hex(random_bytes(24)); db()->prepare('DELETE FROM resets WHERE user_id=?')->execute([$u['id']]);
        db()->prepare('INSERT INTO resets(token,user_id,expires) VALUES(?,?,?)')->execute([hash('sha256', $t), $u['id'], time() + 3600]);
        send_mail($u['email'], 'Passwort zurücksetzen', "Hallo " . $u['first'] . ",\n\nüber diesen Link kannst du ein neues Passwort für Zahlvio festlegen (1 Stunde gültig):\n\n" . cfg('base_url') . "/?reset=$t\n\nWenn du das nicht angefordert hast, kannst du diese Nachricht ignorieren.");
      }
    }
    out(200, ['ok' => true]);
  }
  case 'reset': {
    if ($method !== 'POST') fail(405, 'method');
    $b = body(); $t = (string)($b['token'] ?? ''); $pw = (string)($b['password'] ?? ''); check_password($pw);
    if (!preg_match('/^[a-f0-9]{48}$/', $t)) fail(400, 'bad_token', 'Der Link ist ungültig.');
    $st = db()->prepare('SELECT user_id FROM resets WHERE token=? AND expires>?'); $st->execute([hash('sha256', $t), time()]); $r = $st->fetch();
    if (!$r) fail(400, 'bad_token', 'Der Link ist ungültig oder abgelaufen.');
    db()->prepare('UPDATE users SET pw=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $r['user_id']]);
    db()->prepare('DELETE FROM resets WHERE user_id=?')->execute([$r['user_id']]);
    db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$r['user_id']]);
    out(200, ['ok' => true]);
  }
  case 'change-password': {
    if ($method !== 'POST') fail(405, 'method'); $u = need_user(); $b = body();
    $k = 'cp:' . $u['id']; if (limited($k, 6, 900)) fail(429, 'rate_limited', 'Zu viele Versuche.');
    if (!password_verify((string)($b['old'] ?? ''), $u['pw'])) { hit($k); fail(401, 'bad_login', 'Das aktuelle Passwort stimmt nicht.'); }
    $new = (string)($b['new'] ?? ''); check_password($new);
    db()->prepare('UPDATE users SET pw=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    $c = hash('sha256', (string)$_COOKIE['zv']); db()->prepare('DELETE FROM sessions WHERE user_id=? AND token<>?')->execute([$u['id'], $c]);
    out(200, ['ok' => true]);
  }
  case 'state': {
    $u = need_user();
    if ($method === 'GET') {
      $st = db()->prepare('SELECT json,version FROM states WHERE user_id=?'); $st->execute([$u['id']]); $r = $st->fetch();
      if (!$r) out(200, ['state' => null, 'version' => 0]);
      echo '{"state":' . $r['json'] . ',"version":' . (int)$r['version'] . '}'; exit;
    }
    if ($method !== 'PUT') fail(405, 'method');
    $b = body(); if (!isset($b['state']) || !is_array($b['state'])) fail(400, 'bad_request');
    $json = json_encode($b['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > MAX_STATE) fail(413, 'too_large', 'Die Datenmenge ist zu gross.');
    $base = (int)($b['base'] ?? 0); $pdo = db(); $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT version FROM states WHERE user_id=?'); $st->execute([$u['id']]); $r = $st->fetch(); $cur = $r ? (int)$r['version'] : 0;
    if ($cur !== $base) { $pdo->rollBack(); fail(409, 'conflict', 'Das Konto wurde in einem anderen Fenster geändert.'); }
    $pdo->prepare('INSERT INTO states(user_id,json,version,updated) VALUES(?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET json=excluded.json, version=excluded.version, updated=excluded.updated')->execute([$u['id'], $json, $cur + 1, time()]);
    $pdo->commit(); out(200, ['version' => $cur + 1]);
  }
  case 'lead': {
    if ($method !== 'POST') fail(405, 'method'); $b = body();
    if (!empty($b['website'])) out(200, ['ok' => true]);               // Honeypot
    $k = 'lead:' . ip_key(); if (limited($k, 5, 3600)) fail(429, 'rate_limited', 'Zu viele Anfragen. Bitte ruf uns an oder versuche es später erneut.');
    $l = []; foreach (['name' => 120, 'firma' => 160, 'mail' => 190, 'tel' => 60, 'land' => 40, 'vol' => 80, 'tool' => 120, 'when' => 80, 'time' => 80] as $f => $m) $l[$f] = str($b[$f] ?? '', $m);
    $l['msg'] = text($b['msg'] ?? '', 3000);
    if ($l['name'] === '' || $l['firma'] === '' || !valid_email($l['mail'])) fail(422, 'invalid', 'Bitte Name, Firma und eine gültige E-Mail-Adresse angeben.');
    if (empty($b['ok'])) fail(422, 'consent', 'Bitte stimme der Kontaktaufnahme zu.');
    hit($k);
    db()->prepare('INSERT INTO leads(json,created) VALUES(?,?)')->execute([json_encode($l, JSON_UNESCAPED_UNICODE), time()]);
    $lines = ''; foreach ($l as $f => $v) if ($v !== '') $lines .= ucfirst($f) . ': ' . $v . "\n";
    send_mail(cfg('contact_to'), 'Neue Anfrage über Zahlvio: ' . $l['firma'], "Neue Anfrage über das Formular auf der Website:\n\n$lines\nDie Person hat der Kontaktaufnahme zugestimmt.", ['reply_to' => $l['mail']]);
    out(200, ['ok' => true]);
  }
  case 'send-invoice': {
    if ($method !== 'POST') fail(405, 'method'); $u = need_user();
    if (!$u['verified']) fail(403, 'unverified', 'Bitte bestätige zuerst deine E-Mail-Adresse. Wir haben dir einen Link geschickt.');
    $b = body(); $to = strtolower(str($b['to'] ?? '', 190)); $sub = str($b['subject'] ?? '', 200); $txt = text($b['body'] ?? '', 8000); $fn = str($b['filename'] ?? 'Dokument.pdf', 120);
    if (!valid_email($to)) fail(422, 'bad_email', 'Die Empfängeradresse ist ungültig.');
    if ($sub === '' || $txt === '') fail(422, 'missing', 'Betreff und Nachricht dürfen nicht leer sein.');
    $pdf = base64_decode((string)($b['pdf'] ?? ''), true);
    if ($pdf === false || strlen($pdf) < 200 || strlen($pdf) > 4 * 1024 * 1024 || substr($pdf, 0, 5) !== '%PDF-') fail(422, 'bad_pdf', 'Der Anhang ist ungültig.');
    $cnt = db()->prepare('SELECT COUNT(*) c FROM mails WHERE user_id=? AND ts>?'); $cnt->execute([$u['id'], time() - 86400]);
    if ((int)$cnt->fetch()['c'] >= (int)cfg('invoice_mail_per_day', 40)) fail(429, 'daily_limit', 'Das Tageslimit für den Versand ist erreicht.');
    if (!preg_match('/\.pdf$/i', $fn)) $fn .= '.pdf';
    $ok = send_mail($to, $sub, $txt . "\n\n--\nVersendet über Zahlvio im Auftrag von " . ($u['company'] ?: $u['email']) . ". Antworten gehen direkt an den Absender.", ['reply_to' => $u['email'], 'cc' => !empty($b['cc_self']) ? $u['email'] : '', 'attach' => ['name' => $fn, 'data' => $pdf]]);
    if (!$ok) fail(502, 'mail_failed', 'Der Versand ist fehlgeschlagen. Bitte versuche es später erneut.');
    db()->prepare('INSERT INTO mails(user_id,ts) VALUES(?,?)')->execute([$u['id'], time()]);
    out(200, ['ok' => true]);
  }
  case 'export': {
    $u = need_user(); $st = db()->prepare('SELECT json FROM states WHERE user_id=?'); $st->execute([$u['id']]); $r = $st->fetch();
    header('Content-Disposition: attachment; filename="zahlvio-datenexport.json"');
    echo '{"konto":' . json_encode(pub($u) + ['registriert' => date('c', (int)$u['created']), 'einwilligung' => date('c', (int)$u['consent_at']), 'version_rechtstexte' => $u['consent_ver']], JSON_UNESCAPED_UNICODE) . ',"daten":' . ($r ? $r['json'] : 'null') . '}'; exit;
  }
  case 'delete-account': {
    if ($method !== 'POST') fail(405, 'method'); $u = need_user(); $b = body();
    $k = 'del:' . $u['id']; if (limited($k, 5, 900)) fail(429, 'rate_limited', 'Zu viele Versuche.');
    if (!password_verify((string)($b['password'] ?? ''), $u['pw'])) { hit($k); fail(401, 'bad_login', 'Das Passwort stimmt nicht.'); }
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$u['id']]); set_cookie('', time() - 3600); out(200, ['ok' => true]);
  }
  default: fail(404, 'not_found');
}
} catch (Throwable $e) {
  error_log('zahlvio: ' . $e->getMessage());
  fail(500, 'server_error', 'Es ist ein Fehler aufgetreten. Bitte versuche es später erneut.');
}
