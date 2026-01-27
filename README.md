# Joomla View Builder System Plugin

A powerful system plugin for Joomla that allows developers and administrators to customize extension views directly from the website frontend (and backend) by automatically generating template overrides.

## 🌟 Features

- **In-Context Editing**: Edit view files directly where they are rendered.
- **Safe Overrides**: Automatically creates template overrides in `templates/[your_template]/html/`. Never modifies core files directly.
- **Syntax Validation**: Checks PHP syntax before saving to prevent "White Screen of Death" errors.
- **Access Control**: Restrict usage to specific user groups (default: Super Users).
- **Flexible Activation**: 
  - Frontend only
  - Frontend & Backend
  - Or via `tp=1` URL parameter (Template Preview mode)
- **Easy Revert**: One-click revert to delete the override and restore the original view.

## 🚀 Installation

1. Download the plugin package.
2. Install via the Joomla Extension Manager.
3. Enable the plugin `System - View Builder`.

## ⚙️ Configuration

Go to **System > Plugins > System - View Builder**.

| Option | Description |
| :--- | :--- |
| **Active** | Control where the builder is active (Disabled, Frontend, Frontend & Backend, or via `?tp=1`). |
| **Allowed Groups** | User groups permitted to use the tool (Default: Super Users). |
| **Excluded Components** | Comma-separated list of components to ignore. |

## 🛠 Usage

1. **Login** to the frontend (or backend) with a user in an allowed group.
2. Ensure the plugin is **Active**.
3. Navigate to the page you want to modify.
4. The View Builder overlay will appear, allowing you to select and edit view elements.
5. Make your changes in the code editor and hit **Save**. 
   - The plugin uses secure AJAX requests to handle file operations.
   - If a PHP syntax error is introduced, the system will prevent the save and alert you.

## 🏗 Architecture

The plugin utilizes a modern Joomla 5/6 architecture:

- **Autoloader Interception**: Uses `ViewOverrideLoader` to hook into Joomla's view loading mechanism.
- **Event Driven**: Subscribes to system events like `onBeforeCompileHead` for asset injection and `onAjaxViewbuilder` for handling API requests.
- **Web Asset Manager**: standard Joomla Web Asset Manager for loading CSS/JS resources.

### File Structure

- `src/Extension/ViewBuilderPlugin.php`: Main entry point handling logic and events.
- `src/Autoload/ViewOverrideLoader.php`: Mechanism to detect and load views.
- `src/Service/ViewParser.php`: Utilities for parsing view file structure.
- `viewbuilder.xml`: Manifest file defining the extension.

## ⚠️ Requirements

- Joomla 4.0+ / 5.x / 6.x
- PHP 7.4 or higher recommended

## 🥷 Author

Developed by **Hikari Software**.  
Visit us at [www.hikashop.com](https://www.hikashop.com).

## 📄 License

GNU General Public License version 2 or later.  
Copyright (C) 2026 Hikari Software. All rights reserved.

