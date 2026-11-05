<?php
// Kopiere diese Datei nach config.php und passe die Werte an.
return [
  // Öffentliche Adresse der Seite, ohne Schrägstrich am Ende
  'base_url'        => 'https://zahlvio.com',
  // Speicherort der Datenbank. Besser ausserhalb des Web-Verzeichnisses, zum Beispiel '/home/USER/zahlvio-data/zahlvio.sqlite'
  'db_path'         => __DIR__ . '/data/zahlvio.sqlite',
  // Absender für Systemmails (muss eine echte Adresse Ihrer Domain sein, sonst landen Mails im Spam)
  'mail_from'       => 'info@zahlvio.com',
  'mail_from_name'  => 'Zahlvio',
  // Hierhin gehen Anfragen aus dem Kontaktformular
  'contact_to'      => 'info@zahlvio.com',
  // Optional: eigene Adresse für Datenschutzanfragen (sonst contact_to)
  'privacy_to'      => '',
  // Hosting-Anbieter und Standort. Erscheint in der Datenschutzerklärung und der Liste der Unterauftragsverarbeiter
  'hoster'          => 'Infomaniak Network AG',
  'hoster_loc'      => 'Schweiz',
  // Anbieter, über dessen Server die E-Mails laufen (meist derselbe wie der Hoster)
  'mail_provider'   => 'Infomaniak Network AG',
  // 'mail' = PHP mail(), 'log' = nur in data/mail.log schreiben (zum Testen)
  'mail_mode'       => 'mail',
  // Registrierung offen? Bei false können sich nur bestehende Konten anmelden
  'allow_register'  => true,
  // Pilotphase: Registrierung nur mit Einladungscode. Eine oder mehrere Codes eintragen, zum Beispiel ['PILOT-2026-AB12', 'PILOT-2026-CD34'].
  // Leer lassen = jeder darf sich registrieren (nur nach Prüfung der Rechtstexte).
  'invite_codes'    => [],
  // Bei jeder neuen Registrierung eine Meldung an contact_to senden
  'notify_new_accounts' => true,
  // Maximale Rechnungs-Mails pro Konto und Tag
  'invoice_mail_per_day' => 40,
  // Nur setzen, wenn ein Reverse-Proxy davor steht, der X-Forwarded-For korrekt setzt
  'trust_proxy'     => false,
  // Datensicherung (siehe DEPLOY.md, Abschnitt 5). Schlüssel erzeugen mit: php api/backup.php genkey
  // Den Schlüssel zusätzlich getrennt vom Server aufbewahren (zum Beispiel im Passwortmanager). Ohne Schlüssel sind die Sicherungen nicht lesbar.
  'backup_key'      => '',
  // Ordner für die Sicherungen, ausserhalb des Web-Verzeichnisses, zum Beispiel '/home/USER/zahlvio-backups'
  'backup_dir'      => '',
  // Optional: zweiter Ort für eine Kopie (anderer Speicher oder gemountetes Laufwerk)
  'backup_copy_dir' => '',
  // Anzahl aufbewahrter Sicherungen (bei täglicher Sicherung: 14 = zwei Wochen)
  'backup_keep'     => 14,
  // Version der Rechtstexte, die bei der Registrierung akzeptiert wurden
  'terms_version'   => '2026-10-draft',
];
