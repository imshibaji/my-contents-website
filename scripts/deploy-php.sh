#!/usr/bin/env bash
# Auto-deploy the PHP API to Hostinger.
#
#   npm run deploy
#   ./scripts/deploy-php.sh                 # host/port from .deploy.env or defaults
#   ./scripts/deploy-php.sh user@host 65002 # override
#
# Why this exists: uploading file-by-file silently dropped api/_env.php and then
# api/env-loader.php, each time taking the whole API to a blank HTTP 500 with no
# visible cause. This uploads the entire dist/api tree in one scp, so there is no
# subset to forget. It also lints on the server, checksums every file after the
# copy, and probes the live site before reporting success.
#
# Credentials are never stored here. SSH prompts for the password locally, or you
# set DEPLOY_SSH_KEY to a private key path.

set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
LOCAL="$REPO/dist/api"

DEFAULT_USER="u802934877"
DEFAULT_HOST="217.21.91.8"
DEFAULT_PORT="65002"
DEFAULT_ROOT="~/domains/shibajidebnath.com/public_html"
SITE="https://shibajidebnath.com"

# Optional config, gitignored: DEPLOY_SSH_KEY, DEPLOY_ROOT, DEPLOY_PORT.
ENV_FILE="$REPO/.deploy.env"
# shellcheck source=/dev/null
[ -f "$ENV_FILE" ] && . "$ENV_FILE"

SSH_USER="${DEPLOY_USER:-$DEFAULT_USER}"
SSH_HOST="${1:-${2:-$DEFAULT_HOST}}"
SSH_PORT="${3:-${DEPLOY_PORT:-$DEFAULT_PORT}}"
REMOTE_ROOT="${DEPLOY_ROOT:-$DEFAULT_ROOT}"
SSH_ARGS=(-p "$SSH_PORT" -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new)
[ -n "${DEPLOY_SSH_KEY:-}" ] && SSH_ARGS+=(-i "$DEPLOY_SSH_KEY")

say()  { printf '\n==> %s\n' "$1"; }
ok()   { printf '  ok    %s\n' "$1"; }
warn() { printf '  WARN  %s\n' "$1"; }
die()  { printf '  FAIL  %s\n' "$1" >&2; exit 1; }

# ── 1. Build ─────────────────────────────────────────────────────────────────
# verify:deploy runs inside npm run build and refuses to pass on a broken PHP
# tree, so uploading only ever happens after the local gate clears.
say "building (includes verify:deploy)"
( cd "$REPO" && npm run build ) || die "build or verify:deploy failed — nothing uploaded"

[ -d "$LOCAL" ] || die "dist/api missing after build"

# ── 2. Preflight ─────────────────────────────────────────────────────────────
say "checking reachability"
ssh "${SSH_ARGS[@]}" "$SSH_USER@$SSH_HOST" true 2>/dev/null \
  || die "SSH to $SSH_USER@$SSH_HOST:$SSH_PORT failed.
    If this times out, the host firewall blocks SSH and no upload will work.
    Check hPanel -> Security -> SSH Access first."

for f in env-loader.php enquiry.php send-mail.php tool-lead.php \
         tool-sequence-supabase.php csrf.php \
         payu/config.php payu/init.php payu/response.php; do
  [ -f "$LOCAL/$f" ] || die "expected dist/api/$f is absent — the tree is incomplete"
done
ok "all 9 endpoints present in dist/api"

# ── 3. Upload ────────────────────────────────────────────────────────────────
# One recursive copy. Nothing here is selected by hand.
say "uploading dist/api -> $REMOTE_ROOT/api"
ssh "${SSH_ARGS[@]}" "$SSH_USER@$SSH_HOST" "mkdir -p '$REMOTE_ROOT/api/payu'"
scp "${SSH_ARGS[@]}" -r "$LOCAL/." "$SSH_USER@$SSH_HOST:$REMOTE_ROOT/api/"
ok "tree copied"

say "setting permissions"
ssh "${SSH_ARGS[@]}" "$SSH_USER@$SSH_HOST" \
  "chmod 644 '$REMOTE_ROOT'/api/*.php '$REMOTE_ROOT'/api/payu/*.php 2>/dev/null; \
   chmod 755 '$REMOTE_ROOT'/api '$REMOTE_ROOT'/api/payu"
ok "644 on files, 755 on directories"

# ── 4. Verify on the server ──────────────────────────────────────────────────
# Checksums, because a truncated or emptied file is the failure mode that keeps
# repeating here. A mismatch names the file instead of surfacing as a bare 500.
say "verifying checksums on the server"
LOCAL_SUMS="$(cd "$LOCAL" && find . -name '*.php' | sort | xargs shasum -a 256)"
REMOTE_SUMS="$(ssh "${SSH_ARGS[@]}" "$SSH_USER@$SSH_HOST" \
  "cd '$REMOTE_ROOT/api' && find . -name '*.php' | sort | xargs sha256sum")"

drift=0
while IFS= read -r line; do
  sum="${line%% *}"; rel="${line#* }"
  if grep -qF "$sum" <<<"$REMOTE_SUMS"; then
    ok "${rel#./}"
  else
    warn "${rel#./} differs — scanner may have altered it"
    drift=$((drift + 1))
  fi
done <<<"$LOCAL_SUMS"

[ "$drift" -eq 0 ] || warn "$drift file(s) drifted — inspect before trusting production"

say "linting on the server (PHP 8.4)"
ssh "${SSH_ARGS[@]}" "$SSH_USER@$SSH_HOST" \
  "cd '$REMOTE_ROOT' && for f in api/*.php api/payu/*.php; do php -l \"\$f\" | grep -v 'No syntax errors' || true; done"

# ── 5. Probe production ──────────────────────────────────────────────────────
say "probing production"
failed=0
for p in api/env-loader.php api/csrf.php api/enquiry.php api/send-mail.php \
         api/payu/config.php api/payu/init.php api/payu/response.php; do
  code="$(curl -s -o /dev/null -w '%{http_code}' "$SITE/$p")"
  case "$code" in
    200|302|404|405) ok "$code  $p" ;;   # 404 = loader's direct-access guard working
    *) warn "$code  $p"; failed=$((failed + 1)) ;;
  esac
done

# The decisive check: config.php must answer with JSON naming whatever is still
# missing. A blank body means a stale file, which is the bug this script exists
# to prevent.
say "gateway diagnosis"
body="$(curl -s "$SITE/api/payu/config.php")"
if [ -n "$body" ]; then
  printf '  %s\n' "$body"
  if grep -q 'env_loader_missing' <<<"$body"; then
    warn "env_loader_missing — env-loader.php did not load; check the checksums above"
  elif grep -qE 'payu_config_(incomplete|missing)' <<<"$body"; then
    ok "loader works — now set the credentials it named, in hPanel -> PHP -> Configuration"
  fi
else
  warn "blank response — config.php is likely stale; re-run this script"
fi

if [ "$failed" -gt 0 ]; then
  warn "$failed endpoint(s) unexpected"
fi

say "reminders"
cat <<EOF
  PAYU_MODE must be PROD on $SITE. With TEST, checkouts go to test.payu.in:
  the payment page renders, no money moves, no enrollment is created.
  Rotate any credential ever pasted into a chat.
EOF