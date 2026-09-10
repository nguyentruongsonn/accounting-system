#!/usr/bin/env node

/**
 * Small dependency-free release guard for the browser/desktop boundary.
 *
 * This is deliberately a high-signal check, not a replacement for a secret
 * scanner or an HTTP security-header probe.  It prevents the repository from
 * silently regressing to a disabled Tauri CSP or adding an obvious credential
 * / private-key literal to tracked source.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { extname, join } from 'node:path';

const root = join(import.meta.dirname, '..', '..');
const failures = [];

function read(relativePath, required = true) {
  try {
    return readFileSync(join(root, relativePath), 'utf8');
  } catch (error) {
    if (required) failures.push(`${relativePath}: unable to read (${error.message})`);
    return '';
  }
}

function fail(message) {
  failures.push(message);
}

let tauri;
try {
  tauri = JSON.parse(read('frontend/src-tauri/tauri.conf.json'));
} catch (error) {
  fail(`frontend/src-tauri/tauri.conf.json: invalid JSON (${error.message})`);
}

const security = tauri?.app?.security;
if (!security || security.csp === null || security.csp === undefined) {
  fail('frontend/src-tauri/tauri.conf.json: app.security.csp must be configured (null is not an acceptable release default)');
}
if (!security || security.devCsp === null || security.devCsp === undefined) {
  fail('frontend/src-tauri/tauri.conf.json: app.security.devCsp must be configured separately from the release policy');
}

if (security?.dangerousDisableAssetCspModification === true) {
  fail('frontend/src-tauri/tauri.conf.json: dangerousDisableAssetCspModification must remain false/unset');
}

function cspSources(policy, directive) {
  const value = typeof policy === 'object' && policy !== null ? policy[directive] : undefined;
  return Array.isArray(value) ? value : typeof value === 'string' ? value.split(/\s+/).filter(Boolean) : [];
}

for (const [label, policy] of [['csp', security?.csp], ['devCsp', security?.devCsp]]) {
  const scriptSources = cspSources(policy, 'script-src');
  const connectSources = cspSources(policy, 'connect-src');
  if (scriptSources.includes("'unsafe-eval'")) {
    fail(`frontend/src-tauri/tauri.conf.json: ${label}.script-src must not include unsafe-eval`);
  }
  if (scriptSources.includes("'unsafe-inline'")) {
    fail(`frontend/src-tauri/tauri.conf.json: ${label}.script-src must not include unsafe-inline`);
  }
  if (scriptSources.includes('*') || scriptSources.some((source) => /^(?:https?:|data:|blob:)/.test(source))) {
    fail(`frontend/src-tauri/tauri.conf.json: ${label}.script-src must not include wildcard, remote, data: or blob: sources`);
  }
  if (connectSources.includes('*') || connectSources.includes('https:') || connectSources.includes('http:') || connectSources.includes('ws:') || connectSources.includes('wss:')) {
    fail(`frontend/src-tauri/tauri.conf.json: ${label}.connect-src must use exact origins, not wildcard or broad schemes`);
  }
}

const devUrl = tauri?.build?.devUrl;
if (devUrl !== 'http://localhost:8080') {
  fail(`frontend/src-tauri/tauri.conf.json: build.devUrl must match the pinned Vite dev origin http://localhost:8080 (found ${devUrl ?? 'missing'})`);
}

// Keep the production-control contract aligned across the runtime config,
// MySQL smoke PHPUnit config, CI service environment, and read-only release
// preflight. A newly added fail-closed posting gate must be enabled in all
// four places or the release evidence would describe different systems.
const accountingConfig = read('backend/config/accounting.php');
const mysqlSmokeConfig = read('backend/phpunit.mysql-control-smoke.xml');
const workflow = read('.github/workflows/ci.yml');
const preflight = read('scripts/release-preflight.ps1');
const axiosSource = read('frontend/src/api/axios.ts');
const agingExecutionSource = read('frontend/src/features/reports/agingV2Execution.ts');
const uniqueSorted = (values) => [...new Set(values)].sort();
const configFlags = uniqueSorted([...accountingConfig.matchAll(/env\(\s*['"](ACCOUNTING_ENFORCE_[A-Z0-9_]+)['"]\s*,\s*true\b/g)].map((match) => match[1]));
const smokeFlags = uniqueSorted([...mysqlSmokeConfig.matchAll(/<env\s+name="(ACCOUNTING_ENFORCE_[A-Z0-9_]+)"\s+value="true"/g)].map((match) => match[1]));
const ciFlags = uniqueSorted([...workflow.matchAll(/^\s+(ACCOUNTING_ENFORCE_[A-Z0-9_]+):\s*['"]true['"]\s*$/gm)].map((match) => match[1]));
const preflightFlags = uniqueSorted([...preflight.matchAll(/^\s*'([A-Z0-9_]+)'\s*,?\s*$/gm)].map((match) => `ACCOUNTING_ENFORCE_${match[1]}`));

function assertSameFlags(label, actual, expected) {
  const missing = expected.filter((flag) => !actual.includes(flag));
  const unexpected = actual.filter((flag) => !expected.includes(flag));
  if (missing.length || unexpected.length) {
    fail(`${label} control flags differ from backend/config/accounting.php (missing: ${missing.join(', ') || 'none'}; unexpected: ${unexpected.join(', ') || 'none'})`);
  }
}

if (configFlags.length !== 19) {
  fail(`backend/config/accounting.php must expose exactly 19 fail-closed ACCOUNTING_ENFORCE_* flags (found ${configFlags.length})`);
}
assertSameFlags('backend/phpunit.mysql-control-smoke.xml', smokeFlags, configFlags);
assertSameFlags('.github/workflows/ci.yml', ciFlags, configFlags);
assertSameFlags('scripts/release-preflight.ps1', preflightFlags, configFlags);

if (/VITE_API_URL\s*\|\|\s*['"]https?:\/\//.test(axiosSource)) {
  fail('frontend/src/api/axios.ts must not use an absolute developer API fallback when VITE_API_URL is absent');
}
if (!/import\.meta\.env\.PROD\s*\?\s*['"]\/api\/v1['"]/.test(axiosSource)) {
  fail('frontend/src/api/axios.ts must fail closed to a same-origin production API path when VITE_API_URL is absent');
}
if (/VITE_API_URL\s*\|\|\s*['"]https?:\/\//.test(agingExecutionSource)) {
  fail('frontend/src/features/reports/agingV2Execution.ts must not use an absolute developer API fallback in production');
}

const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: root }).toString().split('\0').filter(Boolean);
const secretPatterns = [
  /-----BEGIN (?:RSA|EC|OPENSSH|DSA|PGP|PRIVATE) KEY-----/,
  /\bAKIA[0-9A-Z]{16}\b/,
  /\bgh[pousr]_[A-Za-z0-9]{30,}\b/,
  /\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{16,}\b/,
];

for (const relativePath of tracked) {
  const extension = extname(relativePath).toLowerCase();
  if (!['.js', '.mjs', '.cjs', '.ts', '.tsx', '.jsx', '.json', '.php', '.rs', '.yml', '.yaml', '.env'].includes(extension)) {
    continue;
  }
  // A tracked path may be deleted by the change under review.  That is not a
  // secret finding; the CI checkout will inspect the files present at its
  // commit.  Required config files are still read strictly above.
  const contents = read(relativePath, false);
  for (const pattern of secretPatterns) {
    if (pattern.test(contents)) {
      fail(`${relativePath}: high-confidence private credential pattern detected (${pattern})`);
    }
  }
}

if (failures.length > 0) {
  console.error('Security baseline check failed:');
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log(`Security baseline passed (${tracked.length} tracked files inspected).`);
