/**
 * Adresse für nachgeladene Bundle-Teile aus dem tatsächlichen Installationsort der App
 * (z. B. custom_apps). Muss der erste Import des Einstiegspunkts sein.
 */
import { generateFilePath } from '@nextcloud/router'

// eslint-disable-next-line camelcase, no-undef
__webpack_public_path__ = generateFilePath('nc_nexus', '', 'js/')
