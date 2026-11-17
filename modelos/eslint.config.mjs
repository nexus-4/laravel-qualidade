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
            'no-console': 'warn',
            'no-unused-vars': ['error', { vars: 'local' }],
            curly: ['error', 'all'],
            'prefer-template': 'warn',
            'prefer-arrow-callback': 'warn',
            'object-shorthand': 'warn',
        },
    },
];
