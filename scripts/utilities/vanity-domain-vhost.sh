#!/bin/bash
# vanity-domain-vhost.sh
#
# Generates an Apache vhost snippet for hooking up a "vanity domain" to an
# existing HAXiam-hosted site (e.g. https://flourish.hhd.psu.edu pointing at
# a site that otherwise lives at https://your-iam-domain/<user>/sites/<site>/).
#
# Why this exists:
# Vanity domains have historically been hand-wired by pointing DocumentRoot at
# a shallow symlink (e.g. /var/www/hhd/flourish -> .../users_sites/<user>/sites/<site>).
# This breaks two things:
#   1. Apache's <Directory> AllowOverride matching happens against the
#      *canonicalized* (symlink-resolved) path, so a directive attached to the
#      symlink path does not apply to the real target unless a matching
#      <Directory> block also exists for the resolved real path.
#   2. bootstrapHAX.php's vanity-domain support derives $userDir from
#      $_SERVER['DOCUMENT_ROOT'] assuming a specific path depth. A shallow
#      DocumentRoot alias does not have that depth, so the derived path is
#      wrong and HAXCMS_ROOT / configDirectory end up empty, producing a
#      fatal error or a silent fallback to the static index.html shell.
#
# haxtheweb.org itself has always used the correct pattern and has never hit
# this problem, e.g.:
#   ServerName haxtheweb.org
#   DocumentRoot /var/www/oer/<iam-username>/sites/<site-machine-name>/
#   SetEnv HAXSITE_BASE_URL /
#   <Directory /var/www/oer/>
#           Options Indexes FollowSymLinks
#           Header set Access-Control-Allow-Origin "*"
#           AllowOverride All
#   </Directory>
# This script generates that same shape of stanza for any other IAM user/site:
# DocumentRoot points directly at the site's real, non-symlinked path inside
# users_sites (no shallow alias directory), SetEnv HAXSITE_BASE_URL / is set,
# and AllowOverride All is granted on the shared users_sites root so each
# site's own .htaccess (needed for pretty-URL rewriting to deep links) is
# actually honored.
#
# Usage:
#   bash scripts/utilities/vanity-domain-vhost.sh <iam-username> <site-machine-name> <vanity-domain> [ssl-cert-path] [ssl-key-path]
#
# Example:
#   bash scripts/utilities/vanity-domain-vhost.sh bto108 human-flourish flourish.hhd.psu.edu \
#     /etc/letsencrypt/live/flourish.hhd.psu.edu/fullchain.pem \
#     /etc/letsencrypt/live/flourish.hhd.psu.edu/privkey.pem
#
# This ONLY prints a suggested vhost stanza to the console (via a heredoc to
# stdout) - it does not write any file, does not touch Apache's
# sites-available directory, and does not reload Apache. Review the printed
# output, sanity check the real site path exists, then copy it into your own
# vhost file and install/enable it yourself.

# where am i? move to where I am. This ensures source is properly sourced
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
cd ../../
source _iamConfig/config.cfg

# provide messaging colors for output to console
txtbld=$(tput bold)             # Bold
bldgrn=${txtbld}$(tput setaf 2) #  green
bldred=${txtbld}$(tput setaf 1) #  red
txtreset=$(tput sgr0)
elmslnecho(){
  echo "${bldgrn}$1${txtreset}"
}
elmslnwarn(){
  echo "${bldred}$1${txtreset}"
}

user="$1"
site="$2"
domain="$3"
sslCert="$4"
sslKey="$5"

if [ -z "$user" ] || [ -z "$site" ] || [ -z "$domain" ]; then
  elmslnwarn "Usage: bash scripts/utilities/vanity-domain-vhost.sh <iam-username> <site-machine-name> <vanity-domain> [ssl-cert-path] [ssl-key-path]"
  exit 1
fi

# real, non-symlinked path convention established by IAM.php's liberate():
# users_sites/<user>/_sites/<site> with a "sites" symlink alias for readability.
# Either resolves to the same real target; we use the resolved real path so
# Apache's <Directory> AllowOverride match is unambiguous regardless of which
# alias someone later points DocumentRoot at.
realSitePath="${haxiam}/users_sites/${user}/_sites/${site}"
sitesRootPath="${haxiam}/users_sites"

if [ ! -d "$realSitePath" ]; then
  elmslnwarn "Warning: ${realSitePath} does not exist yet. Double check the username/site-name, or create the site first."
fi

resolvedRealSitePath="$(cd "$realSitePath" 2>/dev/null && pwd -P || echo "$realSitePath")"

elmslnecho ""
elmslnecho "Suggested vhost stanza for ${domain}:"
elmslnecho "Review, adjust SSL cert/key paths, then add to Apache (e.g. /etc/apache2/sites-available/${domain}.conf)"
elmslnecho "and run: sudo a2ensite ${domain} && sudo apache2ctl configtest && sudo systemctl reload apache2"
elmslnecho ""

cat <<VHOST
<IfModule mod_ssl.c>
    <VirtualHost *:443>
        ServerAdmin webmaster@localhost
        ServerName ${domain}
        # Point DocumentRoot at the REAL (non-symlinked) site path, not a
        # shallow alias symlink. This keeps Apache's AllowOverride matching
        # and bootstrapHAX.php's DOCUMENT_ROOT-depth math consistent with
        # how the site is normally reached.
        DocumentRoot ${resolvedRealSitePath}/
        # Tells HAXcms the app is being served at the domain root instead of
        # under /<user>/sites/<site>/ so base tag / routing resolve correctly.
        SetEnv HAXSITE_BASE_URL /
        <Directory ${sitesRootPath}/>
                Options Indexes FollowSymLinks
                Header set Access-Control-Allow-Origin "*"
                # Must be All (not just for the symlink alias) so this site's
                # own .htaccess pretty-URL rewrite rules are honored for deep
                # links (e.g. /some-page), not just the homepage.
                AllowOverride All
                Require all granted
        </Directory>
        SSLEngine on
        SSLCertificateFile ${sslCert:-/etc/letsencrypt/live/${domain}/fullchain.pem}
        SSLCertificateKeyFile ${sslKey:-/etc/letsencrypt/live/${domain}/privkey.pem}
    </VirtualHost>
</IfModule>
<VirtualHost *:80>
    ServerName ${domain}
    Redirect permanent / https://${domain}/
</VirtualHost>
VHOST

elmslnecho ""
elmslnwarn "Sanity checks before enabling:"
elmslnwarn "  - Confirm ${resolvedRealSitePath}/.htaccess and config.php exist"
elmslnwarn "  - Confirm mod_rewrite, mod_ssl, mod_headers are enabled (a2enmod)"
elmslnwarn "  - After enabling, test both the homepage and a deep link (e.g. https://${domain}/some-page)"
