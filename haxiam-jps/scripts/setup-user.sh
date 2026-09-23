#!/bin/bash
#
# haxiam-jps/scripts/setup-user.sh
#
# Called by the setupUser action in haxiam-jps/manifest.jps AFTER deployApp.
# Applies the hosting-provider-supplied admin credentials (HAX_ADMIN_USER /
# HAX_ADMIN_PASS) to the HAXcms core's _config/config.php, overriding the
# random uuidgen credentials the installer's haxtheweb.sh generated.
#
# Env vars (set by the JPS manifest):
#   HAX_HAXIAM_DIR  - the install root (captured from deployApp's stdout)
#   HAX_ADMIN_USER   - the admin username (from the JPS settings form)
#   HAX_ADMIN_PASS   - the admin password (from the JPS settings form)

set -eu

txtbld=$(tput bold 2>/dev/null || true)
bldgrn=${txtbld}$(tput setaf 2 2>/dev/null || true)
bldred=${txtbld}$(tput setaf 1 2>/dev/null || true)
txtreset=$(tput sgr0 2>/dev/null || true)
haxecho() { echo "${bldgrn}$1${txtreset}" >&2; }
haxwarn() { echo "${bldred}$1${txtreset}" >&2; }

IAM_DIR="${HAX_HAXIAM_DIR:-/var/www/iam}"
ADMIN_USER="${HAX_ADMIN_USER:-haxadmin}"
ADMIN_PASS="${HAX_ADMIN_PASS:-}"

if [ -z "${ADMIN_PASS}" ]; then
  haxwarn "setup-user: no admin password provided, skipping credential setup"
  exit 0
fi

# Source the HAXiam config to find the core path.
if [ -f "${IAM_DIR}/_iamConfig/config.cfg" ]; then
  # shellcheck disable=SC1090
  source "${IAM_DIR}/_iamConfig/config.cfg"
fi

HAXCMS_DIR="${haxcms:-${IAM_DIR}/cores/HAXcms-1.x.x}"
CONFIG_PHP="${HAXCMS_DIR}/_config/config.php"

if [ ! -f "${CONFIG_PHP}" ]; then
  haxwarn "setup-user: ${CONFIG_PHP} not found — install may not have completed"
  exit 1
fi

# Write a temp PHP script that safely updates the superUser credentials.
# Using a temp file avoids the escaping nightmare of php -r inside bash
# single quotes inside a heredoc — the $HAXCMS literal in the regex
# needs careful escaping through 3 layers (bash → PHP string → regex).
# With a file, PHP reads it directly with no shell interpretation.
SETUP_SCRIPT="$(mktemp)"
trap 'rm -f "${SETUP_SCRIPT}"' EXIT

cat > "${SETUP_SCRIPT}" <<'PHPEOF'
<?php
$f = getenv('HAXCMS_CONFIG');
$c = file_get_contents($f);
$user = getenv('HAX_ADMIN_USER_VAL');
$pass = getenv('HAX_ADMIN_PASS_VAL');
// Match the literal $HAXCMS->superUser->name = '...'; line.
// Double-quoted PHP string so \x27 (single quote) works.
// \\$ in PHP double-quoted = \$ in the regex = literal $ (review fix #2).
$c = preg_replace_callback(
    "/(\\\$HAXCMS->superUser->name = )\x27[^\x27]*\x27;/",
    function($m) use ($user) { return $m[1] . var_export($user, true) . ';'; },
    $c
);
$c = preg_replace_callback(
    "/(\\\$HAXCMS->superUser->password = )\x27[^\x27]*\x27;/",
    function($m) use ($pass) { return $m[1] . var_export($pass, true) . ';'; },
    $c
);
file_put_contents($f, $c);
PHPEOF

HAXCMS_CONFIG="${CONFIG_PHP}" \
HAX_ADMIN_USER_VAL="${ADMIN_USER}" \
HAX_ADMIN_PASS_VAL="${ADMIN_PASS}" \
php "${SETUP_SCRIPT}"

haxecho "setup-user: admin credentials applied (user=${ADMIN_USER})"
