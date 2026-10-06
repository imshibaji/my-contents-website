#!/usr/bin/env node
/**
 * Deploy verification for dist/.
 *
 * The PayU 500 that occurred in production was a *partial upload*: init.php and
 * response.php were on the server but config.php was not. Nothing failed loudly,
 * the browser just showed a generic error. This script makes that class of
 * mistake impossible to ship silently.
 *
 * Usage:
 *   node scripts/verify-deploy.mjs            # verify ./dist
 *   node scripts/verify-deploy.mjs ./public   # verify another build root
 */

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

const distRoot = resolve(process.argv[2] ?? 'dist');
const errors = [];
const warnings = [];

function fail(msg) {
  errors.push(msg);
}

function warn(msg) {
  warnings.push(msg);
}

// ── 1. Build output exists ────────────────────────────────────────────────
if (!existsSync(distRoot)) {
  console.error(`✗ Build output not found: ${distRoot}\n  Run "npm run build" first.`);
  process.exit(1);
}

// ── 2. Required PHP endpoints are present and non-empty ───────────────────
// config.php is the one that silently went missing, so it is listed explicitly.
// env-loader.php is the single shared .env loader every other endpoint depends on.
const REQUIRED_PHP = [
  'api/env-loader.php',
  'api/payu/config.php',
  'api/payu/init.php',
  'api/payu/response.php',
  'api/enquiry.php',
  'api/send-mail.php',
];

console.log(`Verifying ${distRoot}\n`);

for (const rel of REQUIRED_PHP) {
  const full = join(distRoot, rel);

  if (!existsSync(full)) {
    fail(`MISSING  ${rel}  — this causes a blank HTTP 500 if another file requires it`);
    continue;
  }

  const size = statSync(full).size;
  if (size < 50) {
    fail(`EMPTY    ${rel}  (${size} bytes)`);
    continue;
  }

  const src = readFileSync(full, 'utf8');
  if (!src.startsWith('<?php')) {
    fail(`NOT PHP  ${rel}  — file does not start with "<?php"`);
    continue;
  }
  if (/\?>\s*$/.test(src.trimEnd())) {
    fail(`STRAY ?> ${rel}  — closing PHP tag can emit trailing whitespace into JSON output`);
    continue;
  }
  console.log(`  ok  ${rel}`);
}

// ── 3. The precedence bug that silently returned 200 + empty body ─────────
const configPath = join(distRoot, 'api/payu/config.php');
if (existsSync(configPath)) {
  const cfg = readFileSync(configPath, 'utf8');
  // `$_SERVER['REQUEST_METHOD'] ?? '' === 'OPTIONS'` parses as
  // `$var ?? ('' === 'OPTIONS')`, which is truthy for every real request.
  if (/REQUEST_METHOD'\]\s*\?\?\s*''\s*===/.test(cfg)) {
    fail(
      'BUG      api/payu/config.php has the unparenthesised `?? ... ===` precedence bug ' +
        '— every request exits with HTTP 200 and an empty body'
    );
  }
  if (!cfg.includes("=== 'OPTIONS'")) {
    fail('BUG      api/payu/config.php no longer handles the OPTIONS preflight');
  }
}

// ── 4. .env must not be inside the deployable web root ────────────────────
const envCandidates = [
  join(distRoot, '.env'),
  join(distRoot, 'api/.env'),
  join(distRoot, 'api/payu/.env'),
];
for (const p of envCandidates) {
  if (existsSync(p)) {
    warn(
      `${p.replace(distRoot, distRoot)} exists inside the web root. If it is being served, ` +
        'PayU credentials are publicly readable — remove it and configure the values as ' +
        'host-level environment variables instead.'
    );
  }
}

// ── 5. Static pages the payment flow redirects to ─────────────────────────
for (const page of ['courses/payment-success/index.html', 'courses/payment-failed/index.html']) {
  if (!existsSync(join(distRoot, page))) {
    fail(`MISSING  ${page}  — PayU surl/furl would 404 after a real payment`);
  }
}

// ── 6. Course catalog the server prices from ──────────────────────────────
const catalogPath = join(distRoot, 'api/course_catalog.json');
if (!existsSync(catalogPath)) {
  fail('MISSING  api/course_catalog.json  — init.php falls back to trusting a client-supplied amount');
} else {
  try {
    const catalog = JSON.parse(readFileSync(catalogPath, 'utf8'));
    const slugs = Object.keys(catalog);
    if (slugs.length === 0) {
      fail('EMPTY    api/course_catalog.json  — no priced courses found');
    } else {
      console.log(`  ok  api/course_catalog.json (${slugs.length} courses)`);
    }
  } catch (err) {
    fail(`INVALID  api/course_catalog.json  — ${err.message}`);
  }
}

// ── 7. Exactly one .env loader, and nothing reads $_ENV or calls putenv() ──────
// The server runs variables_order=GPCS, so PHP never populates $_ENV from the
// host environment. A second hand-rolled loader, or
// a direct $_ENV read anywhere else, silently reintroduces the bug where
// enquiry.php ignored DB_HOST and always dialled 127.0.0.1.
function walkPhp(dir, acc = []) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) walkPhp(full, acc);
    else if (entry.name.endsWith('.php')) acc.push(full);
  }
  return acc;
}

const apiDir = join(distRoot, 'api');
if (existsSync(apiDir)) {
  const offenders = [];

  for (const file of walkPhp(apiDir)) {
    const rel = file.slice(distRoot.length + 1);
    const src = readFileSync(file, 'utf8');

    if (rel !== 'api/env-loader.php' && /file_exists\(\s*\$dotenvPath\s*\)/.test(src)) {
      offenders.push(`${rel} has its own .env loader — only api/env-loader.php may parse .env`);
    }

    // Nothing may read $_ENV directly, the loader included. $_SERVER holds
    // per-request metadata (REQUEST_METHOD, REMOTE_ADDR) and must never route
    // through env().
    const reads = src.match(/\$_ENV\s*\[\s*['"][A-Z_]+/g) ?? [];
    if (reads.length > 0) {
      offenders.push(
        `${rel} reads ${[...new Set(reads)].join(', ')} directly — use env() so .env and host ` +
          'variables both work'
      );
    }

    // Hostinger's upload scanner strips any file that calls putenv() or writes
    // $_ENV — it reads them as credential theft. The loader parses .env into a
    // static array instead, so neither may reappear anywhere in dist/.
    if (/putenv\s*\(/.test(src)) {
      offenders.push(
        `${rel} calls putenv() — Hostinger's scanner deletes files that use it; ` +
          'the loader reads .env into a static array instead'
      );
    }
  }

  if (offenders.length > 0) {
    for (const o of offenders) fail(`ENV      ${o}`);
  } else {
    console.log('  ok  single .env loader, no direct $_ENV reads, no putenv()');
  }
}

// ── Report ────────────────────────────────────────────────────────────────
for (const w of warnings) {
  console.log(`\n⚠ ${w}`);
}

if (errors.length > 0) {
  console.error(`\n✗ ${errors.length} problem(s) — do not deploy:`);
  for (const e of errors) {
    console.error(`  ${e}`);
  }
  process.exit(1);
}

console.log('\n✓ All deploy checks passed.');
