#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
cd ../../../
source _iamConfig/config.cfg
# loop through user sites linkage and ensure there's a symlink for these new files
for i in $(find $haxiam/users_sites -maxdepth 1 -type d); do
  if [[ "${haxiam}/users_sites" != "${i}" ]]; then
    cd $i
    rm sites
    ln -s ./_sites sites
    rm archived
    ln -s ./_archived archived
    rm published
    ln -s ./_published published
  fi
done