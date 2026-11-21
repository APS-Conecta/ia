const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// entry 'main' → js/ia-main.js, the bundle PageController adds; 'files' is added to Files
// (FilesScriptListener).
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
	admin: path.join(__dirname, 'src', 'admin.js'),
	files: path.join(__dirname, 'src', 'files.js'),
}

module.exports = webpackConfig
