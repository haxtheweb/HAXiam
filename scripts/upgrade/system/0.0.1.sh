#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
cd ../../../
source _iamConfig/config.cfg
# loop through users and ensure there's a symlink for .htaccess files
for i in $(find $haxiam/users_sites -maxdepth 1 -type d); do
  if [[ "${haxiam}/users_sites" != "${i}" ]]; then
    cd $i
    ln -s "../../cores/${haxcmscore}/.htaccess" .htaccess
    ln -s "../../cores/${haxcmscore}/system" system
    ln -s "../../cores/${haxcmscore}/haxcms-jwt.php" haxcms-jwt.php
  fi
done
# add in userData.json symlink cause we missed that one
for i in $(find $haxiam/users -maxdepth 1 -type d); do
  if [[ "${haxiam}/users" != "${i}" ]]; then
    cd $i/_config
    ln -s ../../../_iamConfig/userData.json userData.json
  fi
done