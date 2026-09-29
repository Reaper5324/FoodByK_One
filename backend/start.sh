#!/bin/sh

# Dynamic port binding for Railway
sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT:-80}>/" /etc/apache2/sites-available/000-default.conf

echo "=== PORTS.CONF ==="
cat /etc/apache2/ports.conf

echo "=== VHOST CONFIG ==="
cat /etc/apache2/sites-available/000-default.conf

a2dismod mpm_event 2>/dev/null || true
a2dismod mpm_worker 2>/dev/null || true

# Explicitly ensure only prefork is active
a2enmod mpm_prefork

echo "=== PHP CHECK ==="
php -v
echo "=== APACHE MODULES ==="
apache2 -M 2>&1 | grep -E "php|rewrite"

# Hand execution off to the default Apache foreground process
exec apache2-foreground
