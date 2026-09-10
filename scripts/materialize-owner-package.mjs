import { appendFileSync, mkdtempSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

// Transport only: the existing inventory still validates approval, hashes and
// commit provenance. Never print the package or include it in public artifacts.
const raw = process.env.RELEASE_OWNER_ARTIFACT_PACKAGE;
if (raw?.trim()) {
  let packageCommit = '';
  try {
    const decode = value => {
      if (typeof value !== 'string' || !value.length || Buffer.from(value, 'base64').toString('base64') !== value) {
        throw new Error('invalid base64 content');
      }
      return Buffer.from(value, 'base64');
    };
    const bundle = JSON.parse(raw);
    const manifestBytes = decode(bundle.manifest);
    const manifest = JSON.parse(manifestBytes.toString('utf8'));
    packageCommit = typeof manifest.release_commit === 'string' ? manifest.release_commit : '';
    if (manifest.schema_version !== 1 || manifest.release_profile !== 'internal-core' ||
        !/^[a-f0-9]{40}$/.test(manifest.release_commit ?? '') || manifest.release_commit !== process.env.GITHUB_SHA) {
      throw new Error('manifest profile or commit mismatch');
    }
    if (!Array.isArray(manifest.artifacts) || !manifest.artifacts.length || !bundle.evidence || Array.isArray(bundle.evidence)) {
      throw new Error('missing evidence collection');
    }
    const paths = manifest.artifacts.map(row => row.evidence_path);
    if (new Set(paths.map(path => String(path).toLowerCase())).size !== paths.length ||
        paths.length !== Object.keys(bundle.evidence).length) throw new Error('duplicate or missing evidence');
    const files = paths.map(path => {
      if (typeof path !== 'string' || !/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,179}$/.test(path) ||
          path.toLowerCase() === 'manifest.json' || /^(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i.test(path) || path.endsWith('.')) {
        throw new Error('unsafe or reserved evidence path');
      }
      return [path, decode(bundle.evidence[path])];
    });
    if (!process.env.RUNNER_TEMP || !process.env.GITHUB_ENV) throw new Error('runner paths unavailable');
    const directory = mkdtempSync(join(process.env.RUNNER_TEMP, 'internal-owner-'));
    for (const [name, bytes] of files) writeFileSync(join(directory, name), bytes, { flag: 'wx', mode: 0o600 });
    const manifestPath = join(directory, 'manifest.json');
    writeFileSync(manifestPath, manifestBytes, { flag: 'wx', mode: 0o600 });
    appendFileSync(process.env.GITHUB_ENV, `RELEASE_OWNER_ARTIFACT_MANIFEST=${manifestPath}\n`);
    console.log('Owner evidence package restored for inventory validation.');
  } catch (error) {
    // Parser errors may quote supplied content. Keep signed/private contents
    // out of the public job log even when the package is malformed.
    const safeReasons = new Set([
      'invalid base64 content', 'manifest profile or commit mismatch',
      'missing evidence collection', 'duplicate or missing evidence',
      'unsafe or reserved evidence path', 'runner paths unavailable',
    ]);
    const reason = safeReasons.has(error?.message) ? error.message : 'parser or filesystem validation failed';
    const provenanceHint = reason === 'manifest profile or commit mismatch'
      ? ` (workflow=${String(process.env.GITHUB_SHA ?? '').slice(0, 12) || 'unset'}, package=${packageCommit.slice(0, 12) || 'unset'})`
      : '';
    console.error(`Owner package invalid: ${reason}${provenanceHint}. Verify encoding, paths and commit provenance.`);
    process.exitCode = 1;
  }
}
