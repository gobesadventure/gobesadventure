#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════════
#  GOBLIN — build drag-and-drop deploy folders for Hostinger
#  Run:  bash build-deploy.sh
#  Output:
#    deploy/main/  ->  upload to  goblinworld.xyz        (public_html root)
#    deploy/play/  ->  upload to  play.goblinworld.xyz   (subdomain root)
#  The repo root stays the editable SOURCE (relative links, local-testable).
# ════════════════════════════════════════════════════════════════════════
set -euo pipefail
cd "$(dirname "$0")"
ROOT="$(pwd)"
OUT="$ROOT/deploy"; MAIN="$OUT/main"; PLAY="$OUT/play"

rm -rf "$OUT"; mkdir -p "$MAIN" "$PLAY"

# ───────────── MAIN DOMAIN — landing (goblinworld.xyz) ─────────────
cp index.html token.js og.png favicon.svg favicon.png "$MAIN/"
# "GOBLIN WORLD" button → game on the subdomain
sed -i '' 's#<a href="world.html" id="system-btn"#<a href="https://play.goblinworld.xyz/" id="system-btn"#' "$MAIN/index.html"

# ───────────── PLAY SUBDOMAIN — game + backend (play.goblinworld.xyz) ─────────────
cp world.html "$PLAY/index.html"                                   # game becomes the subdomain index
cp system.html admin.html api.php mp.php config.php logo-nobg.png og.png favicon.svg favicon.png "$PLAY/"

# token.js stays a SINGLE source on the main domain (edit CA in one place)
sed -i '' 's#src="token\.js\([^"]*\)"#src="https://goblinworld.xyz/token.js\1"#' "$PLAY/index.html" "$PLAY/system.html"

# game (index) meta → subdomain · 🏠 HOME button → main landing
sed -i '' \
  -e 's#https://goblinworld.xyz/world.html#https://play.goblinworld.xyz/#g' \
  -e 's#https://goblinworld.xyz/og.png#https://play.goblinworld.xyz/og.png#g' \
  -e 's#href="index.html" id="homeBtn"#href="https://goblinworld.xyz/" id="homeBtn"#' \
  -e 's#upload world.html + mp.php#upload the game + mp.php#g' \
  "$PLAY/index.html"

# system dashboard meta + navigation
sed -i '' \
  -e 's#https://goblinworld.xyz/system.html#https://play.goblinworld.xyz/system.html#g' \
  -e 's#https://goblinworld.xyz/og.png#https://play.goblinworld.xyz/og.png#g' \
  -e 's#href="world.html"#href="https://play.goblinworld.xyz/"#' \
  -e 's#href="index.html"#href="https://goblinworld.xyz/"#' \
  "$PLAY/system.html"

echo "✅ Built:"
echo "  deploy/main → $(cd "$MAIN" && ls | tr '\n' ' ')"
echo "  deploy/play → $(cd "$PLAY" && ls | tr '\n' ' ')"
