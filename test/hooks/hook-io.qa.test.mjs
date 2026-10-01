import assert from 'node:assert/strict';
import path from 'node:path';
import { describe, it } from 'node:test';
import { decide, pick } from '../../.claude/hooks/lib/hook-io.mjs';
import { runHook } from '../support/run-hook.mjs';

const THROWING_GUARD = path.resolve('test/support/fixtures/throwing-guard.mjs');

describe('decide (QA)', () => {
  for (const [label, thrown] of [
    ['a string', 'plain string'],
    ['undefined', undefined],
    ['null', null],
    ['an object', { code: 42 }],
  ]) {
    it(`fails closed when the evaluator throws ${label}`, () => {
      const result = decide(() => {
        // non-Error throws are the case under test
        throw thrown;
      }, {});
      assert.equal(result.decision, 'block');
      assert.equal(typeof result.reason, 'string');
    });
  }

  it('passes the input through to the evaluator', () => {
    const input = { tool_name: 'Read' };
    let seen;
    decide((value) => {
      seen = value;
      return { decision: 'allow' };
    }, input);
    assert.equal(seen, input);
  });

  it('returns an ask decision unchanged', () => {
    assert.deepEqual(decide(() => ({ decision: 'ask', reason: 'r' }), {}), {
      decision: 'ask',
      reason: 'r',
    });
  });
});

describe('pick (QA)', () => {
  for (const [value, keys] of [
    [undefined, ['a']],
    [[], ['a']],
    [{ a: null }, ['a', 'b']],
    [{ a: ['x'] }, ['a']],
    [{ a: { b: 1 } }, ['a', 'b']],
    [{}, ['constructor']],
    [{}, ['__proto__']],
    ['str', ['length']],
  ]) {
    it(`returns undefined for ${JSON.stringify(value)} at ${JSON.stringify(keys)}`, () => {
      assert.equal(pick(value, ...keys), undefined);
    });
  }

  it('returns an empty string rather than undefined', () => {
    assert.equal(pick({ a: '' }, 'a'), '');
  });
});

describe('runGuard end to end (QA)', () => {
  it('exits 2, not 1, when the evaluator throws', async () => {
    const result = await runHook(THROWING_GUARD, '{"tool_name":"Read"}');
    assert.equal(result.code, 2);
    assert.equal(result.stdout, '');
    assert.ok(result.stderr.includes('evaluator exploded'));
  });

  it('exits 2 on invalid JSON before the evaluator runs', async () => {
    const result = await runHook(THROWING_GUARD, 'not json');
    assert.equal(result.code, 2);
    assert.ok(result.stderr.includes('not valid JSON'));
    assert.ok(!result.stderr.includes('evaluator exploded'));
  });
});
