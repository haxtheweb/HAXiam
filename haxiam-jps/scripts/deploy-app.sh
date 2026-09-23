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

# 3. Installer flag vector.
INSTALLER="${WEBROOT}/scripts/install/haxiam-install.sh"
if [ ! -f "${INSTALLER}" ]; then
  haxwarn "expected installer at ${INSTALLER}; branch ${BRANCH} may not have the unified installer yet"
  exit 3
fi

INSTALL_FLAGS="--distro auto --skip-le --non-interactive"

if [ "${AZURE_CONFIGURED:-false}" = "true" ]; then
  : "${AZ_TENANT:?deploy-app: AZURE_CONFIGURED=true but AZ_TENANT is unset}"
  : "${AZ_CLIENT:?deploy-app: AZURE_CONFIGURED=true but AZ_CLIENT is unset}"
  : "${AZ_SECRET:?deploy-app: AZURE_CONFIGURED=true but AZ_SECRET is unset}"
  INSTALL_FLAGS="${INSTALL_FLAGS} --azure-tenant ${AZ_TENANT}"
  INSTALL_FLAGS="${INSTALL_FLAGS} --azure-client ${AZ_CLIENT}"
  INSTALL_FLAGS="${INSTALL_FLAGS} --azure-secret ${AZ_SECRET}"
  if [ -n "${AZ_DOMAIN:-}" ]; then
    INSTALL_FLAGS="${INSTALL_FLAGS} --azure-redirect-base https://${AZ_DOMAIN}"
  fi
fi

if [ -n "${LOCAL_STORAGE_LIMIT_GB:-}" ]; then
  INSTALL_FLAGS="${INSTALL_FLAGS} --storage-limit-gb ${LOCAL_STORAGE_LIMIT_GB}"
fi

# 4. Run the installer.
haxecho "deploy-app: running unified installer (Azure client secret redacted)"
sudo bash "${INSTALLER}" ${INSTALL_FLAGS}

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
