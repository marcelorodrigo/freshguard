import js from '@eslint/js';
import sonarjs from 'eslint-plugin-sonarjs';
import pluginVue from 'eslint-plugin-vue';
import vueA11y from 'eslint-plugin-vuejs-accessibility';
import tseslint from 'typescript-eslint';

export default [
  { ignores: ['dist/**', 'coverage/**', 'node_modules/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  {
    files: ['**/*.{ts,vue}'],
    languageOptions: {
      globals: {
        window: 'readonly',
        document: 'readonly',
        navigator: 'readonly',
        localStorage: 'readonly',
        fetch: 'readonly',
        setInterval: 'readonly',
        clearInterval: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
        HTMLDialogElement: 'readonly',
        KeyboardEvent: 'readonly',
      },
    },
  },
  {
    files: ['**/*.vue'],
    languageOptions: {
      parserOptions: {
        parser: tseslint.parser,
      },
    },
  },
  {
    files: ['**/*.{ts,vue}'],
    plugins: {
      sonarjs,
      'vuejs-accessibility': vueA11y,
    },
    rules: {
      // The three Sonar rules this codebase has hit, enforced before the scan.
      // Go's twin, gocognit, already runs in `make check`.
      'no-await-in-loop': 'error',
      'sonarjs/cognitive-complexity': ['error', 15],
      'vuejs-accessibility/form-control-has-label': 'error',
    },
  },
  {
    files: ['**/*.test.ts'],
    rules: {
      // A test drives the DOM one step at a time; the rule targets hot paths.
      'no-await-in-loop': 'off',
    },
  },
  {
    rules: {
      'vue/multi-word-component-names': 'off',
      // vue-i18n's type augmentation is an interface that only extends.
      '@typescript-eslint/no-empty-object-type': [
        'error',
        { allowInterfaces: 'with-single-extends' },
      ],
    },
  },
];
