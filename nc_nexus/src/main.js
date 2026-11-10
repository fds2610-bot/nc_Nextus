import './publicPath.js'
import { createApp } from 'vue'
import '@nextcloud/dialogs/style.css'
import './styles/nexus.scss'
import App from './App.vue'

createApp(App).mount('#nc-nexus')
