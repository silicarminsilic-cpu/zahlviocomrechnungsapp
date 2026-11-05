# Zahlvio online stellen (Infomaniak, Hostpoint oder anderer Apache-Hoster mit PHP)

Dieses Paket ist eine echte Anwendung: Registrierung, Login, Konto-Speicher in einer Datenbank, Kontaktformular, Rechnungsversand per E-Mail mit PDF. Es braucht **PHP 8.1 oder neuer** mit SQLite (Standard bei allen gängigen Hostern) und die E-Mail-Funktion des Hosters. Kein Node, keine Zusatzdienste.

## 1. Einrichten (ca. 30 Minuten)

1. **Domain auf den Hoster zeigen lassen** und ein Web-Hosting mit PHP 8.1+ buchen. HTTPS (Let's Encrypt) im Kundencenter aktivieren.
2. **E-Mail-Adressen anlegen** bei demselben Hoster, `info@zahlvio.com` (Absender der Systemmails und Empfang der Kontaktanfragen). SPF und DKIM für die Domain im Kundencenter einschalten, sonst landen Mails im Spam.
3. **Dateien hochladen**: den ganzen Inhalt dieses Ordners in das Web-Verzeichnis (`public_html` oder `web`). Wichtig: auch die versteckten Dateien `.htaccess`, `api/.htaccess` und `api/data/.htaccess` mitnehmen.
4. **Konfiguration**: `api/config.sample.php` kopieren nach `api/config.php` und ausfüllen (Adresse der Seite, Absender, Empfänger, Hoster-Name und Standort für die Datenschutzerklärung). `config.php` nie öffentlich weitergeben.
5. **Datenbank-Ordner**: `api/data/` muss für PHP beschreibbar sein (Rechte 750 oder 770). Besser: in `config.php` bei `db_path` einen Ordner **ausserhalb** des Web-Verzeichnisses angeben (zum Beispiel `/home/USER/zahlvio-data/zahlvio.sqlite`).
6. **Testen**: `https://deinedomain.ch/api/index.php?a=me` muss `{"user":null,...}` zeigen. Dann auf der Seite ein Testkonto anlegen, die Bestätigungs-E-Mail abwarten, eine Rechnung erstellen und an dich selbst senden.
   Kommt keine Mail an: in `config.php` kurz `'mail_mode' => 'log'` setzen, dann steht der Text in `api/data/mail.log`. So siehst du, ob die App funktioniert und nur der Mailversand des Hosters hakt.
7. **Datensicherung**: einrichten wie in Abschnitt 5 beschrieben. Das ist Pflicht vor dem Start, weil die Rechtstexte tägliche verschlüsselte Sicherungen zusagen.

## 2. Was funktioniert

- Registrierung mit Firmendaten und Zustimmung zu AGB, AVV und Datenschutz (Zeitpunkt und Version werden gespeichert).
- Login mit Sperre nach Fehlversuchen, Passwort-Reset per E-Mail, E-Mail-Bestätigung, Passwort ändern.
- Alle Daten eines Kontos (Kunden, Rechnungen, Angebote, Einstellungen, Design) werden automatisch auf dem Server gespeichert. Zwei Fenster überschreiben sich nicht gegenseitig.
- Rechnungs-PDF in vier Stilen, Versand per E-Mail mit PDF-Anhang (nur für bestätigte Konten, maximal 40 pro Tag und Konto, Antworten gehen an den Absender).
- Datenexport (JSON) und Konto löschen im Benutzerprofil.
- Kontaktformular: Anfrage wird gespeichert und per E-Mail an `contact_to` geschickt.
- Die Live-Demo ohne Anmeldung bleibt erhalten und speichert nur im Browser.
- Schriftarten kommen vom eigenen Server, es gibt keine Verbindung zu Google.

## 3. Was bewusst noch NICHT drin ist

- **Zahlungen / Abo-Abrechnung** (Stripe o. ä.): Alle Konten sind kostenlos. Die Tarif-Grenzen (zum Beispiel 5 Kunden) werden nur in der Oberfläche angezeigt und nicht serverseitig erzwungen. Preise auf der Seite sind Platzhalter.
- **CH-QR-Zahlteil** auf der Rechnung, **validierte E-Rechnung** (die XML-Vorschau ist nicht gegen den Standard geprüft).
- **Team-Einladungen**, **Mahnungen per Automatik**, Bankanbindung: sind nur als Oberfläche vorhanden und versenden nichts selbst.
- **Zwei-Faktor-Anmeldung** und ein Admin-Bereich. Anfragen und Konten lässt du dir bei Bedarf direkt aus der Datenbank anzeigen.
- Ein Test mit vielen gleichzeitigen Nutzern. SQLite trägt kleine bis mittlere Lasten gut. Bei starkem Wachstum wäre ein Wechsel auf MySQL/PostgreSQL fällig.

## 4. Vor dem öffentlichen Start, ohne Ausnahme

1. **Rechtstexte juristisch prüfen lassen** (Anwältin oder Anwalt in Liechtenstein): AGB, Auftragsverarbeitungsvertrag, Datenschutzerklärung, Impressum. Es sind Entwürfe, kein Rechtsrat. In den Texten stehen noch Stellen in [eckigen Klammern]: Supportzeiten, Haftungs-Mindestbetrag, ggf. weitere Angaben. Solange diese Klammern stehen oder nicht geprüft ist, sollte die Registrierung geschlossen bleiben (`'allow_register' => false`).
2. **Steuerliche Logik** für DE und AT von einer Steuerfachperson prüfen lassen, bevor Kunden damit echte Rechnungen stellen. Die Rechnungen sind ohne QR-Zahlteil in der Schweiz nicht für jeden Zahlungsweg geeignet.
3. **Impressum** (Domain, E-Mail, MWST-Nr. 64050 sind eingetragen) und Kontaktdaten gegen den Handelsregisterauszug prüfen. Telefonnummer, WhatsApp-Nutzung und Supportzeiten bestätigen.
4. **Aufbewahrungspflicht**: Rechnungen müssen Kunden oft 10 Jahre aufbewahren. Das Löschen eines Kontos löscht die Daten endgültig. Ein klarer Hinweis dazu steht im Dialog, ob das reicht, klärt die Anwältin.
5. **Datenschutz-Folgen**: Prüfen lassen, ob ein Verzeichnis der Verarbeitungstätigkeiten und ein Vertreter nach Art. 27 DSGVO nötig sind (Sitz in Liechtenstein, Kunden in DE/AT/CH).

## 5. Dateien

```
index.html          die ganze Anwendung (Startseite, Demo, Login, App)
api/index.php       Server-Schnittstelle
api/config.php      deine Einstellungen (aus config.sample.php erstellen)
api/data/           Datenbank und Testmails (nicht öffentlich zugänglich)
fonts/              Schriftdateien (Inter, Plus Jakarta Sans, Lizenz SIL OFL)
.htaccess           HTTPS-Pflicht und Sicherheits-Header
```

## 5. Datensicherung einrichten (ca. 15 Minuten)

Das Paket bringt `api/backup.php` mit. Es erstellt eine konsistente Kopie der Datenbank, prüft sie, packt und verschlüsselt sie mit AES-256-GCM und behält die letzten Sicherungen. Aufruf nur per Kommandozeile, über den Browser gibt die Datei 404 zurück.

1. **Schlüssel erzeugen**: per SSH `php api/backup.php genkey` (oder über das Terminal im Kundencenter). Die ausgegebene Zeichenfolge in `config.php` bei `backup_key` eintragen **und zusätzlich in einem Passwortmanager ablegen**, getrennt vom Server. Ohne diesen Schlüssel kann niemand die Sicherungen lesen, auch Sie nicht.
2. **Ordner festlegen**: `backup_dir` auf einen Ordner **ausserhalb** des Web-Verzeichnisses setzen, zum Beispiel `/home/USER/zahlvio-backups`. Optional `backup_copy_dir` auf einen zweiten Speicherort (anderer Speicher oder Laufwerk). Mit `backup_keep` bestimmen Sie, wie viele Stände bleiben (14 = zwei Wochen bei täglicher Sicherung).
3. **Testlauf**: `php api/backup.php`. Es muss `OK:` und einen Dateinamen ausgeben. Im Backup-Ordner steht dann eine Datei `zahlvio-DATUM.sqlite.gz.enc` und `backup.log`.
4. **Täglich automatisch**: im Kundencenter einen Cron-Job anlegen, zum Beispiel täglich um 02:30 Uhr: `php /home/USER/sites/zahlvio.com/api/backup.php` (Pfad anpassen). Prüfen Sie am nächsten Tag, ob eine neue Datei da ist.
5. **Wiederherstellung testen** (mindestens vierteljährlich, und einmal vor dem Start): `php api/backup.php verify DATEI` entschlüsselt eine Sicherung und prüft sie. Zum Wiederherstellen: `php api/backup.php restore DATEI /pfad/zahlvio-neu.sqlite`, danach `db_path` darauf zeigen lassen. Halten Sie das Ergebnis des Tests mit Datum fest.
6. **Sicherungen ausserhalb des Servers**: laden Sie zusätzlich regelmässig eine Sicherung auf einen Rechner, der nicht beim selben Hoster liegt. Die Dateien sind verschlüsselt, der Schlüssel bleibt separat.
7. **Hoster-Backup zusätzlich**: aktivieren Sie die automatische Sicherung des Hosters. Sie ersetzt die eigene Sicherung nicht.

Hinweis: Gelöschte Konten bleiben bis zum Ablauf der letzten Sicherung (14 Tage) in älteren Sicherungen. Das steht so in der Datenschutzerklärung. Stellen Sie bei einer Wiederherstellung sicher, dass inzwischen gelöschte Konten erneut gelöscht werden.

## 6. Automatisch von GitHub veröffentlichen

Infomaniak hat keine eingebaute GitHub-Anbindung. Dieses Repository bringt deshalb einen Workflow mit (`.github/workflows/deploy.yml`): Bei jedem Push auf `main` prüft GitHub die PHP-Dateien und überträgt die Seite per SSH zu Infomaniak. Die Dateien `api/config.php` und der Ordner `api/data/` auf dem Server bleiben dabei unberührt.

**Einmalig einrichten**

1. **Repository privat anlegen** (nicht öffentlich) und diese Dateien hineinlegen.
2. **SSH bei Infomaniak aktivieren**: Kundencenter > Web-Hosting > FTP/SSH-Benutzer. SSH-/SFTP-Zugang für den Benutzer einschalten.
3. **Eigenen Deploy-Schlüssel erzeugen** (auf Ihrem Rechner): `ssh-keygen -t ed25519 -f zahlvio-deploy -C "github-deploy" -N ""`. Den **öffentlichen** Teil (`zahlvio-deploy.pub`) beim Benutzer bei Infomaniak als erlaubten Schlüssel eintragen. Den **privaten** Teil gleich im nächsten Schritt bei GitHub hinterlegen und danach vom Rechner löschen.
4. **Host-Schlüssel des Servers holen**: `ssh-keyscan -t ed25519 ssh.clusterXXX.hosting.infomaniak.com`. Die ausgegebene Zeile gleicht der Fingerabdruck im Infomaniak-Kundencenter ab (wenn dort angezeigt).
5. **GitHub > Repository > Settings > Secrets and variables > Actions**, sechs Secrets anlegen:
   - `SFTP_HOST`: Server, zum Beispiel `ssh.clusterXXX.hosting.infomaniak.com`
   - `SFTP_USER`: SSH-Benutzer
   - `SFTP_KEY`: der private Schlüssel (kompletter Inhalt der Datei `zahlvio-deploy`)
   - `SFTP_HOST_KEY`: die Zeile aus Schritt 4
   - `SFTP_PATH`: Zielordner auf dem Server (Web-Verzeichnis der Domain, zum Beispiel `/home/clients/XXXX/sites/zahlvio.com`)
   - `SITE_URL`: `https://zahlvio.com`
6. **Beim ersten Mal** auf dem Server `api/config.php` aus `api/config.sample.php` anlegen (siehe Abschnitt 1, Punkt 4). Sie wird nie von GitHub überschrieben.
7. Push auf `main` oder in GitHub unter Actions > Deploy > Run workflow. Nach etwa einer Minute ist die Seite aktuell.

**Wichtig**

- Das Repository muss **privat** bleiben. Geheimnisse (`config.php`, Backup-Schlüssel, Datenbank) gehören nie hinein, `.gitignore` und der Workflow verhindern das.
- Aktivieren Sie in GitHub den Schutz für `main` (Pull Request vor dem Merge) und die Zwei-Faktor-Anmeldung für alle, die Zugriff haben.
- Wenn der Upload scheitert, steht der Grund im Protokoll unter Actions. Häufigste Ursachen: Schlüssel nicht eingetragen, falscher Pfad, SSH nicht aktiviert.

## 7. Pilotphase mit Einladungscode

Für die ersten 20 bis 30 Firmen: in `api/config.php` bei `invite_codes` einen oder mehrere Codes eintragen, zum Beispiel `['PILOT-2026-AB12']`. Dann ist die Registrierung nur mit Code möglich. Link zum Weitergeben: `https://zahlvio.com/?invite=PILOT-2026-AB12` (füllt das Feld vor). Bei jeder Registrierung geht eine Meldung an `contact_to`. Codes lassen sich jederzeit ändern oder entfernen.
