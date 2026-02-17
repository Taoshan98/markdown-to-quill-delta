# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### ⚠️ Breaking Changes
- **API Update**: Renamed `Converter::convert($markdown)` to `Converter::convertToArray($markdown)` to strictly indicate the return type.
- **Feature Removal**: Removed support for Horizontal Rules (Dividers) following decision to exclude custom blots from core support.

### Added
- **New Method**: Added `Converter::convertToJson($markdown)` helper for direct JSON string output.
- **Tests**: Comprehensive test suite refactoring. Added specific test files:
  - `HeaderTest`, `TextFormattingTest`, `CodeTest`, `BlockquoteTest`, `ListTest`
  - `LinkImageTest`, `NestedListTest`, `CheckboxTest`, `TableTest`
  - `ReferenceLinkTest`, `DefinitionListTest`, `AcademicTest`

### Fixed
- **Parser Logic**: Fixed parsing of nested links containing images (e.g., `[![Image](url)](link)`).
- **Text Formatting**: Resolved greedy boolean matching for mixed Bold/Italic syntax (e.g., `***` sequences now correctly parse as Bold+Italic).
- **Inline Precedence**: Fixed conflict where Subscript (`~`) could interfere with Strikethrough (`~~`) parsing.

### Changed
- **Refactoring**: Completely decomposed the monolithic `ConverterTest.php` into granular, maintainable unit tests (Total: 53 tests).
- **Parser**: Cleaned up `InlineParser` logic for better reliability.
