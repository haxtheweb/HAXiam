#!/bin/bash
# where am i? move to where I am. This ensures source is properly sourced
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
cd ../../
source _iamConfig/config.cfg

#provide messaging colors for output to console
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

# check that we are the root user
if [[ $EUID -ne 0 ]]; then
  elmslnwarn "Please run as root"
  exit 1
fi

# test for an argument as to what user to write as
if [ -z $1 ]; then
    owner='root'
  else
    owner=$1
fi
# chown / chmod the entire thing correctly then we undo what we just did
# in all of the steps below. This ensure the entire package is devoid of holes
chown -R $owner:$webgroup "$haxiam"
chmod -R 775 "$haxiam"
for i in $(find $haxiam/users -maxdepth 1 -type d); do
  chown -R $wwwuser:$webgroup $i
  chown $wwwuser:$webgroup $i -v
  chmod 2775 $i -v
done
for i in $(find $haxiam/users_sites -maxdepth 1 -type d); do
  chown -R $wwwuser:$webgroup $i
  chown $wwwuser:$webgroup $i -v
  chmod 2775 $i -v
done