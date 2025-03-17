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
echo "alias g='git'" >> $HOME/.bashrc
echo "alias l='ls -laHF'" >> $HOME/.bashrc
source $HOME/.bashrc

# Install PHP 8.3 and other important packages for Ubuntu 24.04
sudo apt-get update
sudo apt-get install -y php8.3-fpm php8.3-zip php8.3-gd php8.3-dom php8.3-mbstring git apache2 brotli

# Optional for development (composer, nodejs)
# sudo apt-get install -y composer nodejs

# Enable Apache modules
sudo a2enmod proxy_fcgi
sudo a2enconf php8.3-fpm
sudo a2dismod mpm_prefork
sudo a2enmod mpm_event
sudo a2enmod http2
sudo a2enmod ssl
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod brotli

# Enable protocol support
sudo -i
sudo echo "Protocols h2 http/1.1" > /etc/apache2/conf-available/http2.conf
sudo a2enconf http2

# Restart Apache to apply all changes
sudo service apache2 restart

haxecho "Installation completed successfully on Ubuntu 24.04!"
