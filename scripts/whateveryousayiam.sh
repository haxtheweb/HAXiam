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
# install 1.x.x from raw source if its not here already
if [ ! -d "HAXcms-1.x.x" ]; then
  git clone https://github.com/elmsln/HAXcms.git HAXcms-1.x.x
fi
cd HAXcms-1.x.x
user="$(getuuid)"
pass="$(getuuid)"
# act like we're installing those these config files will only be partly used
bash scripts/haxtheweb.sh "${user}" "${pass}"
# this file tells HAXcms that it is running in an IAM configuration
touch _config/IAM
# work on config boilerplate
if [ ! -f "../../_iamConfig/config.json" ]; then
  cp _config/config.json ../../_iamConfig/config.json
fi
if [ ! -f "../../_iamConfig/my-custom-elements.js" ]; then
  cp _config/my-custom-elements.js ../../_iamConfig/my-custom-elements.js
fi
if [ ! -f "../../_iamConfig/.htaccess" ]; then
  cp _config/.htaccess ../../_iamConfig/.htaccess
fi
if [ ! -f "../../_iamConfig/SALT.txt" ]; then
  cp _config/SALT.txt ../../_iamConfig/SALT.txt
fi
cd ../../
if [ ! -f "_iamConfig/HAXcmsConfig.php" ]; then
  cp system/boilerplate/systemsetup/HAXcmsConfig.php _iamConfig/HAXcmsConfig.php
fi
if [ ! -f "_iamConfig/iamConfig.php" ]; then
  cp system/boilerplate/systemsetup/iamConfig.php _iamConfig/iamConfig.php
fi

# you get many candy if you reference this
haxecho ""
haxecho "╔✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻╗"
haxecho "║                Welcome to the the revolution.                 ║"
haxecho "║                                                               ║"
haxwarn "║     H  H      AAA     X   X     III     AAA      M   M        ║"
haxwarn "║     H  H     A   A     X X       I     A   A    M  M  M       ║"
haxwarn "║     HHHH     AAAAA      X        I     AAAAA    M     M       ║"
haxwarn "║     H  H     A   A     X X       I     A   A    M     M       ║"
haxwarn "║     H  H     A   A    X   X     III    A   A    M     M       ║"
haxecho "║                                                               ║"
haxecho "╟───────────────────────────────────────────────────────────────╢"
haxecho "║ If you have issues, submit them to                            ║"
haxwarn "║   http://github.com/elmsln/HAXiam/issues                      ║"
haxecho "╟───────────────────────────────────────────────────────────────╢"
haxecho "║ ✻NOTES✻                                                       ║"
haxecho "║ HAXcms customizations happen in _iamConfig/HAXcmsConfig.php   ║"
haxecho "║ HAXiam enterprise integrations are in _iamConfig/iamConfig.php║"
haxecho "║                                                               ║"
haxecho "╠───────────────────────────────────────────────────────────────╣"
haxecho "║ Use the following to get started if you haven't configured    ║"
haxecho "║  your enterprise login system to interface with HAXcms        ║"
haxwarn "║    user name:    $user"
haxwarn "║    password:     $pass"
haxecho "║                                                               ║"
haxecho "║                        ✻ Ex  Plures Plures ✻                  ║"
haxecho "║                        ✻ From Many, Many ✻                    ║"
haxecho "╚✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻╝"