#!/bin/bash
# haxiam-install.sh
#
# Single unified idempotent installer for HAXiam. Replaces the four
# scripts/install/ubuntu{20,22,24,26}.sh + scripts/whateveryousayiam.sh
# pair (still kept as thin wrappers - see scripts/install/ubuntu20.04.sh
# and siblings). Also invoked from haxiam-jps/manifest.jps deployApp.
#
# Flag surface is locked at _contracts/installer_flag_surface.md.
# Schema for the Azure JSON this script writes is locked at
# _contracts/azure_json_schema.md. Eight phases:
#
#   1. Pre-flight             failed -> exit 1
#   2. System packages        failed -> exit 2
#   3. HAXiam bootstrap       failed -> exit 3  (absorbs whateveryousayiam.sh)
#   4. composer               failed -> exit 4  (no-op if composer.json absent)
#   5. Optional LE / cert     failed -> exit 6  (skip if --skip-le / unset)
#   6. Optional Azure config  failed -> exit 5  (skip if no Azure flags)
#   7. Config-change ledger   uses scripts/utilities/install-ledger.sh
#   8. Permissions hardening  scoped to ${HA_DIR}/users + ${HA_DIR}/users_sites (where IAM::liberate creates them)

set -e
set -o pipefail

# Default TERM so tput never aborts under set -e when invoked from CI,
# JPS, or any non-TTY context (review fix #7).
export TERM="${TERM:-dumb}"

# ---------------------------------------------------------------------------
# 0. Locate ourselves + colour helpers.
# ---------------------------------------------------------------------------
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "${DIR}"
cd ../../

txtbld=$(tput bold 2>/dev/null || true)             # Bold
bldgrn=${txtbld}$(tput setaf 2 2>/dev/null || true) # Green / success
bldred=${txtbld}$(tput setaf 1 2>/dev/null || true) # Red / warning
txtreset=$(tput sgr0 2>/dev/null || true)

install_green(){ echo "${bldgrn}$1${txtreset}"; }
install_red(){ echo "${bldred}$1${txtreset}"; }
install_bold(){ echo "${txtbld}$1${txtreset}"; }

LEDGER="${DIR}/../utilities/install-ledger.sh"
AZURE_CHECK="${DIR}/../utilities/check-azure-sso.sh"

# ---------------------------------------------------------------------------
# Flag defaults.
# ---------------------------------------------------------------------------
DISTRO="auto"             # auto | ubuntu-20.04 | ubuntu-22.04 | ubuntu-24.04 | ubuntu-26.04
DOMAIN=""                 # optional primary host
DO_LE="skip"              # skip | run
CERT_PATH=""              # --cert
KEY_PATH=""               # --key
AZ_TENANT=""
AZ_CLIENT=""
AZ_SECRET=""
AZ_SECRET_FROM_ENV="no"
AZ_REDIRECT_BASE=""
AZ_SCOPES="openid profile email"
HA_DIR="/var/www/iam"
NON_INTERACTIVE="no"
WROTE_ANY="no"            # for the final summary line

# ---------------------------------------------------------------------------
# 1. Help / usage.
# ---------------------------------------------------------------------------
usage() {
  cat <<USAGE
Usage: sudo bash scripts/install/haxiam-install.sh [flags]

  --distro <auto|ubuntu-20.04|ubuntu-22.04|ubuntu-24.04|ubuntu-26.04>
  --domain <example.org>
  --le | --skip-le        (default: --skip-le)
  --cert </path/fullchain.pem> --key </path/privkey.key>
                           (mutually exclusive with --le)
  --azure-tenant <guid> --azure-client <id> --azure-secret <secret>
                           (all three, or none)
  --azure-redirect-base <https://example.org>
                           (omit -> derived from Apache config / hostname)
  --azure-scopes "<scopes>"   (default: "openid profile email")
  --ha </var/www/iam>         (default: /var/www/iam)
  --non-interactive

See _contracts/installer_flag_surface.md for the locked behaviour.
USAGE
}

# ---------------------------------------------------------------------------
# 2. Argument parsing. Long flags only.
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
  arg="${1}"
  case "${arg}" in
    -h|--help) usage; exit 0 ;;
    --distro)        DISTRO="${2:-}"; shift 2 ;;
    --domain)        DOMAIN="${2:-}"; shift 2 ;;
    --le)            DO_LE="run"; shift ;;
    --skip-le)       DO_LE="skip"; shift ;;
    --cert)          CERT_PATH="${2:-}"; shift 2 ;;
    --key)           KEY_PATH="${2:-}"; shift 2 ;;
    --azure-tenant)  AZ_TENANT="${2:-}"; shift 2 ;;
    --azure-client)  AZ_CLIENT="${2:-}"; shift 2 ;;
    --azure-secret)  AZ_SECRET="${2:-}"; shift 2 ;;
    --azure-redirect-base) AZ_REDIRECT_BASE="${2:-}"; shift 2 ;;
    --azure-scopes)  AZ_SCOPES="${2:-}"; shift 2 ;;
    --ha)            HA_DIR="${2:-}"; shift 2 ;;
    --non-interactive) NON_INTERACTIVE="yes"; shift ;;
    *)
      install_red "Unknown flag: ${arg}"
      usage
      exit 1
      ;;
  esac
done

# ---------------------------------------------------------------------------
# 3. Mutually-exclusive validation + root.
# ---------------------------------------------------------------------------
if [[ -n "${CERT_PATH}" || -n "${KEY_PATH}" ]] && [[ "${DO_LE}" == "run" ]]; then
  install_red "--le is mutually exclusive with --cert/--key."
  exit 1
fi
if [[ -n "${CERT_PATH}" ]] && [[ -z "${KEY_PATH}" ]]; then
  install_red "--cert requires --key."; exit 1
fi
if [[ -n "${KEY_PATH}" ]] && [[ -z "${CERT_PATH}" ]]; then
  install_red "--key requires --cert."; exit 1
fi

# Azure triple-or-none. The secret can be provided via CLI (--azure-secret)
# or via the HAXIAM_AZURE_SECRET env var (review fix #5: keeps it out of
# /proc/<pid>/cmdline on shared hosts). If both are given, CLI wins.
if [[ -z "${AZ_SECRET}" ]] && [[ -n "${HAXIAM_AZURE_SECRET:-}" ]]; then
  AZ_SECRET="${HAXIAM_AZURE_SECRET}"
  AZ_SECRET_FROM_ENV="yes"
fi

AZ_FLAGS_SET=0
[[ -n "${AZ_TENANT}" ]] && AZ_FLAGS_SET=$((AZ_FLAGS_SET + 1))
[[ -n "${AZ_CLIENT}" ]] && AZ_FLAGS_SET=$((AZ_FLAGS_SET + 1))
[[ -n "${AZ_SECRET}" ]] && AZ_FLAGS_SET=$((AZ_FLAGS_SET + 1))
if [[ ${AZ_FLAGS_SET} -gt 0 ]] && [[ ${AZ_FLAGS_SET} -ne 3 ]]; then
  install_red "Azure flags must be all three (--azure-tenant, --azure-client, --azure-secret) or none."
  exit 1
fi

if [[ $EUID -ne 0 ]]; then
  install_red "Please run as root (sudo bash $(basename "${BASH_SOURCE[0]}") ...)."
  exit 1
fi

# The install root must already contain the HAXiam source (the deploy
# script or operator clones the repo into it before running the installer).
# Don't mkdir an empty dir — that would leave .version/composer.json/boilerplate
# unreadable and produce a broken install (review fix #5).
if [[ ! -d "${HA_DIR}" ]]; then
  install_red "--ha directory ${HA_DIR} does not exist. Clone the HAXiam source there first, then run the installer."
  exit 1
fi
cd "${HA_DIR}"

# ---------------------------------------------------------------------------
# PHASE 1 - pre-flight. OS match, disk space, network probe.
# ---------------------------------------------------------------------------
install_bold "[1/8] Pre-flight"
resolve_distro() {
  if [[ "${DISTRO}" != "auto" ]]; then
    case "${DISTRO}" in
      ubuntu-20.04|ubuntu-22.04|ubuntu-24.04|ubuntu-26.04) echo "${DISTRO}"; return 0 ;;
      *) install_red "Unsupported --distro ${DISTRO}. Supported: ubuntu-20.04, 22.04, 24.04, 26.04."; return 1 ;;
    esac
  fi
  if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    case "${VERSION_ID:-}" in
      20.04) echo "ubuntu-20.04" ;;
      22.04) echo "ubuntu-22.04" ;;
      24.04) echo "ubuntu-24.04" ;;
      26.04) echo "ubuntu-26.04" ;;
      *)
        install_red "Unsupported /etc/os-release VERSION_ID=${VERSION_ID:-?}. Supported: ubuntu 20.04/22.04/24.04/26.04."
        return 1 ;;
    esac
  else
    install_red "Cannot read /etc/os-release and --distro was not given (try --distro ubuntu-24.04 etc.)."
    return 1
  fi
}
RESOLVED_DISTRO="$(resolve_distro)" || exit 1
install_green "Distro: ${RESOLVED_DISTRO}"

case "${RESOLVED_DISTRO}" in
  # Ubuntu 20.04's default repos only have PHP 7.4, but composer.json
  # requires ^8.1. We install php8.1 from the ondrej/php PPA (added in
  # phase 2 before apt-get install).
  ubuntu-20.04) PHP_FPM="php8.1-fpm"; PHP_PKGS="php8.1-fpm php8.1-zip php8.1-gd php8.1-dom php8.1-mbstring php8.1-yaml" ;;
  ubuntu-22.04) PHP_FPM="php8.1-fpm"; PHP_PKGS="php8.1-fpm php8.1-zip php8.1-gd php8.1-dom php8.1-mbstring php8.1-yaml" ;;
  ubuntu-24.04) PHP_FPM="php8.3-fpm"; PHP_PKGS="php8.3-fpm php8.3-zip php8.3-gd php8.3-dom php8.3-mbstring php8.3-yaml" ;;
  ubuntu-26.04) PHP_FPM="php8.5-fpm"; PHP_PKGS="php8.5-fpm php8.5-zip php8.5-gd php8.5-dom php8.5-mbstring php8.5-yaml" ;;
esac

# Disk space - need at least 2 GB free for HAXcms-core + HAXiam + vendor.
AVAIL_MB="$(df -Pm "${HA_DIR}" 2>/dev/null | awk 'NR==2 {print $4}')"
if [[ -z "${AVAIL_MB}" || ${AVAIL_MB:-0} -lt 2048 ]]; then
  install_red "Pre-flight failed: only ${AVAIL_MB:-?} MB free; HAXiam needs >= 2048 MB."
  exit 1
fi
install_green "Disk: ${AVAIL_MB} MB free"

# ---------------------------------------------------------------------------
# Load existing config if present (invariant #2) - assumes cwd is install root.
# ---------------------------------------------------------------------------
HAX_DIR="${HA_DIR}"
HAXCMS_DIR="${HA_DIR}/cores/HAXcms-1.x.x"
HAXCMS_CORE="HAXcms-1.x.x"
WWW_USER="www-data"
WEB_GROUP="www-data"
CONFIG_FILE="${HA_DIR}/_iamConfig/config.cfg"

if [[ -f "${CONFIG_FILE}" ]]; then
  # Source only the haxiam= line check first to decide if this is "existing".
  if grep -Eq '^[[:space:]]*haxiam[[:space:]]*=' "${CONFIG_FILE}" \
    && [[ -n "$(sed -n 's/^[[:space:]]*haxiam[[:space:]]*=\(.*\)/\1/p' "${CONFIG_FILE}" | head -n1)" ]]; then
    install_green "Existing install detected - sourcing ${CONFIG_FILE}."
    # shellcheck disable=SC1090
    source "${CONFIG_FILE}"
    HAX_DIR="${haxiam:-${HAX_DIR}}"
    HAXCMS_DIR="${haxcms:-${HAXCMS_DIR}}"
    HAXCMS_CORE="${haxcmscore:-${HAXCMS_CORE}}"
    WWW_USER="${wwwuser:-${WWW_USER}}"
    WEB_GROUP="${webgroup:-${WEB_GROUP}}"
  fi
fi

mkdir -p "${HA_DIR}/_iamConfig"
if [[ ! -f "${CONFIG_FILE}" ]]; then
  # First-time write of config.cfg (no existing haxiam= line yet).
  bash "${LEDGER}" ran "phase3:writing ${CONFIG_FILE}"
  cat > "${CONFIG_FILE}" <<CFG
haxiam='${HA_DIR}'
haxcms='${HA_DIR}/cores/${HAXCMS_CORE}'
haxcmscore='${HAXCMS_CORE}'
wwwuser='${WWW_USER}'
webgroup='${WEB_GROUP}'
CFG
  WROTE_ANY="yes"
fi
# Re-source for this run.
# shellcheck disable=SC1090
source "${CONFIG_FILE}"

# ---------------------------------------------------------------------------
# PHASE 2 - system packages.
# ---------------------------------------------------------------------------
install_bold "[2/8] System packages"
PKG_NEEDED=0
for p in ${PHP_PKGS} apache2 git brotli certbot python3-certbot-apache ${PHP_FPM}; do
  if ! dpkg-query -W -f='${Status}' "${p}" 2>/dev/null | grep -q "install ok installed"; then
    PKG_NEEDED=1
    break
  fi
done

if [[ ${PKG_NEEDED} -eq 1 ]]; then
  # Ubuntu 20.04 needs the ondrej/php PPA for php8.1-* packages.
  if [[ "${RESOLVED_DISTRO}" == "ubuntu-20.04" ]]; then
    apt-get install -y software-properties-common || true
    add-apt-repository -y ppa:ondrej/php || true
  fi
  apt-get update \
    || { install_red "apt-get update failed."; exit 2; }
  apt-get install -y ${PHP_PKGS} apache2 git brotli certbot python3-certbot-apache ${PHP_FPM} \
    || { install_red "apt-get install failed"; exit 2; }
  install_green "apt-get install ok."
else
  install_green "Already present - skipping apt-get."
fi

# Apache modules - enabled on every distro, idempotent.
a2enmod proxy_fcgi ssl rewrite headers brotli http2 >/dev/null 2>&1 || true
a2dismod mpm_prefork >/dev/null 2>&1 || true
a2enmod mpm_event >/dev/null 2>&1 || true
a2enconf ${PHP_FPM} >/dev/null 2>&1 || true
if [[ ! -e /etc/apache2/conf-available/http2.conf ]]; then
  echo "Protocols h2 http/1.1" > /etc/apache2/conf-available/http2.conf
fi
a2enconf http2 >/dev/null 2>&1 || true

# Create an Apache vhost with DocumentRoot pointing at the install root
# so the site is actually reachable after install. Without this, Apache
# continues serving /var/www/html (the default site) and a successful
# installer does not make HAXiam reachable (review fix #9).
VHOST_CONF="/etc/apache2/sites-available/haxiam.conf"
cat > "${VHOST_CONF}" <<VHOST
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot ${HA_DIR}
    <Directory ${HA_DIR}/>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/${PHP_FPM}.sock|fcgi://localhost"
    </FilesMatch>
    Protocols h2 http/1.1
</VirtualHost>
VHOST
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite haxiam >/dev/null 2>&1 || true
# Reload Apache so the new vhost takes effect immediately on a running
# host (review fix #14 — otherwise the site isn't reachable until a
# manual restart). Use graceful to avoid dropping in-flight requests.
apache2ctl graceful 2>/dev/null || service apache2 reload 2>/dev/null || true

# ---------------------------------------------------------------------------
# PHASE 3 - HAXiam bootstrap (absorbs whateveryousayiam.sh).
# Idempotency: invariant #1 + #2.
# ---------------------------------------------------------------------------
install_bold "[3/8] HAXiam bootstrap"

mkdir -p "${HA_DIR}/_iamConfig/tmp" "${HA_DIR}/_iamConfig/assets" "${HA_DIR}/_iamConfig/skeletons" "${HA_DIR}/_iamConfig/snapshots"
for d in "${HA_DIR}/_iamConfig/tmp" "${HA_DIR}/_iamConfig/assets" "${HA_DIR}/_iamConfig/skeletons" "${HA_DIR}/_iamConfig/snapshots"; do
  if [[ ! -f "${HA_DIR}/_iamConfig/install_manifest.txt" ]]; then
    bash "${LEDGER}" added "${d}/"
  fi
done

# SYSTEM_VERSION.txt advance only if it equals the new code version.
SRC_VERSION=""
if [[ -f .version ]]; then SRC_VERSION=$(<.version); elif [[ -f VERSION.txt ]]; then SRC_VERSION=$(<VERSION.txt); fi
SYS_VERSION_FILE="${HA_DIR}/_iamConfig/SYSTEM_VERSION.txt"
if [[ -f "${SYS_VERSION_FILE}" ]]; then
  cur="$(<"${SYS_VERSION_FILE}")"
  if [[ "${cur}" != "${SRC_VERSION}" ]]; then
    bash "${LEDGER}" backup "${SYS_VERSION_FILE}" >> /dev/null
    cp -p "${SYS_VERSION_FILE}" "${SYS_VERSION_FILE}.prev"
  fi
fi
if [[ ! -f "${SYS_VERSION_FILE}" ]] || [[ "$(<"${SYS_VERSION_FILE}")" != "${SRC_VERSION}" ]]; then
  cp .version "${SYS_VERSION_FILE}" 2>/dev/null || cp VERSION.txt "${SYS_VERSION_FILE}"
  WROTE_ANY="yes"
  bash "${LEDGER}" wrote "${SYS_VERSION_FILE}" >> /dev/null
fi

# cores/HAXcms-1.x.x - clone if missing AND not on an existing install.
if [[ ! -d "${HAXCMS_DIR}" ]]; then
  install_green "Cloning ${HAXCMS_CORE}..."
  mkdir -p cores
  cd cores
  git clone https://github.com/haxtheweb/haxcms-php.git "${HAXCMS_CORE}" || {
    install_red "git clone of ${HAXCMS_CORE} failed."
    exit 3
  }
  cd "${HAXCMS_CORE}"
else
  install_green "${HAXCMS_DIR} already present - skipping clone."
  cd "${HAXCMS_DIR}"
fi

# Run haxtheweb.sh only on a fresh checkout (no _config/IAM marker yet).
if [[ ! -f _config/IAM ]]; then
  user="$(uuidgen 2>/dev/null || cat /proc/sys/kernel/random/uuid)"
  pass="$(uuidgen 2>/dev/null || cat /proc/sys/kernel/random/uuid)"
  bash scripts/haxtheweb.sh "${user}" "${pass}"
  touch _config/IAM
  # The generated credentials are for first-login reference only; on a real
  # install the operator must rotate them via the IAM admin UI.
  # Never echo the password — installer output is captured in CI/JPS/Docker
  # logs (review fix #6).
  install_green "Initial admin user created (user=${user}). Retrieve or rotate the password via the IAM admin UI or haxiam.sh menu."
fi

# Boilerplate copies - all guarded; invariant #1.
copy_if_absent() {
  local src="$1" dst="$2"
  if [[ -f "${src}" ]] && [[ ! -f "${dst}" ]]; then
    bash "${LEDGER}" backup "${dst}" >> /dev/null
    cp "${src}" "${dst}"
    WROTE_ANY="yes"
    bash "${LEDGER}" wrote "${dst}" >> /dev/null
    install_green "Wrote ${dst}"
  fi
}
cd ../../
copy_if_absent "${HAXCMS_DIR}/_config/config.json"          "_iamConfig/config.json"
copy_if_absent "${HAXCMS_DIR}/system/boilerplate/systemsetup/userData.json" "_iamConfig/userData.json"
copy_if_absent "${HAXCMS_DIR}/_config/my-custom-elements.js" "_iamConfig/my-custom-elements.js"
copy_if_absent "${HAXCMS_DIR}/_config/.htaccess"            "_iamConfig/.htaccess"
copy_if_absent "${HAXCMS_DIR}/_config/SALT.txt"             "_iamConfig/SALT.txt"
# HAXiam-specific boilerplate (iamConfig.php, HAXcmsConfig.php, azure.json)
# lives in HAXiam's OWN system/boilerplate/systemsetup/, NOT in the cloned
# core. The core (haxcms-php) ships config.json/userData.json/SALT.txt under
# its _config/ + system/boilerplate/, but NOT these IAM integration files —
# copying them from ${HAXCMS_DIR} silently no-ops (copy_if_absent's -f guard
# is false) and leaves _iamConfig/iamConfig.php missing, which 500s the site
# (Undefined constant IAM_PROTOCOL). Source from the install root instead.
copy_if_absent "${HA_DIR}/system/boilerplate/systemsetup/HAXcmsConfig.php" "_iamConfig/HAXcmsConfig.php"
copy_if_absent "${HA_DIR}/system/boilerplate/systemsetup/iamConfig.php"    "_iamConfig/iamConfig.php"

if [[ ! -f "_iamConfig/azure.json" ]]; then
  # Use the boilerplate template if azure-oidc has shipped it; otherwise
  # write the locked canonical schema inline so the install is always
  # complete (invariant #4).
  if [[ -f "${HA_DIR}/system/boilerplate/systemsetup/azure.json" ]]; then
    bash "${LEDGER}" backup "_iamConfig/azure.json" >> /dev/null
    cp "${HA_DIR}/system/boilerplate/systemsetup/azure.json" "_iamConfig/azure.json"
  else
    bash "${LEDGER}" backup "_iamConfig/azure.json" >> /dev/null
    cat > "_iamConfig/azure.json" <<AZJSON
{
    "enabled": false,
    "tenantId": "",
    "clientId": "",
    "clientSecret": "",
    "redirectUri": "",
    "issuer": "",
    "scopes": "openid profile email",
    "providerClass": "AzureOIDC"
}
AZJSON
  fi
  chmod 0600 "_iamConfig/azure.json"
  chown "${WWW_USER}" "_iamConfig/azure.json" 2>/dev/null || true
  WROTE_ANY="yes"
  bash "${LEDGER}" wrote "_iamConfig/azure.json" >> /dev/null
  install_green "Wrote _iamConfig/azure.json (enabled=false)"
fi

# ---------------------------------------------------------------------------
# PHASE 4 - composer. Only runs if composer.json is present in the install
# root (azure-oidc owns composer.json; this phase is no-op otherwise).
# ---------------------------------------------------------------------------
install_bold "[4/8] composer"
if [[ -f composer.json ]]; then
  if [[ ! -d vendor ]] || [[ composer.json -nt vendor ]]; then
    if ! command -v composer >/dev/null 2>&1; then
      apt-get install -y composer || { install_red "composer not installed and apt install failed."; exit 4; }
    fi
    composer install --no-dev --no-interaction --no-progress || {
      install_red "composer install failed."; exit 4;
    }
    install_green "composer install ok."
    WROTE_ANY="yes"
    bash "${LEDGER}" wrote "vendor/" >> /dev/null
  else
    install_green "vendor/ already populated and newer than composer.json - skipping."
  fi
else
  install_green "No composer.json in this install - skipping composer phase."
fi

# ---------------------------------------------------------------------------
# PHASE 5 - optional LE / cert.
# Exit code 6 on cert/LE failure per invariant #3.
# ---------------------------------------------------------------------------
install_bold "[5/8] TLS (LE / cert)"
if [[ "${DO_LE}" == "run" ]]; then
  if [[ -z "${DOMAIN}" ]]; then
    install_red "--le requires --domain."
    exit 6
  fi
  # Certbot requires either --email or --register-unsafely-without-email
  # for non-interactive mode (review fix #6). We default to no-email since
  # the installer doesn't collect one; operators who want renewal notices
  # can re-run with --email via the standalone certbot CLI.
  certbot --apache --non-interactive --agree-tos --register-unsafely-without-email -d "${DOMAIN}" \
    || { install_red "certbot failed for ${DOMAIN}."; exit 6; }
  # Auto-renew cron (certbot installs a timer on systemd; on cron-only
  # systems add a daily entry). Idempotent.
  if ! grep -q "certbot renew" /etc/cron.d/certbot 2>/dev/null; then
    echo "0 3 * * * root certbot renew --quiet" > /etc/cron.d/certbot
  fi
  install_green "Let's Encrypt cert installed for ${DOMAIN}."
  WROTE_ANY="yes"
elif [[ -n "${CERT_PATH}" && -n "${KEY_PATH}" ]]; then
  if [[ ! -r "${CERT_PATH}" ]]; then
    install_red "--cert path ${CERT_PATH} unreadable."; exit 6
  fi
  if [[ ! -r "${KEY_PATH}" ]]; then
    install_red "--key path ${KEY_PATH} unreadable."; exit 6
  fi
  # Harden cert/key permissions before wiring into Apache. A world-readable
  # key is a security risk; an inaccessible key makes Apache fail to start.
  # Enforce 0644 for cert and 0640 for key (review fix #5).
  chmod 0644 "${CERT_PATH}" 2>/dev/null || true
  chmod 0640 "${KEY_PATH}" 2>/dev/null || true
  # Wire the cert/key into an Apache SSL vhost so the web server
  # actually uses them (review fix #8 — previously validated but never
  # wired in). The vhost uses the cert/key paths verbatim.
  VHOST_CONF="/etc/apache2/sites-available/haxiam-ssl.conf"
  cat > "${VHOST_CONF}" <<SSLVHOST
<VirtualHost *:443>
    ServerAdmin webmaster@localhost
    DocumentRoot ${HA_DIR}
    SSLEngine on
    SSLCertificateFile ${CERT_PATH}
    SSLCertificateKeyFile ${KEY_PATH}
    <Directory ${HA_DIR}/>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/${PHP_FPM}.sock|fcgi://localhost"
    </FilesMatch>
    Protocols h2 http/1.1
</VirtualHost>
SSLVHOST
    a2enmod ssl >/dev/null 2>&1 || true
    a2ensite haxiam-ssl >/dev/null 2>&1 || true
    # Reload Apache so the SSL vhost takes effect immediately (review
    # previously-missed #1 — the earlier graceful ran before this vhost).
    apache2ctl graceful 2>/dev/null || service apache2 reload 2>/dev/null || true
    install_green "SSL vhost configured with ${CERT_PATH} / ${KEY_PATH}."
    WROTE_ANY="yes"
else
  install_green "Skipping TLS phase (--skip-le / no cert)."
fi

# ---------------------------------------------------------------------------
# PHASE 6 - optional Azure. Writes _iamConfig/azure.json per locked schema,
# runs check-azure-sso.sh. Exit code 5 on check failure.
# ---------------------------------------------------------------------------
install_bold "[6/8] Azure OIDC"
AZURE_WAS_CONFIGURED="no"
if [[ ${AZ_FLAGS_SET} -eq 3 ]]; then
  # Compute redirectUri deterministically; invariant #5.
  if [[ -z "${AZ_REDIRECT_BASE}" ]]; then
    guess_host="$(hostname -f 2>/dev/null || hostname)"
    AZ_REDIRECT_BASE="https://${guess_host}"
    install_red "Azure: --azure-redirect-base not set; deriving from hostname -> ${AZ_REDIRECT_BASE} (this is a guess; the operator should override)."
  fi
  # Drop trailing slash so the contract format "${base}/oauth-callback.php"
  # never produces "//oauth-callback.php".
  AZ_REDIRECT_BASE="${AZ_REDIRECT_BASE%/}"
  REDIRECT_URI="${AZ_REDIRECT_BASE}/oauth-callback.php"

  AZ_BOILER="${HAXCMS_DIR}/system/boilerplate/systemsetup/azure.json"
  TMP_AZ_JSON="$(mktemp)"
  bash "${LEDGER}" backup "_iamConfig/azure.json" >> /dev/null
  if [[ -f "${AZ_BOILER}" ]]; then
    cp "${AZ_BOILER}" "${TMP_AZ_JSON}"
  else
    cat > "${TMP_AZ_JSON}" <<AZJSON
{
    "enabled": false,
    "tenantId": "",
    "clientId": "",
    "clientSecret": "",
    "redirectUri": "",
    "issuer": "",
    "scopes": "openid profile email",
    "providerClass": "AzureOIDC"
}
AZJSON
  fi
  # Stamp the user's values. Uses python3 for portable JSON edit on the
  # distro versions we support (Ubuntu 20.04 onward). Falls back to a
  # perl one-liner if python3 is somehow absent.
  if command -v python3 >/dev/null 2>&1; then
    AZ_TENANT_VAL="${AZ_TENANT}" AZ_CLIENT_VAL="${AZ_CLIENT}" AZ_SECRET_VAL="${AZ_SECRET}" \
      REDIRECT_VAL="${REDIRECT_URI}" ISSUER_VAL="https://login.microsoftonline.com/${AZ_TENANT}/v2.0" \
      SCOPES_VAL="${AZ_SCOPES}" JSON_FILE="${TMP_AZ_JSON}" python3 - <<'PY'
import json, os, sys
p = os.environ.get("JSON_FILE")
data = {
    "enabled": True,
    "tenantId": os.environ["AZ_TENANT_VAL"],
    "clientId": os.environ["AZ_CLIENT_VAL"],
    "clientSecret": os.environ["AZ_SECRET_VAL"],
    "redirectUri": os.environ["REDIRECT_VAL"],
    "issuer": os.environ["ISSUER_VAL"],
    "scopes": os.environ["SCOPES_VAL"],
    "providerClass": "AzureOIDC",
}
with open(p, "w") as f:
    json.dump(data, f, indent=4, sort_keys=False)
    f.write("\n")
PY
  else
    # perl fallback - undocumented in the contract but better than failing.
    perl -e 'use JSON::PP; my $j = { enabled=>JSON::PP::true, tenantId=>$ENV{AZ_TENANT_VAL}, clientId=>$ENV{AZ_CLIENT_VAL}, clientSecret=>$ENV{AZ_SECRET_VAL}, redirectUri=>$ENV{REDIRECT_VAL}, issuer=>$ENV{ISSUER_VAL}, scopes=>$ENV{SCOPES_VAL}, providerClass=>"AzureOIDC" }; open my $fh, ">", $ENV{JSON_FILE} or die $!; print $fh JSON::PP::encode_json($j); close $fh;' \
      JSON_FILE="${TMP_AZ_JSON}" \
      AZ_TENANT_VAL="${AZ_TENANT}" AZ_CLIENT_VAL="${AZ_CLIENT}" AZ_SECRET_VAL="${AZ_SECRET}" \
      REDIRECT_VAL="${REDIRECT_URI}" ISSUER_VAL="https://login.microsoftonline.com/${AZ_TENANT}/v2.0" \
      SCOPES_VAL="${AZ_SCOPES}" || { rm -f "${TMP_AZ_JSON}"; install_red "Neither python3 nor perl available to write azure.json."; exit 5; }
  fi
  # Idempotency: if azure.json already exists with enabled=true, preserve
  # the operator's existing config instead of overwriting it (review fix #7).
  # Only write if the file doesn't exist or is still the disabled template.
  if [[ -f "_iamConfig/azure.json" ]] && grep -q '"enabled"[[:space:]]*:[[:space:]]*true' "_iamConfig/azure.json" 2>/dev/null; then
    install_green "_iamConfig/azure.json already configured (enabled=true) — preserving existing config."
  else
    mv "${TMP_AZ_JSON}" "_iamConfig/azure.json"
    WROTE_ANY="yes"
    bash "${LEDGER}" wrote "_iamConfig/azure.json" >> /dev/null
  fi
  # Always enforce 0600 + chown www-data, even when preserving an existing
  # file, so a manually-created config can't retain permissive permissions
  # (review fix #6).
  chmod 0600 "_iamConfig/azure.json"
  chown "${WWW_USER}" "_iamConfig/azure.json" 2>/dev/null || true
  AZURE_WAS_CONFIGURED="yes"
  rm -f "${TMP_AZ_JSON}"

  # Validate. Never echo the secret; report length only.
  echo "Azure: <set>  tenant=<set>  client=<set>  secret=<len-${#AZ_SECRET}>"
  bash "${AZURE_CHECK}" || { install_red "check-azure-sso.sh failed; see _iamConfig/azure.json and run it again to diagnose."; exit 5; }
  install_green "${REDIRECT_URI}"   # invariant #5 - admin copies this into Azure.
  install_green "Azure config ok."
else
  echo "Azure: <unset>"
fi

# ---------------------------------------------------------------------------
# PHASE 7 - config-change ledger (already called from each write above).
# Wrap up by recording that we ran.
# ---------------------------------------------------------------------------
install_bold "[7/8] Config-change ledger"
bash "${LEDGER}" ran "install-root=${HAX_DIR} distro=${RESOLVED_DISTRO} azure=${AZURE_WAS_CONFIGURED}" >> /dev/null
install_green "Ledger updated at _iamConfig/install_manifest.txt."

# ---------------------------------------------------------------------------
# PHASE 8 - permissions hardening. Replaces the blanket chown -R pattern
# from the old harden-security.sh - invariant #8.
# ---------------------------------------------------------------------------
install_bold "[8/8] Permissions"
wwwuser="${wwwuser:-www-data}"
webgroup="${webgroup:-www-data}"
# IAM::liberate() creates accounts under ${HA_DIR}/users and
# ${HA_DIR}/users_sites (see system/lib/IAM.php), NOT under _iamConfig/.
# The previous code searched _iamConfig/users which never contains the
# live accounts, so the hardening was a no-op (review fix #7).
if [[ -d "${HA_DIR}/users" ]]; then
  find "${HA_DIR}/users" -maxdepth 1 -mindepth 1 -type d | while read -r d; do
    chown "${wwwuser}:${webgroup}" "$d"
    chmod 2755 "$d"
  done
fi
if [[ -d "${HA_DIR}/users_sites" ]]; then
  find "${HA_DIR}/users_sites" -maxdepth 1 -mindepth 1 -type d | while read -r d; do
    chown "${wwwuser}:${webgroup}" "$d"
    chmod 2755 "$d"
  done
fi
if [[ -d "${HA_DIR}/_iamConfig/cache" ]]; then
  chown "${wwwuser}:${webgroup}" "${HA_DIR}/_iamConfig/cache"
  chmod 2755 "${HA_DIR}/_iamConfig/cache"
fi
install_green "Permissions applied (scoped to ${HA_DIR}/users|users_sites|_iamConfig/cache only)."

# ---------------------------------------------------------------------------
# Final summary (invariant #6).
# ---------------------------------------------------------------------------
install_bold "Install complete."
echo "  Root: ${HAX_DIR}"
echo "  HAXcms: ${HAXCMS_DIR}"
echo "  Distro: ${RESOLVED_DISTRO}"
echo "  Azure: ${AZURE_WAS_CONFIGURED}"
if [[ "${WROTE_ANY}" == "yes" ]]; then
  echo "  Wrote at least one new file - see _iamConfig/install_manifest.txt for the full log."
else
  echo "  No new writes performed - idempotent skip."
fi
if [[ "${AZURE_WAS_CONFIGURED}" == "yes" ]]; then
  install_bold "Azure redirect URI (register this in your Azure app registration):"
  # Read the redirectUri from the actual file on disk, not the computed
  # value, in case we preserved an existing config (review previously-missed #2).
  ACTUAL_REDIRECT_URI="$(python3 -c 'import json; print(json.load(open("_iamConfig/azure.json")).get("redirectUri",""))' 2>/dev/null || echo "${REDIRECT_URI}")"
  install_green "${ACTUAL_REDIRECT_URI}"
fi
exit 0
