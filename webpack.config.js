const path = require('path')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// entry 'main' → js/ia-main.js, the bundle PageController adds; 'files' is added to Files
// (FilesScriptListener), 'flow' to Flow's settings (FlowListener).
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
	admin: path.join(__dirname, 'src', 'admin.js'),
	files: path.join(__dirname, 'src', 'files.js'),
	flow: path.join(__dirname, 'src', 'flow.js'),
}

// Vue's build flags, so a bundle runs on a page Nextcloud doesn't set them on (Flow's settings).
webpackConfig.plugins.push(new webpack.DefinePlugin({
	__VUE_OPTIONS_API__: true,
	__VUE_PROD_DEVTOOLS__: false,
	__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
}))

module.exports = webpackConfig
