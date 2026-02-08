<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ViewBuilder
 *
 * @copyright   (C) 2026 Hikari Software. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */


namespace Joomla\Plugin\System\ViewBuilder\Autoload;

\defined('_JEXEC') or die;

class FormFieldOverrideLoader
{
	private string $originalPath;
	private string $cachePath;
	private string $replacementPath;

	public function __construct(string $originalPath, string $cachePath, string $replacementPath)
	{
		$this->originalPath = $originalPath;
		$this->cachePath = $cachePath;
		$this->replacementPath = $replacementPath;
	}

	public function loadClass(string $className): bool
	{
		if ($className !== 'Joomla\CMS\Form\FormField') {
			return false;
		}

		$this->ensureCachedOriginal();

		require_once $this->cachePath;
		require_once $this->replacementPath;

		return true;
	}

	private function ensureCachedOriginal(): void
	{
		$needsRebuild = false;

		if (!file_exists($this->cachePath)) {
			$needsRebuild = true;
		} else {
			$originalMtime = filemtime($this->originalPath);
			$cacheMtime = filemtime($this->cachePath);
			if ($originalMtime > $cacheMtime) {
				$needsRebuild = true;
			}
		}

		if (!$needsRebuild) {
			return;
		}

		$code = file_get_contents($this->originalPath);

		// Rename the class from FormField to ViewBuilderOriginalFormField
		// Keep the same namespace so it remains in Joomla\CMS\Form
		$code = str_replace(
			'abstract class FormField implements DatabaseAwareInterface, CurrentUserInterface',
			'abstract class ViewBuilderOriginalFormField implements DatabaseAwareInterface, CurrentUserInterface',
			$code
		);

		$cacheDir = \dirname($this->cachePath);
		if (!is_dir($cacheDir)) {
			mkdir($cacheDir, 0755, true);
		}

		file_put_contents($this->cachePath, $code);
	}
}
