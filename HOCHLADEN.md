# Zahlvio: Start ohne Konto-Backend

Diese Version zeigt die Website mit Live-Demo. Das Kontaktformular schickt Anfragen per E-Mail an **kontakt@arminsilic.de**. Login und Registrierung sind ausgeblendet ("Zugang auf Anfrage").

## Hochladen (10 Minuten)

1. **Postfach anlegen**: Im Infomaniak-Kundencenter bei der Domain zahlvio.com die E-Mail-Adresse `info@zahlvio.com` anlegen. Das Formular verschickt von dieser Adresse. Ohne bestehendes Postfach kann der Versand scheitern.
2. **Dateien hochladen**: den kompletten Inhalt dieses Ordners (nicht den Ordner selbst) in das Web-Verzeichnis der Domain legen, zum Beispiel per FTP/SFTP oder im Kundencenter über den Datei-Manager. Wichtig: auch die Datei `.htaccess` (beginnt mit einem Punkt, ist manchmal versteckt) und den Ordner `fonts`.
   - `index.html`, `kontakt.php`, `.htaccess`, `robots.txt`, Ordner `fonts`
3. **Domain und HTTPS**: bei Infomaniak ein kostenloses SSL-Zertifikat für zahlvio.com aktivieren (Let's Encrypt). PHP muss aktiv sein (ab Version 8.1).
4. **Testen**: https://zahlvio.com öffnen, unter "Beratung buchen" eine Test-Anfrage senden. Sie muss bei kontakt@arminsilic.de ankommen (Spam-Ordner prüfen). Die Antwort-Adresse ist die E-Mail der anfragenden Person.
5. **Zustellbarkeit**: bei Infomaniak für die Domain SPF und DKIM aktivieren (E-Mail-Dienst > Domain > Sicherheit), sonst landen Anfragen leichter im Spam.

## Danach

- Empfänger oder Absender ändern: die drei Zeilen oben in `kontakt.php` (ZV_TO, ZV_FROM, ZV_FROM_NAME).
- Die Anmeldefunktion (Konten) kommt später mit dem Paket `zahlvio-live.zip`. Dann ersetzt `index.html` diese Version, `kontakt.php` wird nicht mehr gebraucht.
- Die Rechtstexte (Impressum, Datenschutz, AGB) sind Entwürfe. Lassen Sie sie vor dem Start von einer Kanzlei prüfen.
