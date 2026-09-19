import { createApp } from 'vue'
import AdminSettingsApp from './components/AdminSettingsApp.vue'

const container = document.getElementById('custom-user-groups-admin-settings')
if (container) {
	const app = createApp(AdminSettingsApp)
	app.mount(container)
}
