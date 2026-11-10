const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}

webpackConfig.performance = {
	hints: 'warning',
	maxAssetSize: 4 * 1024 * 1024,
	maxEntrypointSize: 4 * 1024 * 1024,
}

module.exports = webpackConfig
