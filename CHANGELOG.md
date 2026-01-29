# Changelog

All notable changes to the "View Builder" Joomla plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - TBA

### Added
- **Delete Blocks**: Users can now completely remove blocks from the view directly in On-Page mode.
- **Override Status UI**: Added a visual indicator bar for user-customized overrides in On-Page mode.
- **Reset Functionality**: New "Reset Override" button allows one-click restoration of the original view file (deleting the override).
- **Auto-Cleanup**: The system now automatically strips internal `@vb-auto-generated` markers when saving, converting temporary auto-overrides into permanent user overrides.

### Changed
- **UI Improvements**: Added specific styling for delete buttons and override status bars.
- **Improved Detection**: Better logic to distinguish between auto-generated overrides and user-customized files.

## [1.1.1] - 2026-01-29

### Fixed
- **Localization**: Added full language support for JavaScript error messages and UI labels. Backend exceptions and error messages are now fully translatable.
- **Language Loading**: Ensured plugin language files are loaded explicitly during initialization.
- **Drag & Drop**: Fixed issues with the drag & drop mechanism in "On Page" mode ensuring smoother block reordering.
- **Styling**: Extensive CSS updates to improve the visual builder interface and highlight overlays.

## [1.1.0] - 2026-01-28

### Added
- **On-Page Editing Mode**: Introduced a new default interaction mode where movable blocks are highlighted directly on the page with drag handles and edit buttons.
- **Auto-Delimited Overrides**: The plugin now automatically detects logic blocks and injects `@block` delimiters into overrides when editing on-page.
- **Smart Dependency Analysis**: Implemented logic to detect variable definitions and usages within blocks. The system now warns if moving a block might break variable scope (e.g. moving a usage before its definition).
- **New AJAX Tasks**: Added `load_block`, `save_block`, `move_block`, and `check_reorder` handlers to support granular operations.
- **Configuration**: Added a "Mode" setting to switch between "On Page" (default) and "Popup" styles.

### Changed
- Refactored `HtmlView` to support recursive nesting depth tracking.
- Updated `HtmlView` to ensure delimited overrides exist before rendering in on-page mode.
- Improved `findOriginalTemplateFile` to correctly skip existing overrides and find the true source.
- Updated README with detailed usage instructions for both modes.

## [1.0.0] - 2026-01-27

### Added
- Initial release.
- In-Context Editing with "Popup" mode.
- Safe template override generation.
- PHP syntax validation.
- Visual Builder (Popup mode).
