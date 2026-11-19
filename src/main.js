import { generateFilePath } from '@nextcloud/router'
import App from './App.vue'
import { createApp } from 'vue'

// The file picker's chunks load from wherever Nextcloud serves this app's js/ (custom_apps/ on a
// suite install), not the build's default /apps/ia/js/.
__webpack_public_path__ = generateFilePath('ia', '', 'js/')

createApp(App).mount('#ia')
