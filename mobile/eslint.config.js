// Expo's flat config plus three local rules. The app is TypeScript strict; lint keeps the rest honest.
const expo = require('eslint-config-expo/flat');

module.exports = [
  ...expo,
  { ignores: ['node_modules/*', '.expo/*', '.export-android/*', '.export-ios/*', 'assets/*'] },
  {
    rules: {
      // Screens load on mount (useEffect -> load()); there is no React Compiler in this build.
      'react-hooks/set-state-in-effect': 'off',
      'no-console': ['warn', { allow: ['warn', 'error'] }],
      eqeqeq: ['error', 'smart'],
    },
  },
  { files: ['scripts/**'], rules: { 'no-console': 'off' } },
  {
    files: ['src/**/__tests__/**', 'jest.setup.js'],
    rules: { 'import/first': 'off', '@typescript-eslint/no-require-imports': 'off' },
  },
];
