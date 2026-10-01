---
paths:
  - 'eslint.config.*'
  - '.eslintrc*'
  - '.prettierignore'
  - '.prettierrc*'
  - 'prettier.config.*'
  - '.claude/hooks/**'
  - 'test/hooks/**'
  - 'test/support/**'
---

# Kit files (dev-kit)

The kit's files are plain untyped `.mjs`, formatted and linted by the kit, not by the project: keep
them out of the project's ESLint (a type-checked config can't lint them) and Prettier. In
`eslint.config.js` add to `ignores`, and add the same paths to `.prettierignore`:

```js
// Managed by Mira's dev-kit (.dev-kit.json); its tests run with `node --test`.
'.claude/hooks/', 'test/hooks/*.mjs', 'test/support/**/*.mjs',
```

The kit's Markdown (agents, rules, skills) follows the kit's own formatting, which a project's
Prettier config (quotes, print width) would rewrite on every update. Add these to `.prettierignore`
too:

```
# Managed by Mira's dev-kit: its Markdown is formatted by the kit.
.claude/agents/
.claude/rules/
.claude/skills/
```

Change the kit's files only through `/dev-kit:update` (or tell Mira); local edits are reported as
drift.
