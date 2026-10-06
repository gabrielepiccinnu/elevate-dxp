#!/usr/bin/env bash
# End-to-end check on a fresh OpenDXP application:
#   create skeleton -> require this bundle (path repository) -> install OpenDXP + bundles
#   -> container lint -> seed demo -> HTTP smoke tests against the PHP built-in server.
#
# Used by CI and runnable locally (e.g. inside the opendxp/opendxp docker image):
#   BUNDLE_DIR=/path/to/elevate-dxp APP_DIR=/tmp/opendxp-app \
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=opendxp DB_PASSWORD=opendxp DB_NAME=opendxp \
#   tests/Integration/install-and-smoke.sh
set -euo pipefail

BUNDLE_DIR="${BUNDLE_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
APP_DIR="${APP_DIR:-/tmp/opendxp-app}"
PORT="${PORT:-8000}"
export COMPOSER_MEMORY_LIMIT=-1
export OPENDXP_INSTALL_MYSQL_HOST_SOCKET="${DB_HOST:-127.0.0.1}"
export OPENDXP_INSTALL_MYSQL_PORT="${DB_PORT:-3306}"
export OPENDXP_INSTALL_MYSQL_USERNAME="${DB_USER:-opendxp}"
export OPENDXP_INSTALL_MYSQL_PASSWORD="${DB_PASSWORD:-opendxp}"
export OPENDXP_INSTALL_MYSQL_DATABASE="${DB_NAME:-opendxp}"
export OPENDXP_INSTALL_ADMIN_USERNAME=admin
export OPENDXP_INSTALL_ADMIN_PASSWORD=admin
export ELEVATE_DXP_MESSENGER_DSN='sync://'

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

step "Create OpenDXP skeleton in $APP_DIR"
rm -rf "$APP_DIR"
composer create-project --no-interaction --no-scripts open-dxp/skeleton "$APP_DIR"
cd "$APP_DIR"

step "Require elevate-dxp/elevate-bundle from $BUNDLE_DIR"
composer config repositories.elevate path "$BUNDLE_DIR"
composer config minimum-stability dev
composer config prefer-stable true
composer require --no-interaction --no-scripts -W 'elevate-dxp/elevate-bundle:*@dev' 'open-dxp/personalization-bundle:^1.0'

step "Register bundles"
php -r '
$f = "config/bundles.php"; $s = file_get_contents($f);
$add = "    OpenDxp\\Bundle\\NewsletterBundle\\OpenDxpNewsletterBundle::class => [\"all\" => true],\n"
     . "    OpenDxp\\Bundle\\PersonalizationBundle\\OpenDxpPersonalizationBundle::class => [\"all\" => true],\n"
     . "    ElevateDxp\\ElevateDxpBundle::class => [\"all\" => true],\n";
file_put_contents($f, preg_replace("/\\];\\s*$/", $add."];\n", $s));'
cat > config/packages/elevate_dxp_ci.yaml <<'YAML'
opendxp_personalization:
    targeting:
        enabled: true
framework:
    messenger:
        transports:
            opendxp_core: 'sync://'
            opendxp_maintenance: 'sync://'
            opendxp_scheduled_tasks: 'sync://'
            opendxp_image_optimize: 'sync://'
            opendxp_asset_update: 'sync://'
YAML

step "Install OpenDXP"
vendor/bin/opendxp-install --no-interaction
bin/console cache:clear -q
for b in OpenDxpNewsletterBundle OpenDxpPersonalizationBundle ElevateDxpBundle; do
  bin/console opendxp:bundle:install "$b" --no-interaction
done
bin/console assets:install public -q

step "Container, routes, configuration"
bin/console lint:container
bin/console config:dump-reference elevate_dxp > /dev/null
bin/console debug:router | grep -E 'elevate_dxp|edxp' > /dev/null
bin/console elevate-dxp:diagnostics
bin/console elevate-dxp:experiments:demo-seed

step "HTTP smoke tests"
php -S "127.0.0.1:$PORT" -t public public/index.php > /tmp/opendxp-server.log 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -sf -o /dev/null "http://127.0.0.1:$PORT/admin/login" && break; sleep 1; done
BASE_URL="http://127.0.0.1:$PORT" php "$BUNDLE_DIR/tests/Integration/smoke.php"
