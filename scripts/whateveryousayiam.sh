#!/bin/bash
# If I wasn't, then why would I say I am..

# where am i? move to where I am. This ensures source is properly sourced
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
# move back to install root
cd ../

# Color. The vibrant and dancing melody of the sighted.
# provide messaging colors for output to console
txtbld=$(tput bold)             # BELIEVE ME. Bold.
bldgrn=$(tput setaf 2) #  WOOT. Green.
bldred=${txtbld}$(tput setaf 1) # Booooo get off the stage. Red.
txtreset=$(tput sgr0) # uhhh what?

# cave....cave....c a ve... c      a     v         e  ....
haxecho(){
  echo "${bldgrn}$1${txtreset}"
}
# EVERYTHING IS ON FIRE
haxwarn(){
  echo "${bldred}$1${txtreset}"
}
# Create a unik, uneek, unqiue id.
getuuid(){
  echo $(cat /proc/sys/kernel/random/uuid)
}

cd cores
git clone https://github.com/elmsln/HAXcms.git HAXcms
cd HAXcms
# this file tells HAXcms that it is running in an IAM configuration
touch IAM
# act like we're installing those these config files will only be partly used
bash scripts/haxtheweb.sh "$(getuuid)" "$(getuuid)"
# work on config boilerplate
if [ ! -f "../../_iamConfig/config.json" ]; then
  cp _config/config.json ../../_iamConfig/config.json
fi
if [ ! -f "../../_iamConfig/my-custom-elements.js" ]; then
  cp _config/my-custom-elements.js ../../_iamConfig/my-custom-elements.js
fi
if [ ! -f "../../_iamConfig/config.php" ]; then
  cp _config/config.php ../../_iamConfig/config.php
fi
if [ ! -f "../../_iamConfig/.htaccess" ]; then
  cp _config/.htaccess ../../_iamConfig/.htaccess
fi
if [ ! -f "../../_iamConfig/SALT.txt" ]; then
  cp _config/SALT.txt ../../_iamConfig/.htaccess
fi