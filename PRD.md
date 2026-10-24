# Frankfurtopoly – PRD (v0.1)

Monopoly mit Frankfurter Straßen für 2–3 Spieler (Daniel, Lea, Kira) über das Netz, als Web-App im Browser auf
Android-Smartphone und -Tablet. Live: **https://phpexpert.de/games/frankfurtopoly/**

Status: **v0.1 – umgesetzt mit Standardannahmen (Abschnitt 9), offene Fragen in Abschnitt 10.**

## 1. Ziele
- Spielbar im Browser auf Android-Smartphone (z. B. Pixel 8a) und -Tablet (z. B. Lenovo Tab M11), Hoch- und Querformat.
- Als PWA installierbar („Zum Startbildschirm hinzufügen“).
- Vanilla JS, kein Build-Schritt; Backend PHP auf dem eigenen Webspace, kein Datenbankserver.
- Regeln wie beim klassischen Monopoly.

## 2. Nicht-Ziele
- Keine Konten, keine Registrierung, keine öffentlichen Lobbys, keine Computergegner (v0.1), kein Chat (v0.1).

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
- Spielfiguren = farbige Kreise mit Initiale (Daniel blau, Lea pink, Kira lila), Besitz = Kreis in der Feldecke.
- Nur die Aktionen, die gerade erlaubt sind, werden als große Buttons angezeigt.

## 7. Technik
- Ordner `frankfurtopoly/` im Repo = `phpexpert.de/games/frankfurtopoly/` auf dem Webspace. Frontend und API auf demselben
  Origin, daher kein CORS und kein Schlüssel nötig.
- `api.php`: ein JSON-Zustand in `data/state.json` mit `flock`, `data/` per `.htaccess` gesperrt.
  `engine.php`: Regel-Engine als reine Funktionen. Server ist maßgeblich (würfelt, prüft jeden Zug).
- Synchronisation per Polling alle 1,5 s (`api.php?v=<version>` → `{"same":true}`, wenn sich nichts geändert hat).
- Service Worker cacht nur die Oberfläche (`frankfurtopoly-v1`), nie `api.php`.
- Deploy: GitHub Action bei jedem Push auf `main`: Tests, dann Upload per `lftp` (FTPS) mit den Secrets der
  Umgebung **FTP** (`FTP_SERVER`, `FTP_USER`, `FTP_PASSWORD`). Zielordner `FTP_DIR` (Variable, Standard `./frankfurtopoly/`).
  Ohne `--delete`, der laufende Spielstand auf dem Server bleibt erhalten.

## 8. Tests
- `php tests/engine_test.php`: Brett, Kaufen, Miete (Gruppe, Häuser, Schulen, Werke), Steuern, Los, JVA (Pasch, Zahlen,
  3. Versuch, dritter Pasch), Versteigerung, Bauen/Verkaufen/Hotel, Hypothek, Karten, Schulden/Bankrott, Verlassen, Handel.
- Manuell/Playwright: 3 Browser (Tablet quer, Handy quer, Handy hoch) gegen `php -S`.

## 9. Getroffene Standardannahmen
- Kein Zugangsschlüssel: Wer die URL kennt, kann einen freien Namen wählen (siehe offene Fragen).
- Kein Computergegner, kein Chat, kein Zugtimer.
- Startgeld 1.500 €, Werte wie Monopoly-Classic in Euro.
- Bei Bankrott an die Bank werden Felder frei statt versteigert.
- Handeln nur durch den Spieler am Zug.

## 10. Offene Fragen
Siehe README bzw. Antwort an Daniel; nach Klärung hier eintragen.
