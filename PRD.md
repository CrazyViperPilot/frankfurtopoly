# Frankfurtopoly – PRD (v0.4)

Monopoly mit Frankfurter Straßen für 2–3 Spieler (Daniel, Lea, Kira) über das Netz, als Web-App im Browser auf
Android-Smartphone und -Tablet. Live: **https://phpexpert.de/games/frankfurtopoly/**

Status: **v0.2 – von Daniel geklärt (2026-10-24): offizielle Regeln, Computergegner und Chat ergänzt.**

## 1. Ziele
- Spielbar im Browser auf Android-Smartphone (z. B. Pixel 8a) und -Tablet (z. B. Lenovo Tab M11), Hoch- und Querformat.
- Als PWA installierbar („Zum Startbildschirm hinzufügen“).
- Vanilla JS, kein Build-Schritt; Backend PHP auf dem eigenen Webspace, kein Datenbankserver.
- Regeln wie beim klassischen Monopoly.

## 2. Nicht-Ziele
- Keine Konten, keine Registrierung, keine öffentlichen Lobbys, kein Zugangsschlüssel (die URL ist nicht öffentlich bekannt).

## 3. Spieler und Lobby
- Drei fest eingebaute Spieler: **Daniel, Lea, Kira**. Beim ersten Start wählt man seinen Namen; er wird lokal gemerkt
  und ist änderbar, solange man in keiner Runde ist.
- Zustände: *keine Runde* → *Lobby* → *läuft* → *beendet* → *Lobby* (neue Runde) oder *keine Runde*.
- Gibt es keine Runde, kann jeder **hosten**. Die anderen sehen „Daniel hostet eine Runde“ und können **mitspielen**.
- Nur der Host hat den Button **„Runde starten“**, aktiv ab **2 Spielern**.
- Der Host kann das Spiel jederzeit für alle beenden (Wertung nach Vermögen). Verlässt der Host, wird der nächste Spieler Host.
- Jeder Name ist nur einmal belegbar. Beim Beitritt bekommt das Gerät ein geheimes Token. Ein anderes Gerät kann den Namen
  erst übernehmen, wenn das alte 30 s lang nichts mehr vom Server geholt hat (Reconnect nach Handywechsel/Absturz).
- Wer ein laufendes Spiel verlässt, scheidet aus; sein Besitz geht an die Bank.
- Nach 6 Stunden ohne Aktion wird alles zurückgesetzt.

## 3a. Computergegner (v0.2)
- Der Host kann in der Lobby Computergegner hinzufügen und entfernen: **Bembel** 🤖 (orange) und **Handkäs** 🤖 (türkis).
  Maximal 4 Spieler insgesamt; so kann man auch allein gegen den Computer spielen (Start ab 2 Spielern inkl. Bots).
- Die Bots laufen auf dem Server: höchstens eine Aktion pro Sekunde, ausgelöst durch die Abfragen der Mitspieler.
- Strategie: kaufen, wenn danach noch 100 € übrig sind; bieten bis zum Kaufpreis; bauen, solange 250 € Reserve bleiben;
  Hypotheken ablösen ab 400 € Reserve; in der JVA Freikarte nutzen oder bei viel Geld freikaufen; bei Schulden erst
  Häuser verkaufen, dann Hypotheken, sonst Bankrott. Handelsangebote nehmen sie an, wenn sie mindestens 25 % mehr Wert
  bekommen und keine Straße hergeben, die dem anderen eine Farbgruppe komplett macht. Bots schlagen selbst keinen Handel vor.

## 3b. Chat (v0.2)
- 💬-Button unten rechts. Antippen blendet den Chat ein; nach ein paar Sekunden ohne Eingabe blendet er sich wieder aus.
- Neue Nachricht: Der Chat blendet sich kurz ein und wieder aus.
- **Jede Spielaktion** steht auch als (graue) Chatnachricht im Chat, z. B. „Kira kauft Emma-Kann-Grundschule für 200 €“,
  ebenso Lobby-Ereignisse (hostet, spielt mit, Spielstart, Sieger).
- Nachrichten werden nach **5 Minuten** gelöscht. Max. 200 Zeichen. Schreiben kann, wer in der Runde ist.

## 4. Spielbrett
40 Felder wie im Original; Preise und Mieten wie im klassischen Monopoly (in €).

| Farbe | Straßen (Preis) |
|---|---|
| Braun | Hanauer Landstraße (60), Mainzer Landstraße (60) |
| Hellblau | Leipziger Straße (100), Berger Straße (100), Oeder Weg (120) |
| Pink | Mörfelder Landstraße (140), Brückenstraße (140), Schweizer Straße (160) |
| Orange | Pariser Straße (180), Tel-Aviv-Platz (180), Europa-Allee (200) |
| Rot | Kaiserstraße (220), Große Bockenheimer Straße (220), Bockenheimer Landstraße (240) |
| Gelb | Taunusanlage (260), Neue Mainzer Straße (260), Römerberg (280) |
| Grün | Rathenauplatz (300), Börsenplatz (300), Opernplatz (320) |
| Dunkelblau | Goethestraße (350), Zeil (400) |

- **Statt Bahnhöfe (je 200 €):** Emma-Kann-Grundschule, Stadtgymnasium, Neues Gymnasium, Goethe-Gymnasium.
  Miete 25/50/100/200 € je nach Anzahl Schulen des Besitzers.
- **Statt Elektrizitätswerk:** Mainova (150 €). **Statt Wasserwerk:** Main-Wasserwerk (150 €). Miete 4× bzw. (beide) 10× Augenzahl.
- Gefängnis = **JVA Preungesheim**, „Gehe ins Gefängnis“ = **Ab in die JVA**, Frei Parken, Los,
  Einkommensteuer (200 €), Zusatzsteuer (100 €).
- 16 Ereigniskarten und 16 Gemeinschaftskarten mit den Original-Effekten und Frankfurter Texten
  (z. B. „Schwarzfahren in der U4 erwischt“, „Apfelweinfest auf dem Römerberg“).
- Alle Daten stehen in `frankfurtopoly/board.json` (eine Quelle für PHP und JS).

## 5. Regeln
- Start mit 1.500 €, Reihenfolge zufällig. Über/auf Los: +200 €.
- Zwei Würfel; Pasch → nochmal würfeln; dritter Pasch in Folge → JVA.
- Freies Grundstück: **kaufen** oder **versteigern** (alle Spieler bieten, auch der Ablehnende; Schritte +10/+50/+100 €;
  wer aussteigt, ist raus; der Letzte mit Höchstgebot kauft).
- Miete: Grundmiete, **doppelt** bei kompletter Farbgruppe ohne Häuser, sonst laut Häuser-/Hoteltabelle. Keine Miete auf belastete Felder.
- **Bauen** nur mit kompletter Farbgruppe ohne Hypothek, **gleichmäßig**; 5. Stufe = Hotel (gibt 4 Häuser zurück).
  Bank hat 32 Häuser und 12 Hotels. Verkauf zum halben Preis, ebenfalls gleichmäßig.
- **Hypothek**: halber Kaufpreis; nur ohne Häuser in der Farbgruppe; Ablösen kostet Hypothek + 10 %.
- **JVA**: raus mit Pasch (kein Extrawurf), 50 € zahlen (vor dem Würfeln) oder Freikarte. Nach dem 3. erfolglosen Versuch
  50 € zahlen und ziehen. Auf Besuch stehen kostet nichts.
- Karten „nächste Schule“ = doppelte Miete; „nächstes Werk“ = neu würfeln, 10× Augenzahl.
- **Zeitlimits** (v0.3, vom Server durchgesetzt, auch wenn die App zu ist; Countdown in der App):
  - Nach dem Würfeln (Phase „Zug beenden“) endet der Zug nach **30 s ohne Aktion** automatisch. Jede eigene Aktion
    (bauen, Hypothek, Handel, Chat) startet die 30 s neu. Kaufen/Versteigern und Schulden werden nie automatisch entschieden.
  - Versteigerung: Wer **20 s** nach dem letzten Höchstgebot nicht geboten hat, steigt automatisch aus
    (jedes neue Höchstgebot startet die 20 s für alle neu; der Höchstbietende bleibt drin).
  - Würfeln (v0.4): Wer **60 s** nicht würfelt, für den würfelt der Server; im Chat steht „Daniel: automatisch gewürfelt.“
    In der JVA ist das der Pasch-Versuch.
  - Handelsangebote (v0.4): Ohne Antwort laufen sie nach **30 s** ab („Das Angebot von … ist abgelaufen“).
  - Alle Countdowns stehen als Sekunden plus ablaufender Balken direkt im jeweiligen Button
    (Würfeln, Zug beenden, Aussteigen, Annehmen bzw. Zurückziehen); die letzten 10 s ist der Balken rot.
- **Handeln**: Der Spieler am Zug bietet einem Mitspieler Felder und/oder Geld gegen Felder und/oder Geld an; der andere
  nimmt an oder lehnt ab. Felder mit Häusern in ihrer Farbgruppe sind nicht handelbar. Hypotheken gehen ohne Zinsen mit.
- **Schulden**: Kann man nicht zahlen, muss man Häuser verkaufen/Hypotheken aufnehmen (die Zahlung erfolgt automatisch,
  sobald genug Geld da ist) oder **Bankrott** erklären. Bankrott an einen Spieler: alles (Geld, Felder, Freikarten) geht an ihn,
  Häuser werden vorher zum halben Preis verkauft. Bankrott an die Bank: Felder werden wieder frei.
- **Ende**: Wer als Letzter übrig bleibt, gewinnt. Beendet der Host vorzeitig, gewinnt das größte Vermögen
  (Geld + Kaufpreise, bei Hypothek halb, + Häuserpreise).
- Frei Parken: keine Geldsammlung (offizielle Regel).

## 6. UI
- Brett als 11×11-Raster, skaliert über Container-Einheiten. Querformat: Brett links, Spielerliste/Aktionen rechts.
  Hochformat: Brett oben, darunter Spieler/Aktionen.
- Feld antippen → Details (Mieten, Besitzer) und, wenn es dir gehört, Bauen/Verkaufen/Hypothek.
- Brettmitte: Würfel, „X ist dran“, zuletzt gezogene Karte (zusätzlich als Einblendung).
- Spielfiguren = große farbige Kreise mit Initiale, weißem Rand, dunkler Kontur und Schatten (Daniel blau, Lea pink, Kira lila,
  Bembel orange, Handkäs türkis); sie schweben über dem Brett und gleiten beim Ziehen; wer dran ist, hat einen gelben Leuchtring.
- **Letzter Zug** als gestrichelte Linie in der Farbe des Spielers mit Pfeil, entlang der gelaufenen Felder (inkl. Kartenzügen;
  „Ab in die JVA“ als gerade Linie). Sie bleibt bis zum nächsten Wurf stehen.
- Besitz = Farbband am Außenrand des Feldes plus dünner Rahmen in Besitzerfarbe; mit Hypothek gestreift.
- Nur die Aktionen, die gerade erlaubt sind, werden als große Buttons angezeigt.

## 7. Technik
- Ordner `frankfurtopoly/` im Repo = `phpexpert.de/games/frankfurtopoly/` auf dem Webspace. Frontend und API auf demselben
  Origin, daher kein CORS und kein Schlüssel nötig.
- `api.php`: ein JSON-Zustand in `data/state.json` mit `flock`, `data/` per `.htaccess` gesperrt.
  `engine.php`: Regel-Engine als reine Funktionen. Server ist maßgeblich (würfelt, prüft jeden Zug).
- Synchronisation per Polling alle 1,5 s (`api.php?v=<version>` → `{"same":true}`, wenn sich nichts geändert hat).
- Service Worker cacht nur die Oberfläche (`frankfurtopoly-v5`), nie `api.php`.
- `bot.php`: Entscheidungen der Computergegner (reine Funktionen, getestet mit kompletten Bot-gegen-Bot-Spielen).
- Deploy: GitHub Action bei jedem Push auf `main`: Tests, dann Upload per `lftp` (FTPS) mit den Secrets der
  Umgebung **FTP** (`FTP_SERVER`, `FTP_USER`, `FTP_PASSWORD`). Zielordner `FTP_DIR` (Variable, Standard `./frankfurtopoly/`).
  Ohne `--delete`, der laufende Spielstand auf dem Server bleibt erhalten.

## 8. Tests
- `php tests/engine_test.php`: Brett, Kaufen, Miete (Gruppe, Häuser, Schulen, Werke), Steuern, Los, JVA (Pasch, Zahlen,
  3. Versuch, dritter Pasch), Versteigerung, Bauen/Verkaufen/Hotel, Hypothek, Karten, Schulden/Bankrott, Verlassen, Handel,
  20 komplette Bot-Spiele ohne ungültige Aktion und ohne negatives Geld, Handelsentscheidung der Bots.
- Manuell/Playwright: 3 Browser (Tablet quer, Handy quer, Handy hoch) gegen `php -S`.

## 9. Entscheidungen (geklärt mit Daniel)
- Zielordner `phpexpert.de/games/frankfurtopoly/` passt (FTP-Login startet in `/games`).
- Kein Zugangsschlüssel – die URL kennt niemand.
- Offizielle Regeln: Startgeld 1.500 €, Frei Parken ohne Geld, Werte wie Monopoly-Classic in Euro.
- Kein Zeitlimit: Der Host kann jederzeit beenden, dann gewinnt das größte Vermögen.
- Bei Bankrott an die Bank werden Felder frei statt versteigert; Handeln nur durch den Spieler am Zug;
  Hypotheken gehen beim Handel ohne 10 % Zinsen mit.
- Straßen und Farbgruppen wie in Abschnitt 4.
- Eintrag auf der Games-Startseite (`crazyviperpilot.github.io/games/`) mit Link nach phpexpert.de.
- Kein Timer bei Kauf- und Schuldenentscheidungen; sonst die Zeitlimits aus Abschnitt 5.
