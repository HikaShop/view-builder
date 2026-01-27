<?php

namespace Joomla\CMS\MVC\View;

use Joomla\Plugin\System\ViewBuilder\Service\ViewBuilderHelper;

\defined('_JEXEC') or die;

class HtmlView extends ViewBuilderOriginalHtmlView
{
	public function loadTemplate($tpl = null)
	{
		$viewName = $this->getName();
		$layout = $this->getLayout();

		$result = parent::loadTemplate($tpl);

		if (!ViewBuilderHelper::shouldWrap()) {
			return $result;
		}

		$filename = $layout . (!empty($tpl) ? '_' . $tpl : '') . '.php';
		
		// Use local path resolution because parent::loadTemplate() modifies $this->_template
		// which causes incorrect paths when nested views are loaded (recursive calls).
		$templateFile = $this->findTemplateFile($filename);

		if (!$templateFile) {
			$templateFile = $this->_template;
		}

		// Determine the component context
		$component = '';
		if (!empty($this->option)) {
			$component = $this->option;
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
}
