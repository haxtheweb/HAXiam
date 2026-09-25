#!/bin/bash
# install-ledger.sh
#
# Tiny helper called by scripts/install/haxiam-install.sh to honour
# invariant #7 of _contracts/installer_flag_surface.md (backup before
# write) and to maintain an audit trail of what the installer touched.
#
# Subcommands:
#   backup <path>   - if <path> exists, copy it to <path>.haxiam-bak and
#                     echo the restore command "cp <path>.haxiam-bak <path>"
#                     so the installer can log it.
#   added  <path>   - append a timestamped "added <path>" line to
#                     _iamConfig/install_manifest.txt.
#   wrote  <path>   - alias for "added"; both semantics are "the installer
#                     produced this file for the first time".
#   ran    <msg>    - append a free-form "step <msg>" line to the manifest.
#
# No flags, no interactivity, no network. Idempotent: re-running on an
# existing file refreshes the .haxiam-bak with the pre-write snapshot.

set -e

if [[ $EUID -ne 0 ]]; then
  echo "install-ledger: must run as root (same requirement as the installer)." >&2
  exit 1
fi

cmd="${1:-}"
shift || true

case "${cmd}" in
  backup)
    target="${1:-}"
    if [[ -z "${target}" ]]; then
      echo "install-ledger backup: missing <path>" >&2
      exit 1
    fi
    if [[ -e "${target}" ]]; then
      cp -p "${target}" "${target}.haxiam-bak"
      echo "cp ${target}.haxiam-bak ${target}"
    else
      # Nothing to back up - emit a no-op marker so the installer can log it.
      echo "no-backup:${target}"
    fi
    exit 0
    ;;
  added|wrote)
    path="${1:-}"
    if [[ -z "${path}" ]]; then
      echo "install-ledger ${cmd}: missing <path>" >&2
      exit 1
    fi
    stamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf '[%s] %s %s\n' "${stamp}" "${cmd}" "${path}" >> "_iamConfig/install_manifest.txt"
    exit 0
    ;;
  ran)
    msg="${*:-}"
    if [[ -z "${msg}" ]]; then
      echo "install-ledger ran: missing <message>" >&2
      exit 1
    fi
    stamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf '[%s] step %s\n' "${stamp}" "${msg}" >> "_iamConfig/install_manifest.txt"
    exit 0
    ;;
  *)
    echo "install-ledger: unknown subcommand '${cmd}' (backup|added|wrote|ran)" >&2
    exit 1
    ;;
esac
