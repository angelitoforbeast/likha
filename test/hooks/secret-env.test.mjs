import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  findSecretEnvSegment,
  isSecretEnvName,
} from '../../.claude/hooks/lib/secret-env.mjs';

describe('isSecretEnvName', () => {
  for (const name of ['.env', '.ENV', '.env.local', '.Env.Production', '.env~', '.env-x']) {
    it(`treats ${name} as a secret env name`, () => {
      assert.equal(isSecretEnvName(name), true);
    });
  }

  for (const name of ['.env.example', '.ENV.EXAMPLE', '.envrc', '.environment', 'env']) {
    it(`does not treat ${name} as a secret env name`, () => {
      assert.equal(isSecretEnvName(name), false);
    });
  }
});

describe('findSecretEnvSegment', () => {
  it('returns the first secret segment of a path', () => {
    assert.equal(findSecretEnvSegment('config/.env.local/x'), '.env.local');
  });

  it('splits Windows separators', () => {
    assert.equal(findSecretEnvSegment('config\\.env'), '.env');
  });

  it('returns undefined when no segment is secret', () => {
    assert.equal(findSecretEnvSegment('config/.env.example'), undefined);
  });
});
