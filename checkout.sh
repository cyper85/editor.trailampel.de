#!/bin/bash

export HOME=/tmp

ssh-keygen -F github.com || ssh-keyscan github.com >> /var/www/.ssh/known_hosts
cp /var/www/id_ed25519 /var/www/.ssh/id_ed25519
chmod 600 /var/www/.ssh/id_ed25519
#cd /var/www/tmp
rm -rf /tmp/editorgit
git clone git@github.com:cyper85/trailampel.de.git /tmp/editorgit
