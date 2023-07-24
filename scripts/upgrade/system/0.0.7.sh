#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
cd ../../../
source _iamConfig/config.cfg
# check that we are the root user
if [[ $EUID -ne 0 ]]; then
  echo "Please run as root"
  exit 1
fi
# add in userData.json symlink cause we missed that one
for i in $(find $haxiam/users -maxdepth 1 -type d); do
  if [[ "${haxiam}/users" != "${i}" ]]; then
    cd $i/_config
    ln -s ../../../_iamConfig/userData.json userData.json
  fi
done

# rebuild files for all sites to be performance optimized even in older stuff
for i in $(find $haxiam/users -maxdepth 1 -type d); do
  if [[ "${haxiam}/users" != "${i}" ]]; then
    cd $i
    echo $(basename $i)
    bash scripts/haxcms.sh rebuildManagedFiles __ALL__ $(basename $i)
    cd ..
  fi
done