<?php

namespace Joomla\CMS\MVC\View;

use Joomla\Plugin\System\ViewBuilder\Service\ViewBuilderHelper;

\defined('_JEXEC') or die;

class HtmlView extends ViewBuilderOriginalHtmlView
{
	public function loadTemplate($tpl = null)
	{
		if (!ViewBuilderHelper::shouldWrap()) {
			return parent::loadTemplate($tpl);
		}

		$isOnPage = (ViewBuilderHelper::getMode() === 'onpage');
		$viewName = $this->getName();
		$layout = $this->getLayout();
		$filename = $layout . (!empty($tpl) ? '_' . $tpl : '') . '.php';

		// In on-page mode, ensure delimited override exists BEFORE rendering
		if ($isOnPage) {
			$originalFile = $this->findOriginalTemplateFile($filename);
			if ($originalFile) {
				ViewBuilderHelper::ensureDelimitedOverride($originalFile);
			}
		}

		// Track nesting depth: increment before rendering (child templates will get depth+1)
		$depth = $isOnPage ? ViewBuilderHelper::incrementDepth() : 0;

		try {
			$result = parent::loadTemplate($tpl);
		} catch (\Throwable $e) {
			if ($isOnPage) {
				ViewBuilderHelper::decrementDepth();
			}
			throw $e;
		}

		if ($isOnPage) {
			ViewBuilderHelper::decrementDepth();
		}

		// If parent returned an Exception/Error (Joomla convention), pass it through unwrapped
		if ($result instanceof \Exception || $result instanceof \Throwable) {
			return $result;
		}

		// Resolve the template file that was actually rendered
		$templateFile = $this->findTemplateFile($filename);
		if (!$templateFile) {
			$templateFile = $this->_template;
		}

		$component = '';
		if (!empty($this->option)) {
			$component = $this->option;
		}

		if ($isOnPage) {
			return ViewBuilderHelper::wrapOnPage($result, $component, $viewName, $filename, $templateFile, $depth);
		}

		return ViewBuilderHelper::wrap($result, $component, $viewName, $filename, $templateFile);
	}

	protected function findTemplateFile($filename): ?string
	{
		if (!isset($this->_path['template']) || !is_array($this->_path['template'])) {
			return null;
		}

		foreach ($this->_path['template'] as $path) {
			$cleanPath = rtrim(\Joomla\Filesystem\Path::clean($path), '/\\');
			$file = $cleanPath . '/' . $filename;

			if (is_file($file)) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Find the original (non-override) template file.
	 * Skips paths that contain /html/ (template override directories).
	 */
	protected function findOriginalTemplateFile($filename): ?string
	{
		if (!isset($this->_path['template']) || !is_array($this->_path['template'])) {
			return null;
		}

		foreach ($this->_path['template'] as $path) {
			$cleanPath = rtrim(\Joomla\Filesystem\Path::clean($path), '/\\');
			$normalized = str_replace('\\', '/', $cleanPath);

			// Skip override directories
			if (strpos($normalized, '/html/') !== false) {
				continue;
			}

			$file = $cleanPath . '/' . $filename;
			if (is_file($file)) {
				return $file;
			}
		}

		return null;
	}
}
