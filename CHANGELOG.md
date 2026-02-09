# Changelog

All notable changes to the "View Builder" Joomla plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - TBA

### Added
- **Translation Editor**: Edit translation strings directly from the frontend. Just click on any text to verify its key and create an override.
- **Joomla 4+ Child Template Support**: The plugin now correctly recognizes and uses template overrides from parent templates when using Joomla's child template system.
- **Translation Map**: Automatically injects a map of used translation keys to the frontend for precise text identification.

### Changed
- Updated `ViewBuilderHelper::getOverridePath()` to check both child and parent template folders for existing overrides.
- Updated `ViewBuilderPlugin::getOverridePath()` with the same parent template fallback logic.
- Updated `FormBuilderHelper::getFormOverridePath()` to support form XML overrides in parent templates.
- Added new `ViewBuilderHelper::getParentTemplate()` method to detect child template parent from `templateDetails.xml`.
- Added `TextOverrideLoader` to intercept `Joomla\CMS\Language\Text` for translation handling.

## [1.2.1] - 2026-02-08

### Fixed
- **Autoloader Class Matching**: Fixed incorrect namespace escaping in `ViewOverrideLoader` and `FormFieldOverrideLoader` that prevented the custom class loaders from intercepting Joomla's core classes.
- **PHP Context-Aware Comments**: Fixed `OverrideInjector` to use PHP comments (`/* @block:... */`) when inside PHP code blocks instead of HTML comments (`<!-- @block:... -->`), which caused "unexpected token '<'" syntax errors in auto-generated overrides.

## [1.2.0] - 2026-02-02

### Added
- **Form Builder**: comprehensive tool to edit, reorder, and hide form fields directly from the frontend.
- **Form Overrides**: Automatically generates XML form overrides in `templates/[template]/html/[component]/forms/`.
- **Field Editing**: Intercepts `Joomla\CMS\Form\FormField` to wrap inputs with drag handles and edit buttons.
- **Delete Blocks**: Users can now completely remove blocks from views directly in On-Page mode.
- **Override Status UI**: Added a visual indicator bar for user-customized overrides in On-Page mode.
- **Reset Functionality**: New "Reset Override" button allows one-click restoration of the original view file (deleting the override).
- **Auto-Cleanup**: The system now automatically strips internal `@vb-auto-generated` markers when saving.

### Changed
- **UI Improvements**: Added specific styling for delete buttons, override checks, and form field wrappers.
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
