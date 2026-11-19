import { generateFilePath } from '@nextcloud/router'
import AdminSettings from './AdminSettings.vue'
import { createApp } from 'vue'

// Chunks load from wherever Nextcloud serves this app's js/, as main.js says.
__webpack_public_path__ = generateFilePath('ia', '', 'js/')

createApp(AdminSettings).mount('#ia-admin')
