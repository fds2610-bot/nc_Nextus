// @ts-check
const { defineConfig, devices } = require('@playwright/test')
const { BASE_URL } = require('./helpers/env')

module.exports = defineConfig({
	testDir: './specs',
	globalSetup: require.resolve('./global-setup'),
	// Tests teilen sich eine Nextcloud-Instanz → seriell statt parallel.
	workers: 1,
	reporter: [['list'], ['html', { open: 'never' }]],
	use: {
		baseURL: BASE_URL,
		storageState: '.auth/user.json',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
})
