#!/usr/bin/env bash
# Einrichtung der Sandbox für den Browser-Durchlauf (Regel R-12), einmal je
# Container. Vorbild: NAdoku tools/sandbox/aufbauen.sh.
#
# - installiert MySQL, falls es fehlt, und startet es (lokale Testdatenbank,
#   siehe docs/betrieb.md Abschnitt 6)
# - holt die Systembibliotheken, die WebKit braucht
# - lädt Firefox und WebKit für das vorinstallierte Playwright in einen
#   eigenen Ordner (/opt/ws-browsers) und verlinkt das vorhandene Chromium
#   dorthin; /opt/pw-browsers bleibt unverändert
# - startet zum Nachweis alle drei Engines
set -u
ZIEL=/opt/ws-browsers

echo "MySQL"
# Fehlt MySQL in der Sandbox, wird es nachinstalliert (AP-15: kam in einer Sandbox vor)
if ! command -v mysqld >/dev/null 2>&1; then
    echo "  MySQL fehlt, wird installiert"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-server >/dev/null 2>&1 || { apt-get update -qq >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-server >/dev/null 2>&1 || echo "  ACHTUNG: MySQL ließ sich nicht installieren"; }
fi
service mysql start >/dev/null 2>&1 || echo "  ACHTUNG: MySQL startet nicht"

echo "Bibliotheken für WebKit"
PAKETE="libenchant-2-2 libsecret-1-0 libwayland-server0 libmanette-0.2-0 libgtk-4-1 libevent-2.1-7t64 libgstreamer-plugins-bad1.0-0 libavif16 libgles2"
apt-get install -y -qq $PAKETE >/dev/null 2>&1 || { apt-get update -qq >/dev/null 2>&1; apt-get install -y -qq $PAKETE >/dev/null 2>&1 || echo "  ACHTUNG: apt-get fehlgeschlagen"; }

echo "Engines"
mkdir -p "$ZIEL"
for d in /opt/pw-browsers/*; do
    n=$(basename "$d")
    [ -e "$ZIEL/$n" ] || ln -s "$d" "$ZIEL/$n"
done
PLAYWRIGHT_BROWSERS_PATH="$ZIEL" npx --no-install playwright install firefox webkit >/dev/null 2>&1 || echo "  ACHTUNG: Nachladen fehlgeschlagen"

echo "Nachweis"
PLAYWRIGHT_BROWSERS_PATH="$ZIEL" node -e '
const pw = require("playwright");
(async () => { let ok = true;
  for (const n of ["chromium", "firefox", "webkit"]) {
    try { const b = await pw[n].launch(); console.log("  " + n + " " + b.version()); await b.close(); }
    catch (e) { ok = false; console.log("  " + n + " FEHLT: " + String(e).split("\n")[0]); }
  }
  process.exit(ok ? 0 : 1); })();'
