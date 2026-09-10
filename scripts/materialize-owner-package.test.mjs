import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const script = new URL('./materialize-owner-package.mjs', import.meta.url);
const sha = 'a'.repeat(40);
const evidence = Buffer.from('Signed evidence\r\nUTF-8: Nguyễn\n');
function input(path = 'owner.md', commit = sha) {
  return {
    manifest: Buffer.from(JSON.stringify({ schema_version: 1, release_profile: 'internal-core', release_commit: commit, artifacts: [{ evidence_path: path }] })).toString('base64'),
    evidence: { [path]: evidence.toString('base64') },
  };
}
function invoke(t, value) {
  const dir = mkdtempSync(join(tmpdir(), 'owner-package-test-'));
  t.after(() => rmSync(dir, { recursive: true, force: true }));
  const run = spawnSync(process.execPath, [fileURLToPath(script)], {
    encoding: 'utf8', env: { ...process.env, RUNNER_TEMP: dir, GITHUB_SHA: sha, GITHUB_ENV: join(dir, 'job.env'), RELEASE_OWNER_ARTIFACT_PACKAGE: value === undefined ? '' : JSON.stringify(value) },
  });
  return { ...run, dir };
}
test('transports signed bytes unchanged and writes only the manifest path to job environment', t => {
  const run = invoke(t, input());
  assert.equal(run.status, 0, run.stderr);
  const path = readFileSync(join(run.dir, 'job.env'), 'utf8').trim().split('=').slice(1).join('=');
  const manifest = JSON.parse(readFileSync(path));
  assert.equal(manifest.release_commit, sha);
  assert.deepEqual(readFileSync(join(path, '..', 'owner.md')), evidence);
  assert.equal((run.stdout + run.stderr).includes('Signed evidence'), false);
});
for (const path of ['../escape.md', '/escape.md', 'C:\\escape.md', 'manifest.json']) {
  test(`rejects unsafe or reserved evidence path ${path} before writing files`, t => {
    const run = invoke(t, input(path));
    assert.equal(run.status, 1);
    assert.match(run.stderr, /Owner package invalid:/);
    assert.deepEqual(readdirSync(run.dir), []);
  });
}
test('rejects a package approved against a different source commit', t => {
  const run = invoke(t, input('owner.md', 'b'.repeat(40)));
  assert.equal(run.status, 1);
  assert.match(run.stderr, /Owner package invalid:/);
  assert.deepEqual(readdirSync(run.dir), []);
});
test('missing package preserves external-path mode without manufacturing approval', t => {
  const run = invoke(t);
  assert.equal(run.status, 0, run.stderr);
  assert.deepEqual(readdirSync(run.dir), []);
});
test('rejects missing evidence and invalid base64 before any files are written', t => {
  for (const value of [{ ...input(), evidence: {} }, { ...input(), evidence: { 'owner.md': 'invalid!' } }]) {
    const run = invoke(t, value);
    assert.equal(run.status, 1);
    assert.match(run.stderr, /Owner package invalid:/);
    assert.deepEqual(readdirSync(run.dir), []);
  }
});
