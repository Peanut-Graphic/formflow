'use strict';

const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');

const expected = Object.freeze({ node: '22.22.2', npm: '10.9.7' });
const declarationsOnly = process.argv.includes('--declarations-only');

function readJson(path) {
  return JSON.parse(readFileSync(path, 'utf8'));
}

function assertEqual(actual, wanted, label) {
  if (actual !== wanted) {
    throw new Error(`${label}: expected ${wanted}, received ${actual}`);
  }
}

const packageJson = readJson('package.json');
const packageLock = readJson('package-lock.json');

assertEqual(packageJson.engines?.node, expected.node, 'package.json engines.node');
assertEqual(packageJson.engines?.npm, expected.npm, 'package.json engines.npm');
assertEqual(packageJson.packageManager, `npm@${expected.npm}`, 'package.json packageManager');
assertEqual(packageLock.packages?.['']?.engines?.node, expected.node, 'package-lock.json engines.node');
assertEqual(packageLock.packages?.['']?.engines?.npm, expected.npm, 'package-lock.json engines.npm');
assertEqual(readFileSync('.nvmrc', 'utf8').trim(), expected.node, '.nvmrc');

const workflow = readFileSync('.github/workflows/accessibility.yml', 'utf8');
const nodePins = [...workflow.matchAll(/node-version:\s*['"]?([^'"\s]+)/g)].map((match) => match[1]);
assertEqual(nodePins.length, 1, 'accessibility workflow Node declaration count');
assertEqual(nodePins[0], expected.node, 'accessibility workflow node-version');
assertEqual(workflow.match(/npm --version/g)?.length ?? 0, 1, 'accessibility workflow npm assertion count');

if (!declarationsOnly) {
  assertEqual(process.versions.node, expected.node, 'active Node runtime');
  const npmVersion = execFileSync('npm', ['--version'], { encoding: 'utf8' }).trim();
  assertEqual(npmVersion, expected.npm, 'active npm runtime');
}

console.log(
  declarationsOnly
    ? `Runtime declarations are pinned to Node ${expected.node} and npm ${expected.npm}.`
    : `Runtime contract verified on Node ${expected.node} and npm ${expected.npm}.`,
);
