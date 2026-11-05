<?php
declare(strict_types=1);
/* Zahlvio Datensicherung. Nur per Kommandozeile (Cron), nie über den Browser.
   php backup.php            Sicherung erstellen (verschlüsselt, mit Aufbewahrungsfrist)
   php backup.php verify F   Sicherung F entschlüsseln und prüfen (Testlauf der Wiederherstellung)
   php backup.php restore F Z  Sicherung F entschlüsselt nach Z schreiben (Z darf noch nicht existieren)
   php backup.php genkey     neuen Schlüssel erzeugen */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function fatal(string $m): never { fwrite(STDERR, "FEHLER: $m\n"); exit(1); }
$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) fatal('config.php fehlt');
$CFG = require $cfgFile;
$cmd = $argv[1] ?? 'run';

if ($cmd === 'genkey') { echo base64_encode(random_bytes(32)), "\n"; exit(0); }

$key = base64_decode((string)($CFG['backup_key'] ?? ''), true);
if ($key === false || strlen($key) !== 32) fatal("backup_key fehlt oder ist ungültig. Erzeugen mit: php backup.php genkey");
foreach (['openssl', 'zlib', 'pdo_sqlite'] as $ext) if (!extension_loaded($ext)) fatal("PHP-Erweiterung $ext fehlt");

function enc(string $plain, string $key): string {
  $iv = random_bytes(12); $tag = '';
  $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'ZVB1', 16);
  if ($c === false) fatal('Verschlüsselung fehlgeschlagen');
  return 'ZVB1' . $iv . $tag . $c;
}
function dec(string $blob, string $key): string {
  if (strlen($blob) < 32 || substr($blob, 0, 4) !== 'ZVB1') fatal('Keine gültige Sicherungsdatei');
  $p = openssl_decrypt(substr($blob, 32), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 4, 12), substr($blob, 16, 16), 'ZVB1');
  if ($p === false) fatal('Entschlüsselung fehlgeschlagen (falscher Schlüssel oder beschädigte Datei)');
  $raw = gzdecode($p); if ($raw === false) fatal('Entpacken fehlgeschlagen');
  return $raw;
}
function check_db(string $file): array {
  $p = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $ic = $p->query('PRAGMA integrity_check')->fetchColumn();
  return ['ok' => $ic === 'ok', 'users' => (int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'states' => (int)$p->query('SELECT COUNT(*) FROM states')->fetchColumn()];
}
function logline(string $dir, string $m): void { @file_put_contents($dir . '/backup.log', date('c') . ' ' . $m . "\n", FILE_APPEND); }

if ($cmd === 'verify' || $cmd === 'restore') {
  $f = $argv[2] ?? ''; if (!is_file($f)) fatal('Sicherungsdatei nicht gefunden');
  $raw = dec((string)file_get_contents($f), $key);
  $tmp = tempnam(sys_get_temp_dir(), 'zvv'); file_put_contents($tmp, $raw); chmod($tmp, 0600);
  $r = check_db($tmp);
  if (!$r['ok']) { unlink($tmp); fatal('Datenbank der Sicherung ist beschädigt'); }
  if ($cmd === 'restore') {
    $t = $argv[3] ?? ''; if ($t === '' || file_exists($t)) { unlink($tmp); fatal('Ziel fehlt oder existiert bereits'); }
    rename($tmp, $t); chmod($t, 0600); echo "Wiederhergestellt nach $t\n";
  } else unlink($tmp);
  echo "OK: Integrität in Ordnung, {$r['users']} Konten, {$r['states']} Datenstände\n"; exit(0);
}

$dir = (string)($CFG['backup_dir'] ?? '');
if ($dir === '') fatal('backup_dir ist nicht gesetzt (Ordner ausserhalb des Web-Verzeichnisses)');
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) fatal('backup_dir kann nicht angelegt werden');
$keep = max(1, (int)($CFG['backup_keep'] ?? 14));
$tmp = $dir . '/.tmp-' . bin2hex(random_bytes(4)) . '.sqlite';
try {
  $src = new PDO('sqlite:' . $CFG['db_path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $src->exec('PRAGMA busy_timeout=10000');
  $src->exec("VACUUM INTO " . $src->quote($tmp));
  $r = check_db($tmp); if (!$r['ok']) throw new RuntimeException('Integritätsprüfung der Kopie fehlgeschlagen');
  $blob = enc(gzencode((string)file_get_contents($tmp), 9), $key);
} catch (Throwable $e) { @unlink($tmp); logline($dir, 'FEHLER ' . $e->getMessage()); fatal($e->getMessage()); }
unlink($tmp);
$name = 'zahlvio-' . date('Ymd-His') . '.sqlite.gz.enc';
file_put_contents("$dir/$name", $blob, LOCK_EX); chmod("$dir/$name", 0600);
$copy = (string)($CFG['backup_copy_dir'] ?? '');
if ($copy !== '') { if (is_dir($copy) || @mkdir($copy, 0700, true)) { if (!@copy("$dir/$name", "$copy/$name")) logline($dir, 'WARNUNG Zweitkopie fehlgeschlagen'); } else logline($dir, 'WARNUNG backup_copy_dir nicht nutzbar'); }
foreach ([$dir, $copy] as $d) {
  if ($d === '' || !is_dir($d)) continue;
  $fs = glob($d . '/zahlvio-*.sqlite.gz.enc') ?: []; sort($fs);
  foreach (array_slice($fs, 0, max(0, count($fs) - $keep)) as $old) @unlink($old);
}
logline($dir, "OK $name, {$r['users']} Konten, " . strlen($blob) . ' Bytes');
echo "OK: $dir/$name\n";
