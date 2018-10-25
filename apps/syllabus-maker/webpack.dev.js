const merge = require('webpack-merge');
const path = require('path');
const common = require('./webpack.common.js');
const webpack = require('webpack');

var BUILD_DIR = path.resolve(__dirname, 'public');

module.exports = merge(common, {
    output: {
        filename: 'bundle.js',
        path: path.resolve(__dirname, 'public-dev')
    },
    mode: 'development',
    devtool: 'source-map',
    devServer: {
        contentBase:  './dist',
    }
})