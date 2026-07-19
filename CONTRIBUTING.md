# Contributing to TSDM Pokemon Plugin

## Branch Strategy

- `master` — stable, deployable branch. All changes land here via PR merge.
- Feature branches — created from `master`, named `feat/<description>` or `fix/<description>`.

## Merge Policy

**All changes must be merged into `master` via Pull Request.** Direct pushes to `master` are prohibited.

Each PR must:
1. Target the `master` branch
2. Have at least one approving review before merge
3. Pass all CI checks (`just fmt-check`, `just lint`, `just test`)
4. Use **squash merge** — the PR title becomes the merge commit message

## Commit Message Convention

All commits follow the [gitmoji](https://gitmoji.dev) convention based on Celestia Island standards:

```
<gitmoji> <Capitalized English summary.>
```

| Rule | Example (pass) | Example (fail) |
|---|---|---|
| Starts with a gitmoji | `🐛 Fix the parser crash.` | `Fix the parser crash.` |
| No Conventional Commits prefix | `🐛 Fix the parser crash.` | `🐛 fix: crash.` |
| First letter after emoji is uppercase | `🐛 Fix the parser crash.` | `🐛 fix the parser crash.` |
| Ends with a period `.` | `🐛 Fix the parser crash.` | `🐛 Fix the parser crash` |
| English only | `🐛 Fix the parser crash.` | `🐛 修复崩溃。` |
| Must not start with version number | `⬆️ Upgrade dependencies.` | `⬆️ 0.3.0` |

### PR Title (becomes squash merge commit)

PR titles must include the PR number at the end:

```
<gitmoji> <Capitalized English summary.> (#<PR_ID>)
```

Example: `✨ Add evolution chain data table. (#42)`

### Exemptions

- `Merge branch '...'` and `Merge pull request #...` commits are automatically exempt.
- `Revert "..."` commits are automatically exempt.

### Quick Reference

| Gitmoji | When to use |
|---|---|
| ✨ | New feature |
| 🐛 | Bug fix |
| 📝 | Documentation |
| ♻️ | Refactor |
| 🚀 | Deploy / release / initial commit |
| 🔒 | Security fix |
| ⬆️ | Upgrade dependencies |
| 🔧 | Configuration changes |
| ✅ | Add or update tests |
| 🎨 | Format / code style |
| 🔥 | Remove code or files |
| 🚑 | Critical hotfix |
| 🔨 | Development scripts or tooling |
| 🌐 | Internationalization |
| 💡 | Add or update comments |

## Development Setup

```bash
# Install tools
just install

# Format code
just fmt

# Run tests
just test

# Lint
just lint
```

## Database Migrations

Migration scripts live in `migrations/from-x3/`. They are designed to be run sequentially:

1. `001_x3_to_x5_migration.sql` — engine/charset upgrade, new tables
2. `002_cleanup_deprecated_fields.sql` — drop deprecated columns
3. `003_restructure_tables.sql` — data normalization and index rebuild

Always back up the database before running migration scripts.
