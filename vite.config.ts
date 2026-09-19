import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig(
	{
		main: resolve(join('src', 'main.ts')),
		'admin-settings': resolve(join('src', 'admin-settings.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
)
