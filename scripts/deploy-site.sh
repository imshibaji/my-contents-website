#!/usr/bin/env bash
# Auto-deploy the built static site (dist/) to Hostinger.
#
#   npm run deploy:site
#
# Kept separate from deploy-php.sh because the site changes often and the API
# rarely. Deploying dist/ wholesale also carries public/api/** along with it, so
# run deploy-php.sh after any PHP change — this script does not lint or verify.

set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
LOCAL="$REPO/dist"

DEFAULT_USER="u802934877"
DEFAULT_HOST="217.21.91.8"
DEFAULT_PORT="65002"
DEFAULT_ROOT="$HOME/domains/shibajidebnath.com/public_html"

ENV_FILE="$REPO/.deploy.env"
# shellcheck source=/dev/null
[ -f "$ENV_FILE" ] && . "$ENV_FILE"

SSH_USER="${DEPLOY_USER:-$DEFAULT_USER}"
SSH_HOST="${1:-${2:-$DEFAULT_HOST}}"
SSH_PORT="${3:-${DEPLOY_PORT:-$DEFAULT_PORT}}"
REMOTE_ROOT="${DEPLOY_ROOT:-$DEFAULT_ROOT}"
SSH_ARGS=(-p "$SSH_PORT" -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new)
[ -n "${DEPLOY_SSH_KEY:-}" ] && SSH_ARGS+=(-i "$DEPLOY_SSH_KEY")

say() { printf '\n==> %s\n' "$1"; }
ok()  { printf '  ok    %s\n' "$1"; }
die() { printf '  FAIL  %s\n' "$1" >&2; exit 1; }

[ -f "$LOCAL/index.html" ] || die "dist/ not built — run: npm run build"

say "uploading dist/ -> $REMOTE_ROOT"
ssh "${SSH_ARGS[@]}" "$SSH_USER@$SSH_HOST" true 2>/dev/null \
  || die "SSH failed. Check hPanel -> Security -> SSH Access."

scp "${SSH_ARGS[@]}" -r "$LOCAL/." "$SSH_USER@$SSH_HOST:$REMOTE_ROOT/"
ok "tree copied"

# .htaccess must survive: without it, absent paths 404 instead of redirecting to
# /, and missing PHP files stop looking like a missing file.
say "checking critical files"
ssh "${SSH_ARGS[@]}" "$SSH_USER@$SSH_HOST" \
  "cd '$REMOTE_ROOT' && for f in .htaccess index.html api/env-loader.php api/payu/config.php; do
     [ -f \"\$f\" ] && echo \"OK      \$f\" || echo \"MISSING \$f\"; done"

ssh "${SSH_ARGS[@]}" "$SSH_USER@$SSH_HOST" \
  "chmod 644 '$REMOTE_ROOT'/.htaccess '$REMOTE_ROOT'/index.html 2>/dev/null"

say "probing production"
for p in / /courses/ /courses/checkout/ /pay/ /api/csrf.php; do
  code="$(curl -s -o /dev/null -w '%{http_code}' "https://shibajidebnath.com$p")"
  case "$code" in
    200|302) ok "$code  $p" ;;
    *)       printf '  WARN  %s  %s\n' "$code" "$p" ;;
  esac
done

say "done"
cat <<'EOF'
  This copies public/api/** too. After any PHP change run `npm run deploy`,
  which lints and checksum-verifies the API on the server.
EOF