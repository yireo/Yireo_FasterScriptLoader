# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.0.1] - 27 November 2026
### Added
- Rewrites `data-mage-init` attributes and `x-magento-init` scripts into direct RequireJS calls so page scripts load faster
- Support for PHP 8.5

### Removed
- Support for PHP 8.1

### Fixed
- Only real `data-mage-init` attributes and `x-magento-init` scripts get rewritten, because a proper HTML parser is now used instead of regular expressions. Invalid JSON is skipped instead of throwing an exception
- Component names that look like URLs are rejected, and configuration is JSON-encoded safely to prevent script injection
- Only HTML page responses are processed, before the built-in page cache stores them
- The loader script is rendered through the SecureHtmlRenderer (CSP-compatible) and no longer writes to the browser console
- Skipped components still initialize, because `mage/mage` is no longer replaced globally
- Output in the component script template is escaped
- `trim()` calls work with PHP 8.6, and missing module dependencies are now declared
