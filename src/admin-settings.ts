import { createApp } from 'vue'
import AdminSettingsApp from './components/AdminSettingsApp.vue'
import '@nextcloud/dialogs/style.css'

const container = document.getElementById('user-groups-hzs-admin-settings')
if (container) {
	const app = createApp(AdminSettingsApp)
	app.mount(container)
}
