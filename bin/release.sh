#!/usr/bin/env bash
# Publie une nouvelle version de MidiConcept Core.
#
#   bin/release.sh 1.2.0          → version précise
#   bin/release.sh patch|minor|major
#
# Met à jour la version dans midiconcept-core.php, commite, tague vX.Y.Z et pousse.
# GitHub Actions construit ensuite le zip et publie la release.
# Sous Windows : à lancer depuis Git Bash (terminal "Git Bash" dans Cursor).

set -euo pipefail
cd "$(dirname "$0")/.."

FILE="midiconcept-core.php"
CURRENT=$(grep -m1 -E '^\s*\*\s*Version:' "$FILE" | awk '{print $NF}')

if [ $# -ne 1 ]; then
  echo "Version actuelle : $CURRENT"
  echo "Usage : bin/release.sh <x.y.z|patch|minor|major>"
  exit 1
fi

IFS='.' read -r MA MI PA <<< "$CURRENT"
case "$1" in
  patch) NEW="$MA.$MI.$((PA + 1))" ;;
  minor) NEW="$MA.$((MI + 1)).0" ;;
  major) NEW="$((MA + 1)).0.0" ;;
  *)     NEW="$1" ;;
esac

if ! [[ "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Version invalide : $NEW"; exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
  echo "Il reste des modifications non commitées. Commite-les d'abord :"
  git status --short
  exit 1
fi

if git rev-parse "v$NEW" >/dev/null 2>&1; then
  echo "Le tag v$NEW existe déjà."; exit 1
fi

echo "MidiConcept Core : $CURRENT → $NEW"
read -r -p "Notes de version (une ligne, facultatif) : " NOTES

sed -i.bak -E "s/^( \* Version:[[:space:]]+).*/\1$NEW/" "$FILE"
sed -i.bak -E "s/define\('MC_CORE_VERSION', '[^']*'\)/define('MC_CORE_VERSION', '$NEW')/" "$FILE"
rm -f "$FILE.bak"

git add "$FILE"
git commit -m "Version $NEW${NOTES:+ — $NOTES}"
git tag -a "v$NEW" -m "${NOTES:-Version $NEW}"
git push
git push origin "v$NEW"

echo
echo "✔ v$NEW poussée. La release sera prête dans ~1 min :"
echo "  $(git remote get-url origin | sed -E 's#git@github.com:#https://github.com/#; s#\.git$##')/releases"
