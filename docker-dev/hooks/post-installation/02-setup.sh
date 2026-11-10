#!/bin/bash
# Läuft einmalig nach der Nextcloud-Erstinstallation (als www-data), nach 01-apps-paths.sh.
# Macht die Instanz ohne weiteres Skript nutzbar: nc_nexus aktivieren, Erststart-Assistent
# aus, Zugriff des e2e-runners erlauben, Testkonten für die E2E-Tests anlegen.
# Shared-Apps aktiviert ../../dev.sh (setup), weil sie von den gemounteten Projekten abhängen.
set -e
cd /var/www/html
php occ app:enable nc_nexus
php occ app:disable firstrunwizard || true
php occ config:system:set trusted_domains 1 --value=host.docker.internal
php occ background:cron
for user in e2e e2e-other; do
	if ! php occ user:info "$user" >/dev/null 2>&1; then
		OC_PASS="$E2E_PASSWORD" php occ user:add --password-from-env --display-name "E2E $user" "$user"
	fi
done
echo "[nc_nexus] Instanz eingerichtet."
