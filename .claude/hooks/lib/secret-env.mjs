// The one rule for "is this a real env file?", shared by guard-files and guard-bash.
// Spec: docs/superpowers/specs/2026-09-26-project-foundation-design.md §7.

// `.env` then end-of-name or any non-letter (so `.envrc` and `.environment` pass). A glob `*` counts
// as a non-letter. Case-insensitive because macOS volumes usually are.
const SECRET_ENV_NAME = /^\.env(?![a-z])/i;

/**
 * True if a single file or directory name is a real env file. Only `.env.example` (any case) is exempt.
 * @param {string} name
 * @returns {boolean}
 */
export function isSecretEnvName(name) {
  return SECRET_ENV_NAME.test(name) && name.toLowerCase() !== '.env.example';
}

/**
 * Returns the first path or glob segment that names a secret env file or directory.
 * @param {string} pathOrGlob
 * @returns {string | undefined}
 */
export function findSecretEnvSegment(pathOrGlob) {
  return pathOrGlob.replaceAll('\\', '/').split('/').find(isSecretEnvName);
}
