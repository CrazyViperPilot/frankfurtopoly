# Frankfurtopoly

Monopoly mit Frankfurter Straßen für Daniel, Lea und Kira – online, ab 2 Spielern, im Browser auf Handy und Tablet.

Live: https://phpexpert.de/games/frankfurtopoly/ · Anforderungen: [PRD.md](PRD.md)

## Aufbau
| Pfad | Inhalt |
|---|---|
| `frankfurtopoly/` | wird 1:1 nach `phpexpert.de/games/frankfurtopoly/` hochgeladen |
| `frankfurtopoly/board.json` | Felder, Preise, Mieten, Karten (von PHP und JS gelesen) |
| `frankfurtopoly/engine.php` | Regel-Engine (reine Funktionen) |
| `frankfurtopoly/api.php` | Lobby + Spiel-API, Zustand in `data/state.json` |
| `frankfurtopoly/js/`, `css/`, `index.html` | Oberfläche (Vanilla JS) |
| `tests/engine_test.php` | Regeltests |

## Lokal starten
```sh
php -S 127.0.0.1:8090 -t frankfurtopoly
# http://127.0.0.1:8090/ in mehreren Browserfenstern/Profilen öffnen
php tests/engine_test.php
```

## Deploy
Jeder Push auf `main` startet `.github/workflows/deploy.yml`: Tests, dann Upload per FTPS mit den Secrets der Umgebung
`FTP` (`FTP_SERVER`, `FTP_USER`, `FTP_PASSWORD`). Zielordner relativ zum FTP-Login über die Variable `FTP_DIR`
(Standard `./frankfurtopoly/`). Bei Änderungen an der Oberfläche `CACHE_VERSION` in `frankfurtopoly/sw.js` erhöhen.
