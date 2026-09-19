import { createApp } from 'vue'
import AdminSettingsApp from './components/AdminSettingsApp.vue'
import '@nextcloud/dialogs/style.css'

const container = document.getElementById('custom-user-groups-admin-settings')
if (container) {
	const app = createApp(AdminSettingsApp)
	app.mount(container)
}
