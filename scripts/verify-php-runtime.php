<?php

declare(strict_types=1);

/**
 * Fail closed when FormFlow's PHP minimum drifts across source, public metadata,
 * dependency resolution, documentation, the lockfile, or required CI.
 */

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root . '/' . $relative;
    $contents = is_file($path) ? file_get_contents($path) : false;

    if ($contents === false) {
        $failures[] = sprintf('%s is missing or unreadable', $relative);

        return '';
    }

    return $contents;
};

$composer = json_decode($read('composer.json'), true);
$lock = json_decode($read('composer.lock'), true);

if (!is_array($composer)) {
    $failures[] = 'composer.json is not valid JSON';
} else {
    if (($composer['require']['php'] ?? null) !== '>=8.2') {
        $failures[] = 'composer.json require.php must be >=8.2';
    }
    if (($composer['config']['platform']['php'] ?? null) !== '8.2.0') {
        $failures[] = 'composer.json config.platform.php must be 8.2.0';
    }
}

if (!is_array($lock)) {
    $failures[] = 'composer.lock is not valid JSON';
} else {
    if (($lock['platform']['php'] ?? null) !== '>=8.2') {
        $failures[] = 'composer.lock platform.php must be >=8.2';
    }
    if (($lock['platform-overrides']['php'] ?? null) !== '8.2.0') {
        $failures[] = 'composer.lock platform-overrides.php must be 8.2.0';
    }
}

if (!preg_match('/^\s*\* Requires PHP:\s*8\.2\s*$/m', $read('formflow.php'))) {
    $failures[] = 'formflow.php must declare PHP 8.2';
}

$readme = $read('README.md');
if (!preg_match('/^\*\*Requires PHP:\*\* 8\.2\+$/m', $readme)
    || !preg_match('/^- PHP 8\.2 or higher$/m', $readme)) {
    $failures[] = 'README.md must consistently document PHP 8.2+';
}

if (!preg_match('/:\s*true\s*\|\s*\\\\WP_Error\s*\{/', $read('includes/platform/class-api-platform.php'))) {
    $failures[] = 'the recorded PHP 8.2 standalone true-type source witness is missing';
}

$workflow = $read('.github/workflows/tests.yml');
$minimumJob = '';

if (preg_match('/^  php-minimum-tests:\s*$\R(?<body>(?:(?!^  [a-zA-Z0-9_-]+:\s*$).)*)/ms', $workflow, $match)) {
    $minimumJob = $match[0];
} else {
    $failures[] = '.github/workflows/tests.yml is missing required php-minimum-tests job';
}

$requiredWorkflowPatterns = [
    '/php-version:\s*["\']8\.2["\']/' => 'PHP 8.2 setup',
    '/composer install --no-interaction --prefer-dist/' => 'locked dependency installation',
    '/phpunit --testsuite=property/' => 'property tests',
    '/phpunit --testsuite=regression/' => 'regression tests',
    '/php scripts\/verify-php-runtime\.php --expect-runtime=8\.2/' => 'runtime identity assertion',
];

foreach ($requiredWorkflowPatterns as $pattern => $description) {
    if (!preg_match($pattern, $minimumJob)) {
        $failures[] = sprintf('php-minimum-tests is missing %s', $description);
    }
}

if (($argv[1] ?? '') === '--expect-runtime=8.2' && PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== '8.2') {
    $failures[] = sprintf('expected the PHP 8.2 runtime, got %s', PHP_VERSION);
}

if ($failures !== []) {
    fwrite(STDERR, "PHP runtime declaration contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PHP runtime declaration contract passed (minimum 8.2).\n");
