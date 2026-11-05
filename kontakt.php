<?php
declare(strict_types=1);
/* Zahlvio: Kontaktformular. Nimmt eine Anfrage entgegen und schickt sie per E-Mail.
   Keine Datenbank, keine Einstellungen ausser den drei Zeilen unten. */
const ZV_TO        = 'kontakt@arminsilic.de';   // Hier landen die Anfragen
const ZV_FROM      = 'info@zahlvio.com';        // Absender: muss bei Infomaniak als Postfach der Domain existieren
const ZV_FROM_NAME = 'Zahlvio Website';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function done(int $code, array $d): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function clean(string $s, int $max): string { $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? ''); return mb_substr($s, 0, $max); }
function one(string $s): string { return trim(preg_replace('/[\r\n]+/', ' ', $s) ?? ''); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') done(405, ['ok' => false, 'message' => 'Nicht erlaubt.']);
if (($_SERVER['HTTP_X_ZV'] ?? '') !== '1') done(403, ['ok' => false, 'message' => 'Nicht erlaubt.']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '') && parse_url($origin, PHP_URL_HOST) . ':' . (parse_url($origin, PHP_URL_PORT) ?? '') !== ($_SERVER['HTTP_HOST'] ?? '')) done(403, ['ok' => false, 'message' => 'Nicht erlaubt.']);

$raw = file_get_contents('php://input', false, null, 0, 20001);
if ($raw === false || strlen($raw) > 20000) done(413, ['ok' => false, 'message' => 'Die Anfrage ist zu gross.']);
$b = json_decode($raw, true);
if (!is_array($b)) done(400, ['ok' => false, 'message' => 'Ungültige Anfrage.']);

if (!empty($b['website'])) done(200, ['ok' => true]); // Honeypot: Bots bekommen scheinbar Erfolg

// Begrenzung: höchstens 5 Anfragen pro Stunde und IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$rf = sys_get_temp_dir() . '/zv-kontakt-' . hash('sha256', $ip . '|zahlvio');
$now = time(); $hits = [];
if (is_file($rf)) { $hits = array_filter(array_map('intval', explode(',', (string)file_get_contents($rf))), fn($t) => $t > $now - 3600); }
if (count($hits) >= 5) done(429, ['ok' => false, 'message' => 'Zu viele Anfragen. Bitte versuche es später erneut oder ruf uns an.']);

$name = one(clean((string)($b['name'] ?? ''), 120)); $firma = one(clean((string)($b['firma'] ?? ''), 160));
$mail = one(clean((string)($b['mail'] ?? ''), 190)); $tel = one(clean((string)($b['tel'] ?? ''), 60));
$land = one(clean((string)($b['land'] ?? ''), 20)); $vol = one(clean((string)($b['vol'] ?? ''), 80)); $tool = one(clean((string)($b['tool'] ?? ''), 80));
$when = one(clean((string)($b['when'] ?? ''), 80)); $time = one(clean((string)($b['time'] ?? ''), 80)); $msg = clean((string)($b['msg'] ?? ''), 3000);
if ($name === '' || $firma === '') done(422, ['ok' => false, 'message' => 'Bitte gib Name und Firma an.']);
if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) done(422, ['ok' => false, 'message' => 'Bitte gib eine gültige E-Mail-Adresse an.']);
if (empty($b['ok'])) done(422, ['ok' => false, 'message' => 'Bitte stimme der Kontaktaufnahme zu.']);

$text = "Neue Anfrage über die Zahlvio-Website\n\n"
  . "Name:      $name\nFirma:     $firma\nE-Mail:    $mail\nTelefon:   " . ($tel ?: '-') . "\nLand:      " . ($land ?: '-') . "\n"
  . "Volumen:   " . ($vol ?: '-') . "\nHeute:     " . ($tool ?: '-') . "\nWunsch:    " . trim($when . ', ' . $time, ', ') . "\n\n"
  . "Nachricht:\n" . ($msg ?: '-') . "\n\n--\nZustimmung zur Kontaktaufnahme erteilt am " . date('d.m.Y H:i') . " (Serverzeit).\n";
$enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
$headers = [
  'From: ' . $enc(ZV_FROM_NAME) . ' <' . ZV_FROM . '>',
  'Reply-To: ' . $enc(one($name)) . ' <' . $mail . '>',
  'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
];
$subject = $enc('Zahlvio: Anfrage von ' . one($firma));
$body = chunk_split(base64_encode($text));
$sent = @mail(ZV_TO, $subject, $body, implode("\r\n", $headers), '-f' . ZV_FROM);
if (!$sent) $sent = @mail(ZV_TO, $subject, $body, implode("\r\n", $headers));
if (!$sent) done(500, ['ok' => false, 'message' => 'Die Anfrage konnte nicht gesendet werden. Bitte ruf uns an oder schreibe an ' . ZV_FROM . '.']);

$hits[] = $now; @file_put_contents($rf, implode(',', $hits), LOCK_EX);
done(200, ['ok' => true]);
