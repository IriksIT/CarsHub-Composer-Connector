'use strict'

// Custom types map for TriPSs/conventional-changelog-action, extending the
// bundled conventional-changelog-conventionalcommits preset. Mirrors the
// commit-type table in the root CarsHub CLAUDE.md (this module shares the
// same monorepo's conventions, split out via sync-composer-connector.yml):
// every documented type both bumps the version and gets its own
// CHANGELOG.md section (the library has no way to bump without also
// showing the type in the changelog).
const config = require('conventional-changelog-conventionalcommits')

module.exports = config({
  types: [
    { type: 'feat', section: 'Features', effect: 'bump' },
    { type: 'fix', section: 'Bug Fixes', effect: 'bump' },
    { type: 'refactor', section: 'Code Refactoring', effect: 'bump' },
    { type: 'docs', section: 'Documentation', effect: 'bump' },
    { type: 'style', section: 'Styles', effect: 'bump' },
    { type: 'test', section: 'Tests', effect: 'bump' },
    { type: 'perf', section: 'Performance', effect: 'bump' },
    { type: 'build', section: 'Dependencies', effect: 'bump' },
    { type: 'chore', section: 'Miscellaneous', effect: 'bump' },
  ],
})
