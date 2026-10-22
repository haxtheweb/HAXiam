#!/bin/bash
# docker/entrypoint.sh - entrypoint for the HAXiam production image.
#
# Commands:
#   serve (default)   configure from environment, run the installer
#                     (idempotent), then run php-fpm + Apache in the foreground.
#   one-shot <args>   run haxiam-install.sh once with the given flags and exit.
#
# Environment (all optional):
#   HAXIAM_DOMAIN               Base domain, optionally with :port. HAXiam uses
#                               iam.<domain>, oer.<domain>, courses.<domain>.
#                               Written to /var/IAMCONFIG.php. Example:
#                               "mysite.localhost:8080".
#   HAXIAM_PROTOCOL             http | https (default https).
#   HAXIAM_ADMIN_USER           Basic-auth user for the iam.<domain> host when
#                               Azure SSO is not enabled (default admin).
#   HAXIAM_ADMIN_PASS           Password for that user. If unset a random one
#                               is generated and stored hashed; it is NOT printed.
#   HAXIAM_AZURE_TENANT / HAXIAM_AZURE_CLIENT / HAXIAM_AZURE_SECRET
#                               All three enable Azure OIDC (installer phase 6).
#   HAXIAM_AZURE_REDIRECT_BASE  e.g. https://iam.example.org
#   HAXIAM_AZURE_SCOPES         default "openid profile email"
#   HAXIAM_AZURE_SKIP_CHECK     1 = do not call Microsoft to validate the tenant
#                               (demo/offline use only).
#
# Secrets are never echoed and are passed to the installer by environment, not
# on its command line.

set -euo pipefail
export TERM="${TERM:-xterm-256color}"

HA_DIR=/var/www/iam
INSTALLER="${HA_DIR}/scripts/install/haxiam-install.sh"
HTPASSWD="${HA_DIR}/_iamConfig/.htpasswd"

log() { echo "[haxiam-entrypoint] $*"; }

cmd="${1:-serve}"

case "${cmd}" in
  one-shot)
    shift
    [[ $# -gt 0 ]] || { log "one-shot requires installer flags, e.g. --help" >&2; exit 2; }
    exec bash "${INSTALLER}" "$@"
    ;;
  --help|-h|help)
    sed -n '2,/^set -euo/p' "$0" | sed '$d'
    exit 0
    ;;
  serve) ;;
  *)
    log "unknown command: ${cmd} (expected serve | one-shot | --help)" >&2
    exit 1
    ;;
esac

# ---------------------------------------------------------------------------
# 1. /var/IAMCONFIG.php - domain + protocol (lives outside the volumes, so it
#    is rewritten from the environment on every start).
# ---------------------------------------------------------------------------
PROTOCOL="${HAXIAM_PROTOCOL:-https}"
if [[ "${PROTOCOL}" != "http" && "${PROTOCOL}" != "https" ]]; then
  log "HAXIAM_PROTOCOL must be http or https" >&2; exit 1
fi
if [[ -n "${HAXIAM_DOMAIN:-}" ]]; then
  # RFC 1123 hostname labels with an optional :port. Strict on purpose: the
  # value is interpolated into PHP source below.
  if [[ ! "${HAXIAM_DOMAIN}" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(:[0-9]{1,5})?$ ]]; then
    log "HAXIAM_DOMAIN is not a valid hostname[:port]" >&2; exit 1
  fi
  log "Writing /var/IAMCONFIG.php for ${PROTOCOL}://*.${HAXIAM_DOMAIN}"
  cat > /var/IAMCONFIG.php <<IAMCFG
<?php
define('IAM_PROTOCOL', '${PROTOCOL}://');
define('IAM_BASE_DOMAIN', '${HAXIAM_DOMAIN}');
define('IAM_EMPOWERED', 'iam');
define('IAM_PRIVATE', 'courses');
define('IAM_OPEN', 'oer');
IAMCFG
else
  log "HAXIAM_DOMAIN not set - using the placeholder domain from the boilerplate."
fi

# ---------------------------------------------------------------------------
# 2. Installer (idempotent). Azure secret goes via env, never argv.
# ---------------------------------------------------------------------------
INSTALL_ARGS=(--distro ubuntu-24.04 --skip-le --non-interactive)
if [[ -n "${HAXIAM_AZURE_TENANT:-}" || -n "${HAXIAM_AZURE_CLIENT:-}" || -n "${HAXIAM_AZURE_SECRET:-}" ]]; then
  INSTALL_ARGS+=(--azure-tenant "${HAXIAM_AZURE_TENANT:-}" --azure-client "${HAXIAM_AZURE_CLIENT:-}")
  [[ -n "${HAXIAM_AZURE_REDIRECT_BASE:-}" ]] && INSTALL_ARGS+=(--azure-redirect-base "${HAXIAM_AZURE_REDIRECT_BASE}")
  [[ -n "${HAXIAM_AZURE_SCOPES:-}" ]] && INSTALL_ARGS+=(--azure-scopes "${HAXIAM_AZURE_SCOPES}")
  # The installer itself reads HAXIAM_AZURE_SECRET from the environment.
  log "Azure OIDC settings supplied (secret not shown)."
fi
log "Running installer"
bash "${INSTALLER}" "${INSTALL_ARGS[@]}"

# php-fpm (www-data) must be able to create user spaces (IAM::liberate()).
# Non-recursive on purpose: installer phase 8 scopes the per-user chowns.
chown www-data:www-data "${HA_DIR}/users" "${HA_DIR}/users_sites"

# ---------------------------------------------------------------------------
# 2b. Host-based routing. HAXiam serves different roots per host name:
#       <domain>          the HAXiam root (login.php, oauth-callback.php) - made
#                         by the installer's catch-all vhost
#       iam.<domain>      authoring: users/<user>/ (full HAXcms manager per user)
#       oer.<domain>      published/open sites:  users_sites/<user>/sites/<site>
#       courses.<domain>  private sites (same root, access-controlled)
#     The installer only writes the catch-all vhost, so write the rest here.
#     Also expose the core's web-component build as the CDN HAXiam points at
#     (<domain>/cdn/1.x.x/); only build/ is exposed, never the core's _config.
# ---------------------------------------------------------------------------
HOST_VHOSTS=/etc/apache2/sites-available/zz-haxiam-hosts.conf
CDN_CONF=/etc/apache2/conf-available/haxiam-cdn.conf
if [[ -n "${HAXIAM_DOMAIN:-}" ]]; then
  HOST_ONLY="${HAXIAM_DOMAIN%%:*}"
  : > "${HOST_VHOSTS}"
  for pair in "iam:users" "oer:users_sites" "courses:users_sites"; do
    sub="${pair%%:*}"; root="${pair##*:}"
    cat >> "${HOST_VHOSTS}" <<VHOST
<VirtualHost *:80>
    ServerName ${sub}.${HOST_ONLY}
    DocumentRoot ${HA_DIR}/${root}
    <Directory ${HA_DIR}/${root}/>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
VHOST
  done
  a2ensite zz-haxiam-hosts >/dev/null
else
  a2dissite zz-haxiam-hosts >/dev/null 2>&1 || true
fi
# HAXiam points every site at <domain>/cdn/1.x.x/. Pages on iam./oer./courses. request
# these cross-origin, so they need CORS: the web-component build, the component
# registry, and the two small loader scripts. Nothing else from the core is exposed.
cat > "${CDN_CONF}" <<CDNCONF
AliasMatch ^/cdn/1\\.x\\.x/(build/.*|wc-registry\\.json|wc-registry-graph\\.json|build\\.js|build-haxcms\\.js)\$ ${HA_DIR}/cores/HAXcms-1.x.x/\$1
<LocationMatch "^/cdn/1\\.x\\.x/">
    Header always set Access-Control-Allow-Origin "*"
</LocationMatch>
<Directory ${HA_DIR}/cores/HAXcms-1.x.x/build/>
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
CDNCONF
a2enconf haxiam-cdn >/dev/null

# ---------------------------------------------------------------------------
# 3. Basic auth for the iam.<domain> host and /login.php when Azure SSO is not enabled.
#    Without Azure, HAXiam trusts REMOTE_USER from the web server, so Apache
#    has to authenticate someone.
# ---------------------------------------------------------------------------
AZURE_ON=no
if grep -Eq '"enabled"[[:space:]]*:[[:space:]]*true' "${HA_DIR}/_iamConfig/azure.json" 2>/dev/null; then
  AZURE_ON=yes
fi

BASIC_CONF=/etc/apache2/conf-available/haxiam-basicauth.conf
if [[ "${AZURE_ON}" == "no" ]]; then
  ADMIN_USER="${HAXIAM_ADMIN_USER:-admin}"
  if [[ ! "${ADMIN_USER}" =~ ^[A-Za-z0-9._-]{1,64}$ ]]; then
    log "HAXIAM_ADMIN_USER may only contain letters, digits, . _ -" >&2; exit 1
  fi
  if [[ -n "${HAXIAM_ADMIN_PASS:-}" ]]; then
    # -i reads the password from stdin so it never appears in argv. Only pass -c
    # (create/truncate) when the file does not exist yet; otherwise a restart
    # would wipe every other user added to this instance.
    HT_CREATE=()
    [[ -s "${HTPASSWD}" ]] || HT_CREATE=(-c)
    printf '%s' "${HAXIAM_ADMIN_PASS}" | htpasswd -i -B "${HT_CREATE[@]}" "${HTPASSWD}" "${ADMIN_USER}" >/dev/null
  elif [[ ! -s "${HTPASSWD}" ]]; then
    head -c 24 /dev/urandom | base64 | tr -d '/+=\n' | htpasswd -i -c -B "${HTPASSWD}" "${ADMIN_USER}" >/dev/null
    log "No HAXIAM_ADMIN_PASS given: generated a random password for '${ADMIN_USER}' (not printed)."
  fi
  chown root:www-data "${HTPASSWD}"; chmod 0640 "${HTPASSWD}"
  # The HAX front end calls its own API with `Authorization: Bearer <jwt>`, which replaces
  # the browser's Basic credentials header. Apache would answer those calls with 401 and
  # the page then thinks it is signed out. So exempt requests that are BOTH Bearer-
  # authenticated AND under an API path (they still need a valid HAXcms token); every
  # page and every private site stays behind basic auth.
  # The path is matched against THE_REQUEST (the original request line): %{REQUEST_URI}
  # did not match here, because the core's .htaccess rewrites API calls internally.
  cat > "${BASIC_CONF}" <<AUTHCONF
<If "(%{HTTP_HOST} =~ m#^(iam|courses)\\.# || %{THE_REQUEST} =~ m# /login\\.php#) && !(%{req:Authorization} =~ m#^Bearer # && %{THE_REQUEST} =~ m# [^ ]*/(system|x)/api/#)">
    AuthType Basic
    AuthName "HAXiam"
    AuthUserFile ${HTPASSWD}
    Require valid-user
</If>
AUTHCONF
  a2enconf haxiam-basicauth >/dev/null
  log "Basic auth enabled for iam.*, courses.* and /login.php (Azure SSO not enabled)."
else
  a2disconf haxiam-basicauth >/dev/null 2>&1 || true
  rm -f "${BASIC_CONF}"
  log "Azure SSO enabled - basic auth not used."
fi

# ---------------------------------------------------------------------------
# 4. php-fpm then Apache in the foreground (PID 1).
# ---------------------------------------------------------------------------
mkdir -p /run/php
service php8.3-fpm start
set +u
APACHE_CONFDIR=/etc/apache2 . /etc/apache2/envvars
set -u
mkdir -p "${APACHE_RUN_DIR:-/var/run/apache2}"
grep -q '^ServerName ' /etc/apache2/apache2.conf 2>/dev/null \
  || echo 'ServerName localhost' >> /etc/apache2/apache2.conf
# The installer may have started Apache in the background via graceful.
apache2ctl stop 2>/dev/null || true
sleep 1
rm -f /var/run/apache2/apache2.pid 2>/dev/null || true
log "Starting apache2 in foreground"
exec apache2 -DFOREGROUND
