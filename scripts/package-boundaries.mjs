import {spawnSync} from 'node:child_process';
import {existsSync, mkdirSync, mkdtempSync, rmSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

export const packageRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const approvedCustomerArchiveFileCount = 68;
const activeTemporaryPaths = new Set();
let signalHandlersInstalled = false;

function removeTemporaryPath(temporaryPath) {
    rmSync(temporaryPath, {recursive: true, force: true});
    activeTemporaryPaths.delete(temporaryPath);
}

function installSignalHandlers() {
    if (signalHandlersInstalled) return;
    signalHandlersInstalled = true;
    for (const [signal, status] of [['SIGINT', 130], ['SIGTERM', 143], ['SIGHUP', 129]]) {
        process.once(signal, () => {
            let cleanupFailed = false;
            for (const temporaryPath of activeTemporaryPaths) {
                try {
                    removeTemporaryPath(temporaryPath);
                } catch (error) {
                    cleanupFailed = true;
                    process.stderr.write(`Unable to clean package-boundary path ${temporaryPath}: ${error.message}\n`);
                }
            }
            process.exit(cleanupFailed ? 1 : status);
        });
    }
}

function ownedTemporaryDirectory(prefix, onTemporaryPath) {
    installSignalHandlers();
    const temporaryPath = mkdtempSync(path.join(os.tmpdir(), prefix));
    activeTemporaryPaths.add(temporaryPath);
    onTemporaryPath?.(temporaryPath);
    return temporaryPath;
}

export function validateArchiveMembers(members) {
    const files = members.filter((member) => !member.endsWith('/'));
    const forbidden = files.filter((member) => /^(?:composer\.lock|ecs\.php|phpstan\.neon|phpunit\.xml\.dist)$/.test(member)
        || /^(?:tests|scripts|\.github|\.githooks|\.internal|docs)\//.test(member));
    if (forbidden.length > 0) {
        throw new Error(`Customer archive contains development files: ${forbidden.join(', ')}`);
    }
    for (const required of [
        'composer.json',
        'src/ReportManager.php',
        'src/config.php',
        'src/migrations/Install.php',
        'src/services/ExportService.php',
        'src/storage/ExportStorage.php',
        'src/templates/exports/view.twig',
        'src/translations/en/report-manager.php',
        'src/icon.svg',
    ]) {
        if (!files.includes(required)) {
            throw new Error(`Customer archive is missing runtime file: ${required}`);
        }
    }
    if (files.length !== approvedCustomerArchiveFileCount) {
        throw new Error(`Customer archive changed from the approved ${approvedCustomerArchiveFileCount}-file boundary: ${files.length}`);
    }
    return files;
}

function runGit(sourceRoot, commandArguments, options = {}) {
    return spawnSync('git', [`-c`, `safe.directory=${path.resolve(sourceRoot)}`, ...commandArguments], {
        cwd: sourceRoot,
        encoding: 'utf8',
        ...options,
    });
}

export function checkPackageExport(sourceRoot = packageRoot, {onTemporaryPath, inspectArchive} = {}) {
    const archiveRoot = ownedTemporaryDirectory('report-manager-package-export-', onTemporaryPath);
    const archivePath = path.join(archiveRoot, 'package.tar');
    try {
        const indexPath = path.join(archiveRoot, 'candidate.index');
        const objectRoot = path.join(archiveRoot, 'objects');
        mkdirSync(objectRoot);
        const repositoryObjects = runGit(sourceRoot, ['rev-parse', '--git-path', 'objects']);
        if (repositoryObjects.error || repositoryObjects.status !== 0) {
            throw new Error(`Git object directory resolution failed.\n${repositoryObjects.stderr ?? ''}`);
        }
        const alternateObjects = path.resolve(sourceRoot, repositoryObjects.stdout.trim());
        const gitEnvironment = {
            ...process.env,
            GIT_INDEX_FILE: indexPath,
            GIT_OBJECT_DIRECTORY: objectRoot,
            GIT_ALTERNATE_OBJECT_DIRECTORIES: [alternateObjects, process.env.GIT_ALTERNATE_OBJECT_DIRECTORIES]
                .filter(Boolean)
                .join(path.delimiter),
        };
        for (const [label, commandArguments] of [
            ['candidate index initialization', ['read-tree', 'HEAD']],
            ['candidate worktree capture', ['add', '-A', '--', '.']],
        ]) {
            const result = runGit(sourceRoot, commandArguments, {env: gitEnvironment});
            if (result.error || result.status !== 0) {
                throw new Error(`Git ${label} failed.\n${result.stderr ?? ''}`);
            }
        }
        const tree = runGit(sourceRoot, ['write-tree'], {env: gitEnvironment});
        if (tree.error || tree.status !== 0) {
            throw new Error(`Git candidate tree creation failed.\n${tree.stderr ?? ''}`);
        }
        const archive = runGit(sourceRoot, ['archive', '--worktree-attributes', `--output=${archivePath}`, tree.stdout.trim()], {env: gitEnvironment});
        if (archive.error || archive.status !== 0) {
            throw new Error(`Git archive failed.\n${archive.stderr ?? ''}`);
        }
        const listing = spawnSync('tar', ['-tf', archivePath], {encoding: 'utf8'});
        if (listing.error || listing.status !== 0) {
            throw new Error(`Archive listing failed.\n${listing.stderr ?? ''}`);
        }
        const members = validateArchiveMembers(listing.stdout.trim().split('\n').filter(Boolean));
        inspectArchive?.(archivePath, members);
        return members;
    } finally {
        if (existsSync(archiveRoot)) {
            removeTemporaryPath(archiveRoot);
        }
    }
}
