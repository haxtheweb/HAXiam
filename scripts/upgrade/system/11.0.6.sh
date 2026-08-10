#!/bin/bash
# 11.0.6 - ensure every users_sites account has a working wc-registry.json symlink.
#
# IAM::liberate() was fixed in this version to create users_sites/{user}/wc-registry.json
# for new accounts. This script patches existing accounts that were liberated before the
# fix so their site pages can preload the registry without 404ing.
#
# Idempotent: skips accounts with a valid link, repairs dangling/missing ones.
# Run directly:  sudo bash scripts/upgrade/system/11.0.6.sh
# Or via runner: bash scripts/upgrade/haxiam-bash-upgrade.sh (after bumping .version)
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
cd ../../../
source _iamConfig/config.cfg
patched=0
skipped=0
for i in $(find $haxiam/users_sites -maxdepth 1 -type d); do
  if [[ "${haxiam}/users_sites" != "${i}" ]]; then
    cd "$i"
    # -e is false for both "missing" and "dangling symlink" -> needs (re)create
    # ln -sf atomically removes any dead entry before creating the fresh link
    if [ ! -e "wc-registry.json" ]; then
      ln -sf "../../cores/${haxcmscore}/wc-registry.json" wc-registry.json
      patched=$((patched+1))
    else
      skipped=$((skipped+1))
    fi
  fi
done
echo "wc-registry.json symlink patch complete: ${patched} repaired, ${skipped} already valid"
