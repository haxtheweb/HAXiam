#!/bin/bash
# Legacy curl one-liner entrypoint. Forwards every argv tail argument to the
# unified installer with the distro pinned to ubuntu-22.04. See
# scripts/install/haxiam-install.sh for the full flag surface.
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
exec "${DIR}/haxiam-install.sh" --distro ubuntu-22.04 "$@"
