---
paths:
  - '**/*.test.*'
  - 'test/**'
  - 'tests/**'
---

# Test conventions

- **Layers.** Unit tests for pure logic. Integration tests against the real local database or
  service when the project has one, never a mock of it. External APIs are always mocked; never use
  a real key in tests.
- Import the test runner's `describe`, `it` and assertions explicitly (the kit's own tests under
  `test/hooks/` use `node:test` and `node:assert/strict`; the project's tests use the runner named
  in `## Stack`).
- One behaviour per test. The test name states the behaviour, e.g. `blocks git push --force`, not `test 3`.
- **Keep the suite small** (Arnel, 2026-09-28, after the memory service reached 7 lines of test per
  line of code): never add a test for a behaviour an existing test already proves; an input variant
  goes into the existing table, not a new test; test the boundary and one representative case, not
  every combination; when the tests for a module exceed about three times its code, consolidate
  before adding more. A literal fixture is for the cases that decide behaviour, not for every row.
- Table-driven cases (`it.each`, or a loop over cases) when the same behaviour is checked against many inputs.
- No real network calls. External APIs are always mocked; never use a real API key.
- Database tests run against the real local database, never a mock of it.
- Shared helpers live in `test/support/`.
- Expected values come from an independent source (the spec, a hand calculation, a known fixture),
  never recomputed the way the code computes them.
- A red run must fail because the behaviour is missing — not from a typo, syntax error or a broken
  import of existing code. A "module not found" for the module you are about to create counts.
