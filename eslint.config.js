import js from '@eslint/js';
import stylistic from '@stylistic/eslint-plugin';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

const controlStatements = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
];
const paddingAroundControl = [
    ...controlStatements.flatMap((stmt) => [
        { blankLine: 'always', prev: '*', next: stmt },
        { blankLine: 'always', prev: stmt, next: '*' },
    ]),
];

/*
 * Text that is on the screen but is not a sentence, and so has nothing to
 * translate: keys people press, the glyphs between two facts, the sigils that
 * make a handle a handle. Translating "@" or "·" would only mean somebody has
 * to invent a Dutch and an English version of the same character.
 */
const untranslatableStrings = [
    // Separators and punctuation that sit between two translated pieces.
    '·',
    '—',
    '–',
    '/',
    ':',
    '.',
    ',',
    '(',
    ')',
    '+',
    // Sigils. A handle is @name in either language, a channel #name in both.
    '@',
    '#',
    // Keys, as printed on the keyboard the reader is actually using.
    '⌘K',
    'Esc',
    'Enter',
    'Shift+Enter',
];

/*
 * The public site is deliberately monolingual — it has no reader whose language
 * we know yet — so the same rule there would only be noise.
 */
const monolingualMarketing = [
    'resources/js/pages/marketing/**',
    'resources/js/layouts/marketing/**',
    'resources/js/components/marketing/**',
];

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    reactHooks.configs.flat['recommended-latest'],
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'],
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        plugins: {
            import: importPlugin,
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './tsconfig.json',
                },
                node: true,
            },
        },
        rules: {
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: [
                        'builtin',
                        'external',
                        'internal',
                        'parent',
                        'sibling',
                        'index',
                    ],
                    alphabetize: { order: 'asc', caseInsensitive: true },
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'bootstrap/ssr',
            'tailwind.config.js',
            'vite.config.ts',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
    },
    prettier,
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            curly: ['error', 'all'],
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
        },
    },
    /*
     * Everything a reader sees goes through t(); nothing is typed straight into
     * the markup. The check exists because the failure is silent: a dialog with
     * Dutch in it looks right to whoever wrote it, and only an English reader
     * ever finds out — by which time it is a page that is half in a language
     * they cannot read.
     *
     * What is already in the tree when this went in is recorded in
     * eslint-suppressions.json rather than exempted here: those files are debt
     * with a number on it, and the number can only go down. A literal in a file
     * that has none, or one more in a file that has some, is an error today.
     *
     * A code sample is quoted rather than written, and the rule cannot be told
     * to leave <code> and <pre> alone — its element overrides only take
     * components, not plain HTML tags. Write those as an expression,
     * {'/app/'}, which the rule already reads as deliberate.
     */
    {
        files: ['resources/js/**/*.tsx'],
        rules: {
            'react/jsx-no-literals': [
                'error',
                {
                    allowedStrings: untranslatableStrings,
                },
            ],
        },
    },
    {
        files: monolingualMarketing,
        rules: {
            'react/jsx-no-literals': 'off',
        },
    },
];
