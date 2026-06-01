import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue2'
import path from 'path'

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/cp.js',
            ],
            publicDirectory: 'dist',
        }),
        vue(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
})