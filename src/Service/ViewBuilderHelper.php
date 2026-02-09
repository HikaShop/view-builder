<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ViewBuilder
 *
 * @copyright   (C) 2026 Hikari Software. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\System\ViewBuilder\Service;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

class ViewBuilderHelper
{
	private static ?Registry $params = null;
	private static bool $initialized = false;
	private static int $wrapperId = 0;
	private static string $mode = 'popup';
	private static int $onpageDepth = 0;

	public static function init(Registry $params): void
	{
		self::$params = $params;
		self::$mode = $params->get('mode', 'popup');
		self::$initialized = true;
	}

	public static function shouldWrap(): bool
	{
		return self::$initialized;
	}

	public static function getMode(): string
	{
		return self::$mode;
	}

	public static function incrementDepth(): int
	{
		return self::$onpageDepth++;
	}

	public static function decrementDepth(): void
	{
		self::$onpageDepth = max(0, self::$onpageDepth - 1);
	}

	public static function getRelativePath(string $absolutePath): string
	{
		$path = str_replace('\\', '/', $absolutePath);
		$root = str_replace('\\', '/', JPATH_ROOT);
		if (strpos($path, $root) === 0) {
			return ltrim(substr($path, strlen($root)), '/');
		}
		return $path;
	}

	public static function getOverridePath(string $originalFile): ?string
	{
		$app = Factory::getApplication();
		$template = $app->getTemplate();
		$filePath = str_replace('\\', '/', $originalFile);

		// Already an override?
		if (strpos($filePath, '/templates/' . $template . '/html/') !== false) {
			return $originalFile;
		}

		// For Joomla 4+ child templates, also check the parent template
		$parentTemplate = self::getParentTemplate();
		if (!empty($parentTemplate) && strpos($filePath, '/templates/' . $parentTemplate . '/html/') !== false) {
			return $originalFile;
		}

		if (preg_match('#/components/(com_\\w+)/tmpl/(\\w+)/(.+\\.php)$#', $filePath, $matches)) {
			$component = $matches[1]; $viewName = $matches[2]; $fileName = $matches[3];
		} elseif (preg_match('#/components/(com_\\w+)/views?/(\\w+)/tmpl/(.+\\.php)$#', $filePath, $matches)) {
			$component = $matches[1]; $viewName = $matches[2]; $fileName = $matches[3];
		} elseif (preg_match('#/administrator/components/(com_\\w+)/tmpl/(\\w+)/(.+\\.php)$#', $filePath, $matches)) {
			$component = $matches[1]; $viewName = $matches[2]; $fileName = $matches[3];
		} elseif (preg_match('#/modules/(mod_\\w+)/tmpl/(.+\\.php)$#', $filePath, $matches)) {
			$component = $matches[1]; $viewName = ''; $fileName = $matches[2];
		} else {
			return null;
		}

		$isAdmin = (strpos($filePath, '/administrator/') !== false);
		$themesPath = $isAdmin ? (JPATH_ADMINISTRATOR . '/templates') : (JPATH_SITE . '/templates');

		// Build the override path and check if it exists in child or parent template
		if ($viewName) {
			$childPath = $themesPath . '/' . $template . '/html/' . $component . '/' . $viewName . '/' . $fileName;
			// If override exists in child template, use it
			if (file_exists($childPath)) {
				return $childPath;
			}
			// Check parent template if available
			if (!empty($parentTemplate)) {
				$parentPath = $themesPath . '/' . $parentTemplate . '/html/' . $component . '/' . $viewName . '/' . $fileName;
				if (file_exists($parentPath)) {
					return $parentPath;
				}
			}
			// Return child path as target for new overrides
			return $childPath;
		}

		$childPath = $themesPath . '/' . $template . '/html/' . $component . '/' . $fileName;
		if (file_exists($childPath)) {
			return $childPath;
		}
		if (!empty($parentTemplate)) {
			$parentPath = $themesPath . '/' . $parentTemplate . '/html/' . $component . '/' . $fileName;
			if (file_exists($parentPath)) {
				return $parentPath;
			}
		}
		return $childPath;
	}

	/**
	 * Get the parent template name for Joomla 4+ child templates.
	 *
	 * @return string|null The parent template name, or null if not a child template
	 */
	public static function getParentTemplate(): ?string
	{
		static $parentTemplate = null;
		static $checked = false;

		if ($checked) {
			return $parentTemplate;
		}
		$checked = true;

		$app = Factory::getApplication();
		$template = $app->getTemplate();

		// Check if the template has a parent defined in templateDetails.xml
		$xmlPath = JPATH_THEMES . '/' . $template . '/templateDetails.xml';
		if (!file_exists($xmlPath)) {
			return null;
		}

		$xml = simplexml_load_file($xmlPath);
		if (!$xml) {
			return null;
		}

		// Joomla 4+ child templates have a <parent> element
		if (isset($xml->parent) && !empty((string) $xml->parent)) {
			$parentTemplate = (string) $xml->parent;
		}

		return $parentTemplate;
	}

	public static function wrap(string $output, string $component, string $viewName, string $filename, ?string $templateFile): string
	{
		if (empty($templateFile)) {
			return $output;
		}

		self::$wrapperId++;
		$id = 'vb-wrapper-' . self::$wrapperId;

		$label = trim($component . ' / ' . $viewName . ' / ' . $filename, ' /');

		$escapedPath = htmlspecialchars(self::getRelativePath($templateFile), ENT_QUOTES, 'UTF-8');
		$escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
		$token = Session::getFormToken();
		$ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=viewbuilder&group=system&format=json&' . $token . '=1';

		$isOverride = (strpos(str_replace('\\', '/', $templateFile), '/html/') !== false);
		$isUserOverride = $isOverride && !OverrideInjector::isAutoGenerated($templateFile);
		$overrideClass = $isUserOverride ? ' vb-is-override' : '';

		return '<div id="' . $id . '" class="vb-wrapper' . $overrideClass . '" data-vb-file="' . $escapedPath . '">'
			. '<div class="vb-label">'
			. '<span class="vb-label-text">' . $escapedLabel . '</span>'
			. ($isUserOverride ? '<span class="vb-override-badge">' . Text::_('PLG_SYSTEM_VIEWBUILDER_OVERRIDE') . '</span>' : '')
			. '<span class="vb-label-actions">'
			. '<button type="button" class="vb-btn vb-btn-edit" data-vb-file="' . $escapedPath . '" data-vb-ajax="' . htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8') . '" title="' . Text::_('PLG_SYSTEM_VIEWBUILDER_EDIT_VIEW_FILE') . '">' . Text::_('PLG_SYSTEM_VIEWBUILDER_EDIT') . '</button>'
			. '<button type="button" class="vb-btn vb-btn-builder" data-vb-file="' . $escapedPath . '" data-vb-ajax="' . htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8') . '" title="' . Text::_('PLG_SYSTEM_VIEWBUILDER_OPEN_BUILDER') . '">' . Text::_('PLG_SYSTEM_VIEWBUILDER_BUILDER') . '</button>'
			. '</span>'
			. '</div>'
			. '<div class="vb-content">' . $output . '</div>'
			. '</div>';
	}

	/**
	 * Ensure that a delimited override file exists for on-page mode.
	 * If no override exists, create one from the original with @block delimiters.
	 * If override exists without delimiters, rename to _old and recreate.
	 */
	public static function ensureDelimitedOverride(string $originalFile): void
	{
		// If the original file already has delimiter-based blocks, no override needed
		$parser = new ViewParser();
		$structure = $parser->parse($originalFile);
		if (!empty($structure['has_delimiters'])) {
			return;
		}

		$overridePath = self::getOverridePath($originalFile);
		if (!$overridePath) {
			return;
		}

		$injector = new OverrideInjector();
		$injector->ensure($originalFile, $overridePath);
	}

	/**
	 * Wrap rendered output for on-page mode.
	 * Finds @block/@endblock comment pairs in HTML and wraps each with drag/edit UI.
	 */
	public static function wrapOnPage(string $output, string $component, string $viewName, string $filename, ?string $templateFile, int $depth = 0): string
	{
		if (empty($templateFile)) {
			return $output;
		}

		$escapedPath = htmlspecialchars(self::getRelativePath($templateFile), ENT_QUOTES, 'UTF-8');
		$token = Session::getFormToken();
		$ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=viewbuilder&group=system&format=json&' . $token . '=1';
		$escapedAjax = htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8');

		// Count blocks: @block style + HikaShop style (<!-- NAME --> / <!-- EO NAME -->)
		$atBlockCount = preg_match_all('/<!--\s*@block:\S+\s*-->/', $output);
		$hikaBlockCount = preg_match_all('/<!--\s+([A-Z][A-Z0-9 _]+?)\s+-->/', $output, $hikaMatches);
		// HikaShop matches include both opening and EO closing — count only openers
		$hikaOpeners = 0;
		if ($hikaBlockCount) {
			foreach ($hikaMatches[1] as $hm) {
				if (strpos(trim($hm), 'EO ') !== 0) {
					$hikaOpeners++;
				}
			}
		}
		$totalBlocks = $atBlockCount + $hikaOpeners;

		if ($totalBlocks <= 1) {
			// Strip block comments so parent templates don't re-match them
			$output = preg_replace('/<!--\s*@(?:end)?block:\S+\s*-->/', '', $output);
			$output = preg_replace('/<!--\s+(?:EO\s+)?[A-Z][A-Z0-9 _]+?\s+-->/', '', $output);
			return $output;
		}

		$isOverride = (strpos(str_replace('\\', '/', $templateFile), '/html/') !== false);
		$dragTitle = Text::_('PLG_SYSTEM_VIEWBUILDER_DRAG_TO_REORDER');
		$editTitle = Text::_('PLG_SYSTEM_VIEWBUILDER_EDIT_BLOCK');
		$deleteTitle = Text::_('PLG_SYSTEM_VIEWBUILDER_DELETE_BLOCK');
		$wrapBlock = function ($name, $content) use ($escapedPath, $escapedAjax, $depth, $dragTitle, $editTitle, $deleteTitle) {
			$escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
			$label = htmlspecialchars(str_replace('_', ' ', $name), ENT_QUOTES, 'UTF-8');
			$deleteBtn = '<button type="button" class="vb-onpage-delete" data-vb-block="' . $escapedName . '" data-vb-file="' . $escapedPath . '" data-vb-ajax="' . $escapedAjax . '" title="' . $deleteTitle . '">&#x2715;</button>';
			return '<div class="vb-onpage-block vb-onpage-depth-' . $depth . '" data-vb-block="' . $escapedName . '" data-vb-label="' . $label . '" data-vb-file="' . $escapedPath . '" data-vb-ajax="' . $escapedAjax . '" data-vb-depth="' . $depth . '">'
				. '<div class="vb-onpage-handle vb-onpage-handle-' . $depth . '" title="' . $dragTitle . '">&#x2807;</div>'
				. $deleteBtn
				. '<button type="button" class="vb-onpage-edit" data-vb-block="' . $escapedName . '" data-vb-file="' . $escapedPath . '" data-vb-ajax="' . $escapedAjax . '" title="' . $editTitle . '">&#9998;</button>'
				. $content
				. '</div>';
		};

		// Match <!-- @block:NAME --> ... <!-- @endblock:NAME --> pairs
		$result = preg_replace_callback(
			'/<!--\s*@block:(\S+)\s*-->(.*?)<!--\s*@endblock:\1\s*-->/s',
			function ($m) use ($wrapBlock) {
				return $wrapBlock($m[1], $m[2]);
			},
			$output
		);

		// Match HikaShop-style <!-- NAME --> ... <!-- EO NAME --> pairs
		$result = preg_replace_callback(
			'/<!--\s+([A-Z][A-Z0-9 _]+?)\s+-->(.*?)<!--\s+EO\s+\1\s+-->/s',
			function ($m) use ($wrapBlock) {
				$name = str_replace(' ', '_', trim($m[1]));
				return $wrapBlock($name, $m[2]);
			},
			$result
		);

		// Wrap the entire view file output with an override indicator when customized
		// Skip auto-generated overrides (created by OverrideInjector for on-page mode)
		$isUserOverride = $isOverride && !OverrideInjector::isAutoGenerated($templateFile);
		if ($isUserOverride) {
			$label = trim($component . ' / ' . $viewName . ' / ' . $filename, ' /');
			$escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
			$resetTitle = Text::_('PLG_SYSTEM_VIEWBUILDER_RESET_OVERRIDE');
			$result = '<div class="vb-onpage-override vb-onpage-override-depth-' . $depth . '" data-vb-file="' . $escapedPath . '" data-vb-ajax="' . $escapedAjax . '">'
				. '<div class="vb-onpage-override-bar vb-onpage-override-bar-' . $depth . '">'
				. '<span class="vb-onpage-override-label">' . Text::_('PLG_SYSTEM_VIEWBUILDER_OVERRIDE') . ': ' . $escapedLabel . '</span>'
				. '<button type="button" class="vb-onpage-reset" data-vb-file="' . $escapedPath . '" data-vb-ajax="' . $escapedAjax . '" title="' . $resetTitle . '">&#x21A9; ' . $resetTitle . '</button>'
				. '</div>'
				. $result
				. '</div>';
		}

		return $result;
	}
}
