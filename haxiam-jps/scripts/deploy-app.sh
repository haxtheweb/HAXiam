#!/bin/bash
#
# haxiam-jps/scripts/deploy-app.sh
#
# Called by the deployApp action in haxiam-jps/manifest.jps.
#
# Responsibilities:
#   1. Determine the cp webroot (Jelastic $gh, then Reclaim Cloud default,
#      then the legacy /var/www/iam fallback).
#   2. Clone HAXiam from GitHub if .git is absent; leave an existing
#      working tree alone so re-runs don't wipe an operator's edits.
#   3. Compose the unified-installer's flag vector from JPS settings +
#      this machine's environment (${HAX_HAXIAM_BRANCH}, ${gh}, ...).
#   4. Run the unified installer:
#         --distro auto --skip-le --non-interactive
#         [+ --azure-tenant / --azure-client / --azure-secret / --azure-redirect-base]
#   5. Skip composer install if the installer already produced vendor/;
#      if not, install it as a fallback.
#
# Owns no state — all configuration flows in as env vars from the JPS action.

set -eu

txtbld=$(tput bold 2>/dev/null || true)
bldgrn=${txtbld}$(tput setaf 2 2>/dev/null || true)
bldred=${txtbld}$(tput setaf 1 2>/dev/null || true)
txtreset=$(tput sgr0 2>/dev/null || true)
haxecho() { echo "${bldgrn}$1${txtreset}" >&2; }
haxwarn() { echo "${bldred}$1${txtreset}" >&2; }

# 1. Webroot discovery.
WEBROOT=""
if   [ -n "${gh:-}" ]                              ; then WEBROOT="${gh}"
elif [ -d /home/jelastic/webapp/ROOT/web ]         ; then WEBROOT=/home/jelastic/webapp/ROOT/web
else                                                   WEBROOT=/var/www/iam
fi
haxecho "deploy-app: webroot = ${WEBROOT}"
mkdir -p "${WEBROOT}"

# 2. Clone or skip.
BRANCH="${HAX_HAXIAM_BRANCH:-plan/3070-haxiam-jps}"
REPO="${HAX_HAXIAM_REPO:-https://github.com/haxtheweb/HAXiam.git}"

if [ ! -d "${WEBROOT}/.git" ]; then
  haxecho "deploy-app: cloning HAXiam (${BRANCH}) into ${WEBROOT}"
  cd "$(dirname "${WEBROOT}")"
  git clone --branch "${BRANCH}" --depth 1 "${REPO}" "$(basename "${WEBROOT}")"
  cd "${WEBROOT}"
else
  haxecho "deploy-app: ${WEBROOT} already has a git working tree, leaving it"
  cd "${WEBROOT}"
fi

# 3. Installer flag vector — built as an argv array so values with
# whitespace / glob chars pass through verbatim (no word splitting).
INSTALLER="${WEBROOT}/scripts/install/haxiam-install.sh"
if [ ! -f "${INSTALLER}" ]; then
  haxwarn "expected installer at ${INSTALLER}; branch ${BRANCH} may not have the unified installer yet"
  exit 3
fi

INSTALL_ARGS=(--distro auto --skip-le --non-interactive --ha "${WEBROOT}")

if [ "${AZURE_CONFIGURED:-false}" = "true" ]; then
  : "${AZ_TENANT:?deploy-app: AZURE_CONFIGURED=true but AZ_TENANT is unset}"
  : "${AZ_CLIENT:?deploy-app: AZURE_CONFIGURED=true but AZ_CLIENT is unset}"
  : "${AZ_SECRET:?deploy-app: AZURE_CONFIGURED=true but AZ_SECRET is unset}"
  INSTALL_ARGS+=(--azure-tenant "${AZ_TENANT}")
  INSTALL_ARGS+=(--azure-client "${AZ_CLIENT}")
  # Pass the secret via the HAXIAM_AZURE_SECRET env var instead of as a
  # CLI arg so it does NOT appear in /proc/<pid>/cmdline (review fix #5).
  export HAXIAM_AZURE_SECRET="${AZ_SECRET}"
  if [ -n "${AZ_DOMAIN:-}" ]; then
    INSTALL_ARGS+=(--azure-redirect-base "https://${AZ_DOMAIN}")
  fi
fi

# 4. Run the installer. Log a REDACTED command (never echo the secret —
# the installer contract requires the secret is never written to logs).
REDACTED_DISPLAY="--distro auto --skip-le --non-interactive --ha ${WEBROOT}"
if [ "${AZURE_CONFIGURED:-false}" = "true" ]; then
  REDACTED_DISPLAY="${REDACTED_DISPLAY} --azure-tenant ${AZ_TENANT} --azure-client ${AZ_CLIENT} --azure-secret <redacted>"
  if [ -n "${AZ_DOMAIN:-}" ]; then
    REDACTED_DISPLAY="${REDACTED_DISPLAY} --azure-redirect-base https://${AZ_DOMAIN}"
  fi
fi
haxecho "deploy-app: bash ${INSTALLER} ${REDACTED_DISPLAY}"
# Use sudo -E so HAXIAM_AZURE_SECRET survives sudo's env filtering
# (review fix #13). Redirect installer stdout to stderr so only the
# final echo "${WEBROOT}" appears on stdout for JPS ${response} capture
# (review fix #4 — installer's install_green/install_red echo to stdout).
sudo -E bash "${INSTALLER}" "${INSTALL_ARGS[@]}" >&2

# 5. Composer fallback if the installer didn't already vendor in.
if [ ! -f "${WEBROOT}/vendor/autoload.php" ]; then
  if command -v composer >/dev/null 2>&1; then
    haxecho "deploy-app: running composer install --no-dev --optimize-autoloader"
    composer install --no-dev --optimize-autoloader
  else
    haxwarn "deploy-app: composer not on PATH — installer is expected to have added it"
  fi
fi

haxecho "deploy-app: finished"

# Echo the discovered webroot to stdout so the JPS manifest can capture it
# via setEnv and pass it to subsequent actions (configureAzure, setupUser).
echo "${WEBROOT}"
