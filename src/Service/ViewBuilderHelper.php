<?php

namespace Joomla\Plugin\System\ViewBuilder\Service;

use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

class ViewBuilderHelper
{
	private static ?Registry $params = null;
	private static bool $initialized = false;
	private static int $wrapperId = 0;

	public static function init(Registry $params): void
	{
		self::$params = $params;
		self::$initialized = true;
	}

	public static function shouldWrap(): bool
	{
		return self::$initialized;
	}

	public static function wrap(string $output, string $component, string $viewName, string $filename, ?string $templateFile): string
	{
		if (empty($templateFile)) {
			return $output;
		}

		self::$wrapperId++;
		$id = 'vb-wrapper-' . self::$wrapperId;

		$label = trim($component . ' / ' . $viewName . ' / ' . $filename, ' /');

		// Only expose the path relative to the site root for security
		$relativePath = str_replace('\\', '/', $templateFile);
		$root = str_replace('\\', '/', JPATH_ROOT);
		if (strpos($relativePath, $root) === 0) {
			$relativePath = ltrim(substr($relativePath, strlen($root)), '/');
		}
		$escapedPath = htmlspecialchars($relativePath, ENT_QUOTES, 'UTF-8');
		$escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
		$token = Session::getFormToken();
		$ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=viewbuilder&group=system&format=json&' . $token . '=1';

		$isOverride = (strpos(str_replace('\\', '/', $templateFile), '/html/') !== false);
		$overrideClass = $isOverride ? ' vb-is-override' : '';

		return '<div id="' . $id . '" class="vb-wrapper' . $overrideClass . '" data-vb-file="' . $escapedPath . '">'
			. '<div class="vb-label">'
			. '<span class="vb-label-text">' . $escapedLabel . '</span>'
			. ($isOverride ? '<span class="vb-override-badge">Override</span>' : '')
			. '<span class="vb-label-actions">'
			. '<button type="button" class="vb-btn vb-btn-edit" data-vb-file="' . $escapedPath . '" data-vb-ajax="' . htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8') . '" title="Edit view file">Edit</button>'
			. '<button type="button" class="vb-btn vb-btn-builder" data-vb-file="' . $escapedPath . '" data-vb-ajax="' . htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8') . '" title="Open builder">Builder</button>'
			. '</span>'
			. '</div>'
			. '<div class="vb-content">' . $output . '</div>'
			. '</div>';
	}
}
