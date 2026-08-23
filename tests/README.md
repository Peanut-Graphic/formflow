# PHPUnit Testing Setup

This directory contains automated tests for the FormFlow plugin.

## Prerequisites

1. **PHP 8.2+** installed locally with CLI access
2. **Composer** installed globally

## Installation

From the plugin root directory (`formflow/`):

```bash
# Install dependencies
composer install
```

This will install:
- PHPUnit 9.6
- Brain Monkey (for mocking WordPress functions)
- Mockery (for mocking PHP classes)
- Yoast PHPUnit Polyfills

## Running Tests

### Run All Tests

```bash
composer test
# or
./vendor/bin/phpunit
```

### Run Only Unit Tests

```bash
composer test:unit
# or
./vendor/bin/phpunit --testsuite=unit
```

### Run Only Integration Tests

```bash
composer test:integration
# or
./vendor/bin/phpunit --testsuite=integration
```

### Run Tests with Coverage Report

```bash
composer test:coverage
# or
./vendor/bin/phpunit --coverage-html coverage
```

Then open `coverage/html/index.html` in your browser.

### Run Specific Test File

```bash
./vendor/bin/phpunit tests/Unit/Analytics/AttributionCalculatorTest.php
```

### Run Specific Test Method

```bash
./vendor/bin/phpunit --filter testFirstTouchAttributionGivesFullCreditToFirstTouch
```

## Test Structure

```
tests/
├── bootstrap.php              # Test initialization
├── Unit/                      # Unit tests (isolated, mocked dependencies)
│   ├── TestCase.php          # Base test class with common mocks
│   └── Analytics/
│       ├── AttributionCalculatorTest.php
│       ├── VisitorTrackerTest.php
│       ├── HandoffTrackerTest.php
│       └── TouchRecorderTest.php
└── Integration/              # Integration tests (require WordPress)
    └── (future tests)
```

## Writing Tests

### Unit Tests

Unit tests use Brain Monkey to mock WordPress functions. Extend `ISF\Tests\Unit\TestCase`:

```php
<?php
namespace ISF\Tests\Unit\Analytics;

use ISF\Tests\Unit\TestCase;

class MyClassTest extends TestCase
{
    public function testSomething(): void
    {
        // WordPress functions are automatically mocked
        // Use $this->mockWpdb() for database mocking

        $this->assertTrue(true);
    }
}
```

### Available Helpers in TestCase

- `mockWpdb(array $methods)` - Mock the global `$wpdb` object
- `mockDatabase()` - Mock the ISF Database class
- `getSampleVisitorData()` - Get sample visitor array
- `getSampleTouchData()` - Get sample touch array
- `getSampleHandoffData()` - Get sample handoff array
- `assertArrayHasKeys(array $keys, array $actual)` - Assert multiple keys exist

## Test Coverage Goals

| Class | Target Coverage |
|-------|-----------------|
| AttributionCalculator | 90%+ |
| VisitorTracker | 85%+ |
| TouchRecorder | 85%+ |
| HandoffTracker | 85%+ |
| CompletionReceiver | 80%+ |

## Troubleshooting

### "Class not found" errors

Run `composer dump-autoload` to regenerate the autoloader.

### WordPress function errors

Make sure you're extending `ISF\Tests\Unit\TestCase` which sets up Brain Monkey.

### Database errors

Use `$this->mockWpdb()` to mock database operations in unit tests.

## Continuous Integration

The Unit, Property, and Regression suites are blocking CI gates. The Integration
suite remains advisory until its WordPress infrastructure is fully wired.

These tests can be run in CI/CD pipelines. Example GitHub Actions workflow:

```yaml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install
      - run: composer test
```
