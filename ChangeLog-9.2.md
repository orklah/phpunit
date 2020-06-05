# Changes in PHPUnit 9.2

All notable changes of the PHPUnit 9.2 release series are documented in this file using the [Keep a CHANGELOG](https://keepachangelog.com/) principles.

## [9.2.1] - 2020-MM-DD

### Fixed

* [#4269](https://github.com/sebastianbergmann/phpunit/issues/4269): Test with `@coversNothing` annotation is wrongly marked as risky with `forceCoversAnnotation="true"`

## [9.2.0] - 2020-06-05

### Added

* [#4224](https://github.com/sebastianbergmann/phpunit/issues/4224): Support for Union Types for test double code generation

### Changed

* [#4246](https://github.com/sebastianbergmann/phpunit/issues/4246): Tests that are supposed to have a `@covers` annotation are now marked as risky even if code coverage is not collected
* [#4258](https://github.com/sebastianbergmann/phpunit/pull/4258): Prevent unpredictable result by raising an exception when multiple matchers can be applied to a test double invocation
* The test runner no longer relies on `$_SERVER['REQUEST_TIME_FLOAT']` for printing the elapsed time

[9.2.1]: https://github.com/sebastianbergmann/phpunit/compare/9.2.0...9.2
[9.2.0]: https://github.com/sebastianbergmann/phpunit/compare/9.1.5...9.2.0