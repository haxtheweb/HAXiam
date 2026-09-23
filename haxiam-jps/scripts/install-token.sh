#!/bin/bash
#
# haxiam-jps/scripts/install-token.sh
#
# Mints a fresh one-shot install token for the JPS-driven setupUser flow:
#   1. writes the token to ${HAX_HAXIAM_DIR:-/var/www/iam}/_installtoken.txt
#      with chmod 0600 so the installer's timing-safe hash_equals() can
#      validate it server-side,
#   2. prints the (unstyled) token to stdout for the JPS action to capture
#      and inject into the POST body sent to install.php?op=advance&toStep=4.
#
# Mirrors the colour idiom from scripts/haxiam.sh (txtbld/bldgrn/bldred/txtreset
# + haxecho/haxwarn), but writes all human-facing chatter to stderr so the
# JPS-captured stdin of the next action is exactly the token, with no
# control sequences leaks.
#
# This script is called once, in the middle of the setupUser action chain.
# The corresponding cleanup step (rm _installtoken.txt) runs in a separate
# action.

set -eu

txtbld=$(tput bold 2>/dev/null || true)
bldgrn=${txtbld}$(tput setaf 2 2>/dev/null || true)
bldred=${txtbld}$(tput setaf 1 2>/dev/null || true)
txtreset=$(tput sgr0 2>/dev/null || true)
haxecho() { echo "${bldgrn}$1${txtreset}" >&2; }
haxwarn() { echo "${bldred}$1${txtreset}" >&2; }

# Allow callers outside the JPS to override the install dir.
IAM_DIR="${HAX_HAXIAM_DIR:-/var/www/iam}"

# If a leftover token is on disk from a previous broken run, warn and
# remove so the new token is the only one install.php can read.
if [ -f "${IAM_DIR}/_installtoken.txt" ]; then
  haxwarn "stale ${IAM_DIR}/_installtoken.txt removed"
  rm -f "${IAM_DIR}/_installtoken.txt"
fi

# Pull a UUID v4 from the kernel's entropy pool. Fall back to a urandom
# + xxd-derived UUID-shaped string if /proc isn't mounted (rare).
TOKEN=$(cat /proc/sys/kernel/random/uuid 2>/dev/null || true)
if [ -z "${TOKEN}" ]; then
  RAW=$(head -c 16 /dev/urandom | xxd -p)
  TOKEN="${RAW:0:8}-${RAW:8:4}-${RAW:12:4}-${RAW:16:4}-${RAW:20:12}"
fi

mkdir -p "${IAM_DIR}"
printf '%s' "${TOKEN}" > "${IAM_DIR}/_installtoken.txt"
chmod 0600 "${IAM_DIR}/_installtoken.txt"

haxecho "install token written to ${IAM_DIR}/_installtoken.txt"

# Stdout: bare token only (JPS captures with no leading colour codes).
echo "${TOKEN}"
