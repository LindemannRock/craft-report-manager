import assert from 'node:assert/strict';
import {execFileSync, spawnSync} from 'node:child_process';
import {chmodSync, cpSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

const pluginRoot = path.resolve(import.meta.dirname, '../..');
const gatePath = path.join(pluginRoot, 'scripts/quality-gate.mjs');
const expectedIds = [
    'platform-compatibility',
    'composer-audit',
    'php-quality',
    'test-conventions',
    'runner-lifecycle-regressions',
    'disposable-phpunit',
    'pre-commit-hook-regressions',
    'customer-archive-regressions',
    'orchestration-regressions',
];

function definitions() {
    return JSON.parse(execFileSync('node', [gatePath, '--list'], {cwd: pluginRoot, encoding: 'utf8'}));
}

function probeFixture() {
    const root = mkdtempSync(path.join(os.tmpdir(), 'report-manager-gate-'));
    const probePath = path.join(root, 'probe.sh');
    const logPath = path.join(root, 'constituents.log');
    writeFileSync(probePath, `#!/bin/sh\nprintf '%s:%s\\n' "$1" "$2" >> "$REPORT_MANAGER_GATE_PROBE_LOG"\nprintf 'diagnostic:%s\\n' "$1" >&2\nif [ "$1" = "$REPORT_MANAGER_GATE_FAIL_ID" ]; then exit 71; fi\nexit 0\n`, {mode: 0o700});
    chmodSync(probePath, 0o700);
    return {
        run(failId = '') {
            return spawnSync('node', [gatePath, '--probe', probePath], {
                cwd: pluginRoot,
                encoding: 'utf8',
                env: {...process.env, REPORT_MANAGER_GATE_PROBE_LOG: logPath, REPORT_MANAGER_GATE_FAIL_ID: failId},
            });
        },
        ids() {
            try { return readFileSync(logPath, 'utf8').trim().split('\n').filter(Boolean).map((line) => line.split(':')[0]); } catch { return []; }
        },
        reset() { writeFileSync(logPath, ''); },
        cleanup() { rmSync(root, {recursive: true, force: true}); },
    };
}

function workflow(steps) {
    return `jobs:\n  quality-gates:\n    container: node:24-bookworm\n    services:\n      db:\n        image: mysql:8.4\n    steps:\n${steps.join('\n')}\n`;
}
const checkout = '      - uses: actions/checkout@v6';
const trust = `      - name: Trust checked-out repository\n        run: git config --global --add safe.directory "$GITHUB_WORKSPACE"`;
const gate = `      - name: Complete package quality gate\n        run: composer quality-gate`;

function actFixture(content = workflow([checkout, trust, gate])) {
    const root = mkdtempSync(path.join(os.tmpdir(), 'report-manager-act-'));
    const binRoot = path.join(root, 'bin');
    const resources = path.join(root, 'owned-resources');
    const logPath = path.join(root, 'act.log');
    mkdirSync(path.join(root, '.github/workflows'), {recursive: true});
    mkdirSync(path.join(root, 'scripts'), {recursive: true});
    mkdirSync(binRoot, {recursive: true});
    mkdirSync(resources, {recursive: true});
    cpSync(path.join(pluginRoot, 'scripts/act-quality-gates'), path.join(root, 'scripts/act-quality-gates'));
    writeFileSync(path.join(root, '.github/workflows/ci.yml'), content);
    writeFileSync(path.join(binRoot, 'act'), `#!/bin/sh\nprintf '%s\\n' "$*" > "$REPORT_MANAGER_ACT_LOG"\ntouch "$REPORT_MANAGER_ACT_RESOURCES/container" "$REPORT_MANAGER_ACT_RESOURCES/network"\ncase " $* " in *" --rm "*) rm -f "$REPORT_MANAGER_ACT_RESOURCES"/* ;; esac\nexit 73\n`, {mode: 0o700});
    chmodSync(path.join(binRoot, 'act'), 0o700);
    return {
        run: () => spawnSync('/bin/bash', ['scripts/act-quality-gates'], {cwd: root, encoding: 'utf8', env: {...process.env, PATH: `${binRoot}:/usr/bin:/bin`, REPORT_MANAGER_ACT_LOG: logPath, REPORT_MANAGER_ACT_RESOURCES: resources}}),
        log: () => readFileSync(logPath, 'utf8'),
        resources: () => readdirSync(resources),
        cleanup: () => rmSync(root, {recursive: true, force: true}),
    };
}

test('aggregate declares each accepted constituent exactly once', () => {
    const declared = definitions();
    assert.deepEqual(declared.map(({id}) => id), expectedIds);
    assert.equal(new Set(declared.map(({family}) => family)).size, expectedIds.length);
    assert.equal(new Set(declared.map(({id}) => id)).size, expectedIds.length);
    assert.match(declared.find(({id}) => id === 'disposable-phpunit').workspace, /FIXTURE_SOURCE_VENDOR_ROOT=.*Fixtures\/Project\/run\.php/);
    assert.doesNotMatch(JSON.stringify(declared), /postgres|browser|compat(?:ibility)?-matrix/i);
});

test('canonical Composer quality gate has one orchestrator and timeout owner', () => {
    const composer = JSON.parse(readFileSync(path.join(pluginRoot, 'composer.json'), 'utf8'));
    assert.deepEqual(composer.scripts['quality-gate'], ['Composer\\Config::disableProcessTimeout', 'node scripts/quality-gate.mjs']);
});

test('aggregate preserves order, diagnostics, and every operational failure status', async (context) => {
    const current = probeFixture();
    try {
        assert.equal(current.run().status, 0);
        assert.deepEqual(current.ids(), expectedIds);
        for (const id of expectedIds) {
            await context.test(id, () => {
                current.reset();
                const result = current.run(id);
                assert.equal(result.status, 71, `${id}\n${result.stdout}\n${result.stderr}`);
                assert.equal(current.ids().at(-1), id);
                assert.match(result.stderr, new RegExp(`diagnostic:${id}`));
            });
        }
    } finally { current.cleanup(); }
});

test('aggregate reports a constituent startup failure', () => {
    const missing = path.join(os.tmpdir(), 'report-manager-missing-probe');
    const result = spawnSync('node', [gatePath, '--probe', missing], {cwd: pluginRoot, encoding: 'utf8'});
    assert.equal(result.status, 1);
    assert.match(result.stderr, /could not start/);
});

test('CI and Act select the same authority with exact workspace trust', () => {
    const ci = readFileSync(path.join(pluginRoot, '.github/workflows/ci.yml'), 'utf8');
    const act = readFileSync(path.join(pluginRoot, 'scripts/act-quality-gates'), 'utf8');
    assert.equal((ci.match(/run:\s+composer quality-gate/g) ?? []).length, 1);
    assert.equal((ci.match(/safe\.directory/g) ?? []).length, 1);
    assert.match(ci, /safe\.directory "\$GITHUB_WORKSPACE"/);
    assert.doesNotMatch(ci, /safe\.directory[^\n]*\*/);
    assert.match(ci, /container:\s+node:24-bookworm/);
    assert.match(ci, /^\s{6}db:/m);
    assert.match(ci, /uses:\s+ramsey\/composer-install@v4[\s\S]*?ignore-cache:\s+yes/);
    assert.match(act, /-W \.github\/workflows\/ci\.yml/);
    assert.match(act, /^\s*--rm\s*$/m);
});

test('Act returns its exact failure and --rm removes only simulated runner resources', () => {
    const current = actFixture();
    try {
        const result = current.run();
        assert.equal(result.status, 73, result.stderr);
        assert.match(current.log(), /-j quality-gates/);
        assert.match(current.log(), /--rm/);
        assert.deepEqual(current.resources(), []);
    } finally { current.cleanup(); }
});

test('Act rejects missing, wildcard, duplicate, and misordered trust before launch', async (context) => {
    for (const [name, content] of [
        ['missing trust', workflow([checkout, gate])],
        ['wildcard trust', workflow([checkout, trust.replace('$GITHUB_WORKSPACE', '*'), gate])],
        ['duplicate trust', workflow([checkout, trust, trust, gate])],
        ['wrong order', workflow([trust, checkout, gate])],
    ]) {
        await context.test(name, () => {
            const current = actFixture(content);
            try {
                const result = current.run();
                assert.notEqual(result.status, 0);
                assert.deepEqual(current.resources(), []);
            } finally { current.cleanup(); }
        });
    }
});
