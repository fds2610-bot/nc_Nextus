module.exports = {
	extends: [
		'@nextcloud/eslint-config/vue3',
	],
	rules: {
		'vue/custom-event-name-casing': ['error', 'kebab-case'],
		// ungescopte <style>-Blöcke wirken auf die ganze Nextcloud-Oberfläche
		'vue/enforce-style-attribute': 'error',
		'jsdoc/require-jsdoc': 'off',
	},
}
