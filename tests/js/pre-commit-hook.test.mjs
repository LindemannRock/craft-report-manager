import assert from 'node:assert/strict';
import {execFileSync, spawnSync} from 'node:child_process';
import {chmodSync, cpSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, unlinkSync, writeFileSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

const pluginRoot = path.resolve(import.meta.dirname, '../..');
const hookSource = path.join(pluginRoot, '.githooks/pre-commit');

function executable(pathname, source) {
    writeFileSync(pathname, source, {mode: 0o700});
    chmodSync(pathname, 0o700);
}

function fixture({workspace = false, ddev = true, ddevExit = 0, platformExit = 0, qualityExit = 0, ciExit = 0} = {}) {
    const root = mkdtempSync(path.join(os.tmpdir(), 'report-manager-hook-'));
    const packageRoot = workspace ? path.join(root, 'plugins/report-manager') : path.join(root, 'report-manager');
    const binRoot = path.join(root, 'bin');
    const logPath = path.join(root, 'commands.log');
    mkdirSync(path.join(packageRoot, '.githooks'), {recursive: true});
    mkdirSync(binRoot, {recursive: true});
    cpSync(hookSource, path.join(packageRoot, '.githooks/pre-commit'));
    writeFileSync(path.join(packageRoot, 'sentinel.txt'), 'must remain byte-identical\n');
    if (workspace) {
        mkdirSync(path.join(root, '.ddev'), {recursive: true});
        writeFileSync(path.join(root, '.ddev/config.yaml'), 'php_version: "8.3"\n');
    }
    if (ddev) {
        executable(path.join(binRoot, 'ddev'), `#!/bin/sh\nprintf 'ddev:%s\\n' "$*" >> "$REPORT_MANAGER_HOOK_TEST_LOG"\nprintf 'synthetic ddev diagnostic\\n' >&2\nexit ${ddevExit}\n`);
    }
    executable(path.join(binRoot, 'php'), `#!/bin/sh\nprintf 'php:%s\\n' "$*" >> "$REPORT_MANAGER_HOOK_TEST_LOG"\nif [ "$1" = "-r" ]; then printf '8.3.30'; exit 0; fi\nif [ "$1" = "scripts/check-quality-platform.php" ]; then printf 'synthetic quality diagnostic\\n' >&2; exit ${qualityExit}; fi\nexit 92\n`);
    executable(path.join(binRoot, 'composer'), `#!/bin/sh\nprintf 'composer:%s\\n' "$*" >> "$REPORT_MANAGER_HOOK_TEST_LOG"\nif [ "$1" = "check-platform-reqs" ]; then printf 'synthetic platform diagnostic\\n' >&2; exit ${platformExit}; fi\nif [ "$1" = "ci" ]; then printf 'synthetic ci diagnostic\\n' >&2; exit ${ciExit}; fi\nexit 91\n`);
    const environment = {...process.env, PATH: `${binRoot}:/usr/bin:/bin`, REPORT_MANAGER_HOOK_TEST_LOG: logPath};
    const snapshot = () => execFileSync('/usr/bin/find', [packageRoot, '-type', 'f', '-exec', '/usr/bin/shasum', '-a', '256', '{}', ';'], {encoding: 'utf8'}).trim().split('\n').sort().join('\n');
    const before = snapshot();
    return {
        root,
        packageRoot,
        before,
        snapshot,
        run: () => spawnSync('/bin/bash', [path.join(packageRoot, '.githooks/pre-commit')], {cwd: packageRoot, encoding: 'utf8', env: environment}),
        log: () => { try { return readFileSync(logPath, 'utf8'); } catch { return ''; } },
        cleanup: () => rmSync(root, {recursive: true, force: true}),
    };
}

function assertNoMutation(current) {
    assert.equal(current.snapshot(), current.before);
    assert.deepEqual(readdirSync(current.packageRoot).sort(), ['.githooks', 'sentinel.txt']);
}

test('workspace runs only read-only composer ci through DDEV', () => {
    const current = fixture({workspace: true});
    try {
        const result = current.run();
        assert.equal(result.status, 0, result.stderr);
        assert.match(current.log(), /^ddev:exec cd plugins\/report-manager .*composer ci$/m);
        assert.doesNotMatch(current.log(), /^(?:php|composer):/m);
        assert.doesNotMatch(current.log(), /fix|phpunit|npm|node|act/i);
        assertNoMutation(current);
    } finally { current.cleanup(); }
});

test('workspace requires DDEV and never falls back to host tools', () => {
    const current = fixture({workspace: true, ddev: false});
    try {
        const result = current.run();
        assert.equal(result.status, 127);
        assert.match(result.stderr, /ddev.*unavailable/i);
        assert.equal(current.log(), '');
        assertNoMutation(current);
    } finally { current.cleanup(); }
});

test('workspace failure preserves exact status and diagnostics', () => {
    const current = fixture({workspace: true, ddevExit: 37});
    try {
        const result = current.run();
        assert.equal(result.status, 37);
        assert.match(result.stderr, /synthetic ddev diagnostic/);
        assert.match(result.stderr, /exit 37/);
        assert.doesNotMatch(current.log(), /^(?:php|composer):/m);
        assertNoMutation(current);
    } finally { current.cleanup(); }
});

test('standalone validates platform and quality-tool compatibility before composer ci', () => {
    const current = fixture();
    try {
        assert.equal(current.run().status, 0);
        assert.match(current.log(), /^composer:check-platform-reqs --no-interaction$/m);
        assert.match(current.log(), /^php:scripts\/check-quality-platform.php$/m);
        assert.match(current.log(), /^composer:ci$/m);
        assertNoMutation(current);
    } finally { current.cleanup(); }
});

for (const [label, options, status, diagnostic] of [
    ['platform', {platformExit: 42}, 42, 'synthetic platform diagnostic'],
    ['quality tool', {qualityExit: 43}, 43, 'synthetic quality diagnostic'],
    ['composer ci', {ciExit: 44}, 44, 'synthetic ci diagnostic'],
]) {
    test(`standalone ${label} failure preserves status, diagnostics, and bytes`, () => {
        const current = fixture(options);
        try {
            const result = current.run();
            assert.equal(result.status, status);
            assert.match(result.stderr, new RegExp(diagnostic));
            assertNoMutation(current);
        } finally { current.cleanup(); }
    });
}
