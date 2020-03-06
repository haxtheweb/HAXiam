#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
cd ../../../
source _iamConfig/config.cfg
# loop through users and ensure there's a symlink for build-home.js
for i in $(find $haxiam/users -maxdepth 1 -type d); do
  if [[ "${haxiam}/users" != "${i}" ]]; then
    cd $i
    ln -s "../../cores/${haxcmscore}/build-home.js" build-home.js
    rm composer.json
    rm composer.lock
    rm vendor
  fi
done