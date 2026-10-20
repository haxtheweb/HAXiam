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
if [ -z "$1" ]; then
    owner='root'
  else
    owner=$1
fi
# chown / chmod the entire thing correctly then we undo what we just did
# in all of the steps below. This ensure the entire package is devoid of holes
chown -R "$owner:$webgroup" "$haxiam"
# directories 755, files 644 (no world/group write, no blanket execute bit)
find "$haxiam" -type d -exec chmod 755 {} +
find "$haxiam" -type f -exec chmod 644 {} +
# restore the execute bit on shell scripts
find "$haxiam" -type f -name '*.sh' -exec chmod 755 {} +
# secrets / private configuration must not be world readable
if [ -d "$haxiam/_iamConfig" ]; then
  chmod 750 "$haxiam/_iamConfig"
  chown "$wwwuser:$webgroup" "$haxiam/_iamConfig"
  for secret in azure.json SALT.txt; do
    if [ -f "$haxiam/_iamConfig/$secret" ]; then
      chown "$wwwuser:$webgroup" "$haxiam/_iamConfig/$secret"
      chmod 600 "$haxiam/_iamConfig/$secret"
    fi
  done
fi
# handle names with spaces safely (null delimited)
for base in users users_sites; do
  while IFS= read -r -d '' i; do
    chown -R "$wwwuser:$webgroup" "$i"
    chown "$wwwuser:$webgroup" "$i" -v
    chmod 2755 "$i" -v
  done < <(find "$haxiam/$base" -maxdepth 1 -type d -print0)
done
# force permissions for cache directory used by gitlist
chown "$wwwuser:$webgroup" _iamConfig/cache -v
chmod 2755 _iamConfig/cache -v
