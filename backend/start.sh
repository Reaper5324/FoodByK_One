#!/bin/sh

# Dynamic port binding for Railway
port="${PORT:-80}"
sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${port}>/" /etc/apache2/sites-available/000-default.conf

# Hand execution off to the default Apache foreground process
exec apache2-foreground
