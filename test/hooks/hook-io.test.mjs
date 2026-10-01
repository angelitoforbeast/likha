import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { decide, pick } from '../../.claude/hooks/lib/hook-io.mjs';

describe('decide', () => {
  it('returns the evaluator decision', () => {
    assert.deepEqual(decide(() => ({ decision: 'allow' }), {}), {
      decision: 'allow',
    });
  });

  it('fails closed when the evaluator throws', () => {
    const result = decide(() => {
      throw new Error('boom');
    }, {});
    assert.equal(result.decision, 'block');
    assert.ok(result.reason.includes('boom'));
  });

  for (const [label, returned] of [
    ['undefined', undefined],
    ['null', null],
    ['a string', 'allow'],
    ['an unknown decision', { decision: 'deny' }],
    ['a block without a reason', { decision: 'block' }],
    ['an ask with a non-string reason', { decision: 'ask', reason: 42 }],
  ]) {
    it(`fails closed when the evaluator returns ${label}`, () => {
      const evaluate = () => returned;
      assert.deepEqual(decide(evaluate, {}), {
        decision: 'block',
        reason: 'guard returned an invalid decision',
      });
    });
  }
});

describe('pick', () => {
  it('returns a nested string', () => {
    assert.equal(pick({ a: { b: 'x' } }, 'a', 'b'), 'x');
  });

  for (const [value, keys] of [
    [null, ['a']],
    [{ a: 1 }, ['a']],
    [{ a: { b: 'x' } }, ['a', 'c']],
    ['str', ['a']],
  ]) {
    it(`returns undefined for ${JSON.stringify(value)} at ${JSON.stringify(keys)}`, () => {
      assert.equal(pick(value, ...keys), undefined);
    });
  }
});
