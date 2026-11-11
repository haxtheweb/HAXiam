#!/bin/bash
# testing/nightly/boot.sh
#
# Stand up a THROWAWAY HAXiam on this machine for the nightly flow checks
# (testing/nightly/flows.php). Runs the real installer and the real
# harden-security.sh, then swaps only the identity provider for the local
# mock in testing/nightly/mock-idp/ (no Azure, no real credentials).
#
# Only for disposable CI runners: it writes /var/IAMCONFIG.php, an Apache
# vhost, /etc/hosts entries and the php-fpm pool, so it refuses to run
# unless CI=true (or HAXIAM_NIGHTLY_ALLOW_LOCAL=1 on a scratch machine) and
# refuses to touch an install root or /var/IAMCONFIG.php it did not create.
#
# Env:
#   HAXIAM_STAGE      install root to create   (default /var/www/iam)
#   HAXCMS_REF        haxcms-php branch/tag to boot as the core (default master)
#   HAXCMS_CORE_SRC   use this local haxcms-php checkout instead of cloning
#   HAXIAM_BASE       base domain              (default haxiam.test)
set -euo pipefail
export TERM="${TERM:-dumb}"

if [[ "${CI:-}" != "true" && "${HAXIAM_NIGHTLY_ALLOW_LOCAL:-}" != "1" ]]; then
  echo "boot.sh only runs on a disposable CI runner (CI=true). Refusing." >&2
  exit 2
fi
if [[ $EUID -ne 0 ]]; then
  echo "boot.sh must run as root (sudo -E bash testing/nightly/boot.sh)" >&2
  exit 2
fi

REPO="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../.." && pwd )"
STAGE="${HAXIAM_STAGE:-/var/www/iam}"
BASE="${HAXIAM_BASE:-haxiam.test}"
HAXCMS_REF="${HAXCMS_REF:-master}"
STATE=/var/lib/haxiam-mockidp
MARKER=".haxiam-nightly"
# Fixed, obviously fake identifiers: the mock IdP only accepts these.
TENANT="00000000-0000-4000-8000-00000000a11c"
CLIENT="00000000-0000-4000-8000-0000000c11e7"
REDIRECT="http://${BASE}/oauth-callback.php"

if [[ -e "${STAGE}" && ! -e "${STAGE}/${MARKER}" ]]; then
  echo "${STAGE} exists and was not created by boot.sh. Refusing to touch it." >&2
  exit 2
fi
if [[ -e /var/IAMCONFIG.php ]] && ! grep -q 'haxiam-nightly' /var/IAMCONFIG.php; then
  echo "/var/IAMCONFIG.php exists and was not created by boot.sh. Refusing." >&2
  exit 2
fi

echo "== stage ${REPO} -> ${STAGE}"
rm -rf "${STAGE}"
mkdir -p "${STAGE}"
# Only tracked files: never carry a local _iamConfig/, cores/, vendor/ or
# real users/ content into the throwaway install.
git -C "${REPO}" ls-files -z | tar -C "${REPO}" --null -T - -cf - | tar -C "${STAGE}" -xf -
touch "${STAGE}/${MARKER}"

echo "== core: haxcms-php ${HAXCMS_CORE_SRC:-@${HAXCMS_REF}}"
mkdir -p "${STAGE}/cores"
if [[ -n "${HAXCMS_CORE_SRC:-}" ]]; then
  cp -a "${HAXCMS_CORE_SRC}/." "${STAGE}/cores/HAXcms-1.x.x/"
  rm -rf "${STAGE}/cores/HAXcms-1.x.x/.git" "${STAGE}/cores/HAXcms-1.x.x/_config"
else
  git clone --quiet --depth 1 --branch "${HAXCMS_REF}" \
    https://github.com/haxtheweb/haxcms-php.git "${STAGE}/cores/HAXcms-1.x.x"
fi

# Plain http on a private test domain, so nothing can resolve to a real host.
cat > /var/IAMCONFIG.php <<IAMCFG
<?php
// haxiam-nightly: throwaway CI config written by testing/nightly/boot.sh
define('IAM_PROTOCOL', 'http://');
define('IAM_BASE_DOMAIN', '${BASE}');
define('IAM_EMPOWERED', 'iam');
define('IAM_PRIVATE', 'courses');
define('IAM_OPEN', 'oer');
IAMCFG

echo "== real installer"
if [[ -n "${HAXIAM_VENDOR_SRC:-}" ]]; then
  # local runs without packagist access can hand in a prepared vendor/
  cp -a "${HAXIAM_VENDOR_SRC}" "${STAGE}/vendor"
fi
COMPOSER_ALLOW_SUPERUSER=1 bash "${STAGE}/scripts/install/haxiam-install.sh" \
  --distro auto --skip-le --non-interactive --domain "${BASE}" --ha "${STAGE}"

echo "== harden-security.sh (the documented post-install step)"
bash "${STAGE}/scripts/utilities/harden-security.sh"

echo "== mock identity provider"
rm -rf "${STATE}"
mkdir -p "${STATE}"
php "${STAGE}/testing/nightly/mock-idp/keygen.php" "${STATE}" "${TENANT}" "${CLIENT}" "${REDIRECT}"
chown -R www-data:www-data "${STATE}"
cat > "${STAGE}/_iamConfig/azure.json" <<AZ
{
    "enabled": true,
    "tenantId": "${TENANT}",
    "clientId": "${CLIENT}",
    "clientSecret": "nightly-not-a-secret",
    "redirectUri": "${REDIRECT}",
    "issuer": "",
    "scopes": "openid profile email",
    "providerClass": "AzureOIDC",
    "adminContactEmail": "nightly@${BASE}"
}
AZ
chown www-data:www-data "${STAGE}/_iamConfig/azure.json"
chmod 0600 "${STAGE}/_iamConfig/azure.json"

PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
POOL="/etc/php/${PHPV}/fpm/pool.d/www.conf"
if ! grep -q 'haxiam-nightly' "${POOL}"; then
  cat >> "${POOL}" <<POOLCFG
; haxiam-nightly: mock IdP seams for the throwaway CI install only
php_admin_value[auto_prepend_file] = ${STAGE}/testing/nightly/mock-idp/prepend.php
; one login session across ${BASE} (login/callback) and iam.${BASE} (the
; user's HAXcms), as a multi-host HAXiam deployment needs
php_admin_value[session.cookie_domain] = .${BASE}
env[HAXIAM_ROOT] = ${STAGE}
env[HAXIAM_MOCK_IDP_STATE] = ${STATE}
env[HAXIAM_MOCK_IDP_HOST] = http://idp.${BASE}
POOLCFG
fi
# The installer's catch-all vhost serves ${BASE} (login.php, oauth-callback.php).
# The per-role hosts follow the README layout: iam.<domain>/<user>/ is the
# user's HAXcms (users/), oer.<domain> and courses.<domain> serve
# /<user>/sites/<site>/ from users_sites/ (open and private sites).
FPM_HANDLER="proxy:unix:/run/php/php${PHPV}-fpm.sock|fcgi://localhost"
cat > /etc/apache2/sites-available/zz-haxiam-nightly.conf <<VHOST
<VirtualHost *:80>
    ServerName iam.${BASE}
    DocumentRoot ${STAGE}/users
    <Directory ${STAGE}/users/>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "${FPM_HANDLER}"
    </FilesMatch>
</VirtualHost>
<VirtualHost *:80>
    ServerName oer.${BASE}
    ServerAlias courses.${BASE}
    DocumentRoot ${STAGE}/users_sites
    <Directory ${STAGE}/users_sites/>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "${FPM_HANDLER}"
    </FilesMatch>
</VirtualHost>
<VirtualHost *:80>
    ServerName idp.${BASE}
    DocumentRoot ${STAGE}/testing/nightly/mock-idp
    <Directory ${STAGE}/testing/nightly/mock-idp/>
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "${FPM_HANDLER}"
    </FilesMatch>
</VirtualHost>
VHOST
a2ensite zz-haxiam-nightly >/dev/null
if ! grep -q "idp.${BASE}" /etc/hosts; then
  echo "127.0.0.1 ${BASE} iam.${BASE} oer.${BASE} courses.${BASE} idp.${BASE}" >> /etc/hosts
fi

echo "== start php-fpm + apache"
mkdir -p /run/php
service "php${PHPV}-fpm" restart
grep -q '^ServerName ' /etc/apache2/apache2.conf || echo 'ServerName localhost' >> /etc/apache2/apache2.conf
apache2ctl configtest
service apache2 restart
for _ in $(seq 1 30); do
  if curl -s -o /dev/null --max-time 3 "http://${BASE}/login.php"; then
    echo "HAXiam is up on http://${BASE}/"
    exit 0
  fi
  sleep 1
done
echo "HAXiam did not come up" >&2
tail -50 /var/log/apache2/error.log >&2 || true
exit 1
