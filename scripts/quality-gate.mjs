#!/usr/bin/env node

import {existsSync} from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const packageRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const workspaceRoot = path.resolve(packageRoot, '../..');
const workspaceMode = packageRoot === path.join(workspaceRoot, 'plugins/report-manager')
    && existsSync(path.join(workspaceRoot, '.ddev/config.yaml'));
const insideDdev = process.env.IS_DDEV_PROJECT === 'true';
const workspaceHostMode = workspaceMode && !insideDdev;
const runtimeVendorRoot = insideDdev ? path.join(workspaceRoot, 'vendor') : path.join(packageRoot, 'vendor');

const constituents = [
    {
        id: 'platform-compatibility',
        family: 'platform',
        standalone: ['composer', ['check-platform-reqs', '--no-interaction']],
        workspace: ['ddev', ['exec', 'composer check-platform-reqs --no-interaction']],
    },
    {
        id: 'composer-audit',
        family: 'security-audit',
        standalone: ['bash', ['scripts/composer-audit']],
        workspace: ['ddev', ['exec', 'cd plugins/report-manager && bash scripts/composer-audit']],
    },
    {
        id: 'php-quality',
        family: 'php-static-style',
        standalone: ['composer', ['ci']],
        workspace: ['ddev', ['exec', 'cd plugins/report-manager && composer ci']],
    },
    {
        id: 'test-conventions',
        family: 'test-conventions',
        standalone: ['php', ['scripts/check-test-conventions.php']],
        workspace: ['ddev', ['exec', 'cd plugins/report-manager && php scripts/check-test-conventions.php']],
    },
    {
        id: 'runner-lifecycle-regressions',
        family: 'runner-lifecycle',
        standalone: ['php', ['tests/Fixtures/Project/run.php', '--lifecycle-probe']],
        workspace: ['ddev', ['exec', 'cd plugins/report-manager && REPORT_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT=/var/www/html/vendor php tests/Fixtures/Project/run.php --lifecycle-probe']],
        standaloneEnvironment: {REPORT_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT: runtimeVendorRoot},
    },
    {
        id: 'disposable-phpunit',
        family: 'php-runtime',
        standalone: ['php', ['tests/Fixtures/Project/run.php', '--no-progress']],
        workspace: ['ddev', ['exec', 'cd plugins/report-manager && REPORT_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT=/var/www/html/vendor php tests/Fixtures/Project/run.php --no-progress']],
        standaloneEnvironment: {REPORT_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT: runtimeVendorRoot},
    },
    {
        id: 'pre-commit-hook-regressions',
        family: 'hook-routing',
        standalone: ['node', ['--test', 'tests/js/pre-commit-hook.test.mjs']],
    },
    {
        id: 'customer-archive-regressions',
        family: 'customer-archive',
        standalone: ['node', ['--test', 'tests/js/package-boundaries.test.mjs']],
    },
    {
        id: 'orchestration-regressions',
        family: 'aggregate-orchestration',
        standalone: ['node', ['--test', 'tests/js/quality-gate-orchestration.test.mjs']],
    },
];

const argumentsList = process.argv.slice(2);
const listOnly = argumentsList.includes('--list');
const probeIndex = argumentsList.indexOf('--probe');
const probeExecutable = probeIndex === -1 ? null : argumentsList[probeIndex + 1];

if (probeIndex !== -1 && (!probeExecutable || !path.isAbsolute(probeExecutable))) {
    console.error('--probe requires an absolute executable path.');
    process.exit(2);
}

if (listOnly) {
    const formatCommand = ([command, commandArguments]) => [command, ...commandArguments].join(' ');
    console.log(JSON.stringify(constituents.map(({id, family, standalone, workspace}) => ({
        id,
        family,
        standalone: formatCommand(standalone),
        workspace: formatCommand(workspace ?? standalone),
    })), null, 2));
    process.exit(0);
}

function commandFor(constituent) {
    if (probeExecutable !== null) {
        return [probeExecutable, [constituent.id, constituent.family], packageRoot, process.env];
    }
    if (workspaceHostMode && constituent.workspace) {
        return [constituent.workspace[0], constituent.workspace[1], workspaceRoot, process.env];
    }
    return [
        constituent.standalone[0],
        constituent.standalone[1],
        insideDdev && constituent.id === 'platform-compatibility' ? workspaceRoot : packageRoot,
        {...process.env, ...(constituent.standaloneEnvironment ?? {})},
    ];
}

for (const constituent of constituents) {
    const [command, commandArguments, cwd, environment] = commandFor(constituent);
    console.log(`\n==> ${constituent.id}`);
    const result = spawnSync(command, commandArguments, {cwd, env: environment, stdio: 'inherit'});
    if (result.error) {
        console.error(`${constituent.id} could not start: ${result.error.message}`);
        process.exit(1);
    }
    if (result.status !== 0) {
        const status = result.status ?? 1;
        console.error(`${constituent.id} failed with exit ${status}.`);
        process.exit(status);
    }
}

console.log('\nComplete Report Manager quality gate passed.');
