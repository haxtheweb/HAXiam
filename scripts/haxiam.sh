#!/bin/sh
# elmsln.sh is intended to be an interactive prompt for administering elmsln
# this provides shortcuts for running commands you could have otherwise
# but like the developers of the project, are far too lazy to search for.

# where am i? move to where I am. This ensures source is properly sourced
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
cd ../
source _iamConfig/config.cfg

cliname="h-a-x-y"
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

# prompt the user
prompt="${cliname}: Type the number for what you'd like to do today: "
items=("oops, didn't want to be here" 'Print number of user accounts' 'Harden security' 'HAXiam - Apply infrastructure upgrades')
locations=('' '' 'utilities/harden-security.sh' 'upgrade/haxiam-bash-upgrade.sh')
commands=('' "ls -l ${haxiam}/users" 'sudo bash' 'sudo bash')
suffix=('' 'grep -c ^d' '' '')

# render the menu options
menuitems() {
  elmslnecho "Hi I'm $cliname, what would you like me to do for you: "
  for i in ${!items[@]}; do
    echo $((i))") ${items[i]}"
  done
  [[ "$msg" ]] && echo "" && echo "$msg"; :
}
haxiamversion=$(cat "$haxiam/VERSION.txt")
haxcmsversion=$(cat "$haxcms/VERSION.txt")
config_version=$(cat "$haxiam/_iamConfig/SYSTEM_VERSION.txt")
# get the latest version
touch "$haxiam/_iamConfig/tmp/LATEST.txt"
wget -O- "https://raw.githubusercontent.com/elmsln/haxcms/master/VERSION.txt" > "$haxiam/_iamConfig/tmp/HAXCMSLATEST.txt"
haxcmslatestversion=$(cat "${haxiam}/_iamConfig/tmp/HAXCMSLATEST.txt")

if [[ $config_version != $haxiamversion ]]; then
  elmslnwarn "HAXiam has infrastructure upgrades to apply!"
  elmslnwarn "To fix run: HAXiam - Apply infrastructure upgrades"
  elmslnwarn "$cliname _iamConfig version: $config_version"
fi
elmslnecho "$cliname HAXiam version: $haxiamversion"

if [[ $haxcmslatestversion != $haxcmsversion ]]; then
  elmslnwarn "HAXcms has a new release"
  elmslnwarn "$cliname HAXcms latest version: $haxcmslatestversion"
  elmslnwarn "RUN this to update HAXcms"
  elmslnwarn "cd $haxcms && git pull origin master"
fi
elmslnecho "$cliname HAXcms version: $haxcmsversion"
# make sure we get a valid response before doing anything
while menuitems && read -rp "$prompt" num && [[ "$num" ]]; do
  (( num > 0 && num <= ${#items[@]} )) || {
    if [ $num == 0 ]; then
      elmslnwarn "$cliname: See ya later!"
      exit
    fi
    msg="$cliname: $num is not a valid option, try again."; continue
  }
  # if we got here it means we have valid input
  choice="${items[num]}"
  if [ "${locations[num]}" == '' ]; then
    location=''
  else
    location="${haxiam}/scripts/${locations[num]}"
  fi
  cmd="${commands[num]}"
  elmslnecho "$cliname: $choice ($cmd $location)"
  if [ "${suffix[num]}" == '' ]; then
    $cmd $location
  else
    $cmd $location | ${suffix[num]}
  fi
done