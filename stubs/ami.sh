#!/bin/bash

# Guide: https://codinglabs.slite.com/api/s/note/6HxLyxYIL9oGuEazer_E3l/Rotate-AMIs

# disable config override prompts
export DEBIAN_FRONTEND=noninteractive

# update & upgrade packages
apt-get update
apt-get upgrade -y

# system stuff
apt-get install -y htop git zip unzip pbzip2 fail2ban mcrypt supervisor language-pack-en

# various database tools (re. mariadb-client: (https://stackoverflow.com/questions/75183032/mysqldump-for-aws-rds-flush-tables-error-on-linux-only)
apt-get install -y awscli mariadb-client percona-toolkit

# web server
apt-get install -y nginx

# PHP
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update

apt-get install -y \
    php8.3-fpm \
    php8.3-curl \
    php8.3-xml \
    php8.3-mbstring \
    php8.3-zip \
    php8.3-mysql \
    php8.3-gd \
    php8.3-bcmath \
    php8.3-intl

# PHP (Octane with Swoole)
apt-get install -y php8.3-swoole

# PHP (Sentry)
apt-get install -y php8.3-excimer

# caching
apt-get install -y memcached php8.3-memcached

# node
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
apt-get install -y gconf-service libasound2 libatk1.0-0 libc6 libcairo2 libcups2 libdbus-1-3 libexpat1 libfontconfig1 libgbm1 libgcc1 libgconf-2-4 libgdk-pixbuf2.0-0 libglib2.0-0 libgtk-3-0 libnspr4 libpango-1.0-0 libpangocairo-1.0-0 libstdc++6 libx11-6 libx11-xcb1 libxcb1 libxcomposite1 libxcursor1 libxdamage1 libxext6 libxfixes3 libxi6 libxrandr2 libxrender1 libxss1 libxtst6 ca-certificates fonts-liberation libappindicator1 libnss3 lsb-release xdg-utils wget libgbm-dev libxshmfence-dev
apt-get install -y nodejs

# puppeteer
npm install --location=global --unsafe-perm puppeteer@^17
chmod -R o+rx /usr/lib/node_modules/puppeteer/.local-chromium

# start on boot services
systemctl enable nginx php8.3-fpm

# install CodeDeploy agent
apt-get install -y ruby
cd /home/ubuntu
sudo -u ubuntu wget https://aws-codedeploy-ap-southeast-2.s3.amazonaws.com/latest/install
chmod +x ./install
./install auto
service codedeploy-agent start

# store file to indicate script is finished
touch /home/ubuntu/finished
