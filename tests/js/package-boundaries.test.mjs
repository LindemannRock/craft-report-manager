import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {chmodSync, cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {pathToFileURL} from 'node:url';

import {
    approvedCustomerArchiveFileCount,
    checkPackageExport,
    packageRoot,
    validateArchiveMembers,
} from '../../scripts/package-boundaries.mjs';

const childCloseTimedOut = Symbol('child close timed out');

function packageFixture() {
    const root = mkdtempSync(path.join(os.tmpdir(), 'report-manager-candidate-'));
    const repository = path.join(root, 'repository');
    const clone = spawnSync('git', ['clone', '--no-hardlinks', '--quiet', packageRoot, repository], {encoding: 'utf8'});
    assert.equal(clone.status, 0, clone.stderr);
    for (const relativePath of ['.gitignore', '.gitattributes']) {
        cpSync(path.join(packageRoot, relativePath), path.join(repository, relativePath));
    }
    return {root, repository};
}

function gitState(repository) {
    const git = (...argumentsList) => {
        const result = spawnSync('git', argumentsList, {
            cwd: repository,
            encoding: 'utf8',
            env: {...process.env, GIT_OPTIONAL_LOCKS: '0'},
        });
        assert.equal(result.status, 0, result.stderr);
        return result.stdout;
    };
    const indexPath = path.resolve(repository, git('rev-parse', '--git-path', 'index').trim());
    return {
        index: readFileSync(indexPath),
        status: git('status', '--porcelain=v2', '--untracked-files=all'),
        staged: git('diff', '--cached', '--binary'),
        objects: git('count-objects', '-v'),
    };
}

test('customer archive preserves the approved current-candidate runtime boundary', () => {
    const before = gitState(packageRoot);
    let temporaryPath = '';
    const files = checkPackageExport(packageRoot, {onTemporaryPath: (value) => { temporaryPath = value; }});
    assert.equal(files.length, approvedCustomerArchiveFileCount);
    assert.equal(files.includes('composer.json'), true);
    assert.equal(files.includes('src/ReportManager.php'), true);
    assert.equal(files.includes('tests/TestCase.php'), false);
    assert.equal(files.includes('.github/workflows/ci.yml'), false);
    assert.equal(files.includes('.githooks/pre-commit'), false);
    assert.equal(files.includes('scripts/quality-gate.mjs'), false);
    assert.notEqual(temporaryPath, '');
    assert.equal(existsSync(temporaryPath), false);
    assert.deepEqual(gitState(packageRoot), before);
});

test('candidate archive contains uncommitted runtime bytes without changing Git state', () => {
    const current = packageFixture();
    let temporaryPath = '';
    try {
        const runtimePath = path.join(current.repository, 'src/ReportManager.php');
        const marker = '// current candidate bytes';
        writeFileSync(runtimePath, `${readFileSync(runtimePath, 'utf8')}\n${marker}\n`);
        const before = gitState(current.repository);
        checkPackageExport(current.repository, {
            onTemporaryPath: (value) => { temporaryPath = value; },
            inspectArchive: (archivePath) => {
                const extracted = spawnSync('tar', ['-xOf', archivePath, 'src/ReportManager.php'], {encoding: 'utf8'});
                assert.equal(extracted.status, 0, extracted.stderr);
                assert.match(extracted.stdout, /current candidate bytes/);
            },
        });
        assert.equal(existsSync(temporaryPath), false);
        assert.deepEqual(gitState(current.repository), before);
    } finally { rmSync(current.root, {recursive: true, force: true}); }
});

test('eligible untracked runtime files cannot be hidden by committed HEAD', () => {
    const current = packageFixture();
    let temporaryPath = '';
    try {
        writeFileSync(path.join(current.repository, 'src/CurrentCandidateRuntime.php'), '<?php\n');
        const before = gitState(current.repository);
        assert.throws(
            () => checkPackageExport(current.repository, {onTemporaryPath: (value) => { temporaryPath = value; }}),
            new RegExp(`approved ${approvedCustomerArchiveFileCount}-file boundary: ${approvedCustomerArchiveFileCount + 1}`),
        );
        assert.equal(existsSync(temporaryPath), false);
        assert.deepEqual(gitState(current.repository), before);
    } finally { rmSync(current.root, {recursive: true, force: true}); }
});

test('archive validation rejects development leakage and missing runtime files', () => {
    assert.throws(() => validateArchiveMembers(['composer.json', 'tests/TestCase.php']), /development files/);
    assert.throws(() => validateArchiveMembers(['composer.lock']), /development files: composer\.lock/);
    assert.throws(() => validateArchiveMembers(['composer.json']), /missing runtime file/);
});

test('generated package composer lock remains outside the customer archive', () => {
    const current = packageFixture();
    try {
        writeFileSync(path.join(current.repository, 'composer.lock'), '{"_readme": ["generated by Composer"]}\n');
        const files = checkPackageExport(current.repository);
        assert.equal(files.length, approvedCustomerArchiveFileCount);
        assert.equal(files.includes('composer.lock'), false);
    } finally { rmSync(current.root, {recursive: true, force: true}); }
});

test('deleted or export-ignored required runtime files fail and clean archive resources', async (context) => {
    for (const [name, mutate] of [
        ['deleted', (repository) => rmSync(path.join(repository, 'src/ReportManager.php'))],
        ['export ignored', (repository) => {
            const attributes = path.join(repository, '.gitattributes');
            writeFileSync(attributes, `${readFileSync(attributes, 'utf8')}\nsrc/ReportManager.php export-ignore\n`);
        }],
    ]) {
        await context.test(name, () => {
            const current = packageFixture();
            let temporaryPath = '';
            try {
                mutate(current.repository);
                assert.throws(
                    () => checkPackageExport(current.repository, {onTemporaryPath: (value) => { temporaryPath = value; }}),
                    /missing runtime file: src\/ReportManager\.php/,
                );
                assert.equal(existsSync(temporaryPath), false);
            } finally { rmSync(current.root, {recursive: true, force: true}); }
        });
    }
});

async function waitForPath(ownedPath, description, timeoutMs = 5000) {
    const deadline = Date.now() + timeoutMs;
    while (!existsSync(ownedPath)) {
        if (Date.now() >= deadline) throw new Error(`Timed out waiting for ${description}.`);
        await new Promise((resolve) => setTimeout(resolve, 20));
    }
}

async function waitForChildClose(childClosed, timeoutMs = 5000) {
    let timeout;
    try {
        return await Promise.race([
            childClosed,
            new Promise((resolve) => { timeout = setTimeout(() => resolve(childCloseTimedOut), timeoutMs); }),
        ]);
    } finally { clearTimeout(timeout); }
}

function signalDetachedChild(child, signal) {
    try { process.kill(-child.pid, signal); } catch (error) {
        if (error.code !== 'ESRCH') throw error;
    }
}

test('interruption removes the exact candidate archive resources', async () => {
    const root = mkdtempSync(path.join(os.tmpdir(), 'report-manager-archive-interrupt-'));
    const binRoot = path.join(root, 'bin');
    const readyPath = path.join(root, 'ready.txt');
    const wrapperReadyPath = path.join(root, 'wrapper-ready.txt');
    mkdirSync(binRoot);
    const realGit = spawnSync('sh', ['-c', 'command -v git'], {encoding: 'utf8'}).stdout.trim();
    const gitWrapper = path.join(binRoot, 'git');
    writeFileSync(gitWrapper, `#!/bin/sh
case "$*" in
  *" read-tree HEAD")
    trap 'exit 143' TERM
    printf 'ready\n' > "$REPORT_MANAGER_WRAPPER_READY"
    while :; do sleep 1; done
    ;;
esac
exec "$REPORT_MANAGER_REAL_GIT" "$@"
`, {mode: 0o700});
    chmodSync(gitWrapper, 0o700);
    const moduleUrl = pathToFileURL(path.join(packageRoot, 'scripts/package-boundaries.mjs')).href;
    const childSource = `import {writeFileSync} from 'node:fs'; import {checkPackageExport} from ${JSON.stringify(moduleUrl)}; checkPackageExport(${JSON.stringify(packageRoot)}, {onTemporaryPath: (value) => writeFileSync(${JSON.stringify(readyPath)}, value)});`;
    const child = spawn(process.execPath, ['--input-type=module', '-e', childSource], {
        cwd: packageRoot,
        detached: true,
        env: {
            ...process.env,
            PATH: `${binRoot}:${process.env.PATH}`,
            REPORT_MANAGER_REAL_GIT: realGit,
            REPORT_MANAGER_WRAPPER_READY: wrapperReadyPath,
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    const childClosed = new Promise((resolve) => child.once('close', (code, signal) => resolve({code, signal})));
    let temporaryPath = '';
    try {
        await waitForPath(readyPath, 'archive child to publish its path');
        await waitForPath(wrapperReadyPath, 'Git wrapper readiness');
        temporaryPath = readFileSync(readyPath, 'utf8');
        assert.equal(existsSync(temporaryPath), true);
        signalDetachedChild(child, 'SIGTERM');
        const result = await waitForChildClose(childClosed);
        assert.notEqual(result, childCloseTimedOut);
        assert.notEqual(result.code, 0);
        assert.equal(existsSync(temporaryPath), false);
    } finally {
        if (child.exitCode === null && child.signalCode === null) {
            signalDetachedChild(child, 'SIGKILL');
            await waitForChildClose(childClosed);
        }
        if (temporaryPath !== '') rmSync(temporaryPath, {recursive: true, force: true});
        rmSync(root, {recursive: true, force: true});
    }
});
