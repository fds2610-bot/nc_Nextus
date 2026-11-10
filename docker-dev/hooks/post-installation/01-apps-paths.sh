#!/bin/bash
# Läuft einmalig nach der Nextcloud-Erstinstallation (als www-data).
# Das offizielle Image legt apps_paths in config/apps.config.php fest. Diese später
# ladende Datei überschreibt das:
#   /custom_apps     – read-only Live-Mounts (nc_nexus und in der Shared-Umgebung
#                      weitere Apps aus den Nachbarprojekten); liegt außerhalb von
#                      /var/www/html, damit der chown des Entrypoints die Host-Dateien
#                      nicht anfasst
#   apps-extra       – schreibbar, für `occ app:install` (Deck, Calendar, Talk …)
set -e
mkdir -p /var/www/html/apps-extra
cat > /var/www/html/config/zz-dev-apps-paths.config.php <<'PHP'
<?php
$CONFIG = array (
  'apps_paths' => array (
    0 => array ( 'path' => '/var/www/html/apps', 'url' => '/apps', 'writable' => false ),
    1 => array ( 'path' => '/custom_apps', 'url' => '/custom_apps', 'writable' => false ),
    2 => array ( 'path' => '/var/www/html/apps-extra', 'url' => '/apps-extra', 'writable' => true ),
  ),
);
PHP
echo "[nc_nexus] apps_paths konfiguriert."
