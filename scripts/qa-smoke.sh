#!/usr/bin/env bash
# Hackstra QA smoke + layout regression guard.
#
# Run BEFORE every push (Batch 6 onwards). Fails (exit 1) if any check fails.
#
#   scripts/qa-smoke.sh [/path/to/app]           # static checks only
#   BASE_URL=http://127.0.0.1:8899 scripts/qa-smoke.sh [/path/to/app]
#                                                 # + live role-login checks
#
# The layout checks encode the Loop 17 root-cause fixes (2026-10-21 owner
# screenshots): unshrinkable CSS grid tracks (repeat(N,1fr)) and forced
# full-height dashboard panels caused right-edge cut-offs and pages of
# empty stretched white on EVERY portal, because all pages share qs.css
# and the same dashboard components. If a future edit reintroduces those
# patterns, this script fails before the push — not on the owner's screen.
set -u
APP="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
PHP="${PHP:-php}"
FAIL=0
say()  { printf '%s\n' "$*"; }
bad()  { say "FAIL: $*"; FAIL=1; }
good() { say " ok : $*"; }

say "== QA smoke for $APP"

# --- 1. PHP syntax -----------------------------------------------------------
if command -v "$PHP" >/dev/null 2>&1; then
  N=0
  while IFS= read -r f; do
    N=$((N+1))
    "$PHP" -l "$f" >/dev/null 2>&1 || bad "php -l: $f"
  done < <(find "$APP/app" "$APP/routes" "$APP/config" "$APP/database" -name '*.php' 2>/dev/null)
  [ "$FAIL" = 0 ] && good "php -l clean ($N files)"
else
  say "skip: php not available for syntax checks"
fi

CSS="$APP/public/assets/css/qs.css"

# --- 2. Layout regression guards (Loop 17) -----------------------------------
# qs.css grows by append-only override sections, so legacy base rules remain
# in the file UNDER their overrides. The guard therefore checks (a) the
# Loop 17 overrides are still present, and (b) no NEW unshrinkable grid
# tracks (repeat(N,1fr), N>=2 -> minmax(auto,1fr): tracks cannot shrink
# below content and push cards off the right edge) appear at or after the
# Loop 17 section — i.e. in any future edit.
L17_LINE="$(grep -n 'Loop 17' "$CSS" | head -1 | cut -d: -f1)"
if [ -z "$L17_LINE" ]; then
  bad "Loop 17 layout section missing from qs.css"
else
  grep -q 'repeat(5,minmax(0,1fr))' "$CSS" \
    && grep -q 'repeat(4,minmax(0,1fr))' "$CSS" \
    && good "Loop 17 shrink-safe grid overrides present" \
    || bad "Loop 17 shrink-safe grid overrides missing from qs.css"
  if tail -n +"$L17_LINE" "$CSS" | grep -nE 'grid-template-columns:repeat\([2-9][0-9]*, ?1fr\)' >/dev/null 2>&1; then
    bad "unshrinkable repeat(N,1fr) tracks added at/after Loop 17 (use minmax(0,1fr)):"
    tail -n +"$L17_LINE" "$CSS" | grep -nE 'grid-template-columns:repeat\([2-9][0-9]*, ?1fr\)' | head -5 | sed 's/^/       /'
  else
    good "no new unshrinkable grid tracks after Loop 17"
  fi
fi
# 2b. Forced panel height: the Loop 7 rule height:calc(100% - 1.5rem) on
#     .dashboard-panel is only safe with the Loop 17 :not(:only-of-type)
#     override present, so stacked panels hug their content.
if grep -q 'height:calc(100% - 1.5rem)' "$CSS"; then
  if grep -q ':not(:only-of-type)' "$CSS"; then
    good "forced panel height has the :not(:only-of-type) guard"
  else
    bad "qs.css forces .dashboard-panel height without the Loop 17 guard"
  fi
fi
# 2c. Panels/columns must be allowed to shrink: min-width:0 guard present.
grep -q 'dashboard-panel' "$CSS" && grep -q 'min-width:0' "$CSS" \
  && good "min-width:0 shrink guards present" \
  || bad "min-width:0 shrink guards missing from qs.css"
# 2d. Dead decorative heading buttons must not come back ANYWHERE.
if grep -rq 'dashboard-heading-icon' "$APP/resources/views" 2>/dev/null; then
  bad "dead dashboard-heading-icon button present in:"
  grep -rl 'dashboard-heading-icon' "$APP/resources/views" | head -5 | sed 's/^/       /'
else
  good "no dead heading-icon buttons in any blade"
fi
# 2e. Icon audit (Batch 8): every icon-* class used in a blade must exist
#     in the icomoon font, or it renders as an empty circle/space.
FONT="$APP/public/global_assets/css/icons/icomoon/styles.css"
if [ -f "$FONT" ]; then
  MISSING="$(comm -23 \
    <(grep -rhoE 'icon-[a-z0-9-]+' "$APP/resources/views" | sort -u) \
    <(grep -oE '\.icon-[a-z0-9-]+:before' "$FONT" | sed 's/^\.//;s/:before//' | sort -u))"
  if [ -n "$MISSING" ]; then
    bad "icon classes used in blades but missing from the font:"
    printf '%s\n' "$MISSING" | head -8 | sed 's/^/       /'
  else
    good "every icon class used in blades exists in the font"
  fi
fi

# --- 3. Blade compilation -----------------------------------------------------
if command -v "$PHP" >/dev/null 2>&1 && [ -f "$APP/artisan" ]; then
  (cd "$APP" && "$PHP" artisan view:cache >/dev/null 2>&1) \
    && good "all blades compile (view:cache)" \
    || bad "blade compilation failed (view:cache)"
fi

# --- 4. Live role checks (optional) -------------------------------------------
if [ -n "${BASE_URL:-}" ]; then
  JAR="$(mktemp)"
  login() { # email password -> http code of GET /
    rm -f "$JAR"
    local page token
    page="$(curl -s -c "$JAR" "$BASE_URL/login")" || return 000
    token="$(printf '%s' "$page" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')"
    curl -s -b "$JAR" -c "$JAR" -o /dev/null -d "_token=$token&identity=$1&password=$2" "$BASE_URL/login"
    curl -s -b "$JAR" -o /tmp/qa_home.html -w '%{http_code}' "$BASE_URL/"
  }
  check_role() { # label email password
    local code; code="$(login "$2" "$3")"
    [ "$code" = "200" ] && good "$1 home = 200" || bad "$1 home = $code (want 200)"
  }
  check_role "Super Admin" cj@cj.com cj
  grep -q 'l17-list-scroll' /tmp/qa_home.html \
    && good "platform home serves Loop 17 layout" \
    || bad "platform home missing Loop 17 markers"
  check_role "Admin"       admin@admin.com cj
  check_role "Teacher"     teacher@teacher.com cj
  check_role "Parent"      parent@parent.com cj
  check_role "Accountant"  accountant@accountant.com cj
  grep -q 'feeDefaulter\|Defaulter' /tmp/qa_home.html \
    && good "accountant home renders (no 500 regression)" \
    || say "note: accountant home defaulter marker not found (check manually)"
  check_role "Librarian"   librarian@librarian.com cj
  rm -f "$JAR"
else
  say "skip: BASE_URL not set (live role checks skipped)"
fi

say "== done"
[ "$FAIL" = 0 ] && say "QA SMOKE: PASS" || say "QA SMOKE: FAIL"
exit "$FAIL"
