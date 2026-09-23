#!/bin/bash
# testing/docker-entrypoint.sh
#
# Container entrypoint for testing/Dockerfile.ubuntu24. Two modes:
#
#   serve            (DEFAULT if invoked with no args, or explicitly)
#                    First-time install of /var/www/iam then foreground apache.
#                    Idempotent: skips the installer if _iamConfig/config.cfg
#                    already has a haxiam= line.
#
#   one-shot <args>  Run the installer ONCE with the given arguments, then exit.
#                    Used by testing/docker-test.sh's installer-flag unit tests
#                    (e.g. `docker run IMAGE one-shot --help`). Picks up
#                    bind mounts of /var/www/iam, so the installer operates
#                    against the bind-mounted repo.
#
# Anything else prints usage and exits 1.

set -euo pipefail

# The installer uses tput for colour output; without TERM it exits 2 and
# this script's `set -e` aborts the container before apache ever starts.
# Default a sane terminal type so the installer runs regardless of how the
# container was launched (operators often omit -e TERM).
export TERM="${TERM:-xterm-256color}"

# `serve` is the default mode — Dumbledore's choice.
if [[ $# -eq 0 ]]; then
  set -- serve
fi

case "${1:-serve}" in
  serve)
    if ! grep -Eq '^[[:space:]]*haxiam[[:space:]]*=' /var/www/iam/_iamConfig/config.cfg 2>/dev/null; then
      echo '[haxiam-docker-entrypoint] First start — running installer'
      bash /var/www/iam/scripts/install/haxiam-install.sh --distro auto --skip-le --non-interactive
    else
      echo '[haxiam-docker-entrypoint] Existing install detected — skipping installer'
    fi
    # php-fpm must be running before apache: the vhost proxies .php via
    # proxy:unix:/run/php/php8.3-fpm.sock and every request 503s without it.
    echo '[haxiam-docker-entrypoint] Starting php8.3-fpm'
    mkdir -p /run/php
    service php8.3-fpm start
    # Source /etc/apache2/envvars so APACHE_RUN_DIR / APACHE_PID_FILE /
    # APACHE_LOG_DIR are defined; calling `apache2` directly without these
    # exits with "Config variable ${APACHE_RUN_DIR} is not defined" /
    # "DefaultRuntimeDir must be a valid directory". apache2ctl sources them
    # but backgrounds the daemon and returns, so PID 1 exits and the
    # container dies. envvars is upstream Debian and references
    # APACHE_CONFDIR before defining it, which trips this script's `set -u`;
    # preset it and relax nounset for the source, then exec apache2
    # -DFOREGROUND to keep PID 1 alive in the foreground. ServerName
    # suppresses the benign FQDN warning.
    set +u
    APACHE_CONFDIR=/etc/apache2 . /etc/apache2/envvars
    set -u
    mkdir -p "${APACHE_RUN_DIR:-/var/run/apache2}"
    grep -q '^ServerName ' /etc/apache2/apache2.conf 2>/dev/null \
      || echo 'ServerName localhost' >> /etc/apache2/apache2.conf
    echo '[haxiam-docker-entrypoint] Starting apache2 in foreground'
    exec apache2 -DFOREGROUND
    ;;

  one-shot)
    shift
    if [[ $# -eq 0 ]]; then
      echo '[haxiam-docker-entrypoint] one-shot requires at least one installer flag; e.g. one-shot --help' >&2
      exit 2
    fi
    # Treat the working tree at /var/www/iam as the install root. If the
    # bind mount is empty, the installer itself errors out — that's a
    # correct test signal and we surface that exit code.
    bash /var/www/iam/scripts/install/haxiam-install.sh "$@"
    ;;

  --help|-h|help)
    cat <<USAGE
Usage:  bash docker-entrypoint.sh [serve | one-shot <installer-flags>...]

  serve (default)  install + foreground apache (idempotent on restart).
  one-shot <args>  run the installer once with the given flags, then exit.
USAGE
    exit 0
    ;;

  *)
    echo "[haxiam-docker-entrypoint] unknown subcommand: ${1}" >&2
    echo "[haxiam-docker-entrypoint] expected serve | one-shot | --help" >&2
    exit 1
    ;;
esac
