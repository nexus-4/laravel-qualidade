import js from '@eslint/js';
import globals from 'globals';

export default [
    js.configs.recommended,
    {
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'script',
            globals: {
                ...globals.browser,
                $: 'readonly',
                jQuery: 'readonly',
                katex: 'readonly',
            },
        },
        rules: {
            'no-var': 'warn',
            'prefer-const': 'warn',
            eqeqeq: ['warn', 'always'],
        },
    },
];
