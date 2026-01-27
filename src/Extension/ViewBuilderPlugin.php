<?php

namespace Joomla\Plugin\System\ViewBuilder\Extension;

use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\ViewBuilder\Autoload\ViewOverrideLoader;
use Joomla\Plugin\System\ViewBuilder\Service\ViewBuilderHelper;
use Joomla\Plugin\System\ViewBuilder\Service\ViewParser;

\defined('_JEXEC') or die;

final class ViewBuilderPlugin extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterInitialise' => 'onAfterInitialise',
			'onBeforeCompileHead' => 'onBeforeCompileHead',
			'onAjaxViewbuilder' => 'onAjaxViewbuilder',
		];
	}

	public function onAfterInitialise(): void
	{
		if (!$this->isActive()) {
			return;
		}

		ViewBuilderHelper::init($this->params);

		$pluginPath = \dirname(__DIR__, 2);
		$loader = new ViewOverrideLoader(
			JPATH_LIBRARIES . '/src/MVC/View/HtmlView.php',
			$pluginPath . '/cache/OriginalHtmlView.php',
			\dirname(__DIR__) . '/View/HtmlView.php'
		);

		\spl_autoload_register([$loader, 'loadClass'], true, true);
	}

	public function onBeforeCompileHead(BeforeCompileHeadEvent $event): void
	{
		if (!$this->isActive()) {
			return;
		}

		$app = $this->getApplication();
		$doc = $app->getDocument();

		if ($doc->getType() !== 'html') {
			return;
		}

		/** @var \Joomla\CMS\WebAsset\WebAssetManager $wa */
		$wa = $doc->getWebAssetManager();
		$wa->registerAndUseStyle('plg_system_viewbuilder.wrapper', 'media/plg_system_viewbuilder/css/wrapper.css');
		$wa->registerAndUseScript('plg_system_viewbuilder.wrapper', 'media/plg_system_viewbuilder/js/wrapper.js', [], ['defer' => true]);
	}

	public function onAjaxViewbuilder(AjaxEvent $event): void
	{
		$app = $this->getApplication();
		$user = $app->getIdentity();

		if (!$user || $user->guest) {
			throw new \RuntimeException('Access denied', 403);
		}

		if (!$this->isUserAllowed($user)) {
			throw new \RuntimeException('Access denied', 403);
		}

		Session::checkToken('get') || Session::checkToken() || die('Invalid token');

		$input = $app->getInput();
		$task = $input->getCmd('task', '');

		switch ($task) {
			case 'load':
				$result = $this->handleLoad($input);
				break;
			case 'save':
				$result = $this->handleSave($input);
				break;
			case 'parse':
				$result = $this->handleParse($input);
				break;
			case 'revert':
				$result = $this->handleRevert($input);
				break;
			default:
				throw new \RuntimeException('Unknown task', 400);
		}

		$event->addResult($result);
	}

	private function handleLoad($input): string
	{
		$filePath = $this->resolveFilePath($input->getString('file', ''));
		$this->validateFilePath($filePath);

		$overridePath = $this->getOverridePath($filePath);
		$isOverride = false;

		if ($overridePath && file_exists($overridePath)) {
			$content = file_get_contents($overridePath);
			$isOverride = true;
		} else {
			$content = file_get_contents($filePath);
		}

		return json_encode([
			'success'     => true,
			'content'     => $content,
			'file'        => $filePath,
			'is_override' => $isOverride,
			'override_path' => $overridePath,
		]);
	}

	private function handleSave($input): string
	{
		$filePath = $this->resolveFilePath($input->getString('file', ''));
		$content  = $input->getRaw('content', '');
		$this->validateFilePath($filePath);

		// Validate PHP syntax before saving
		$syntaxError = $this->checkPhpSyntax($content);
		if ($syntaxError) {
			return json_encode([
				'success'      => false,
				'syntax_error' => true,
				'message'      => $syntaxError,
			]);
		}

		$overridePath = $this->getOverridePath($filePath);
		if (!$overridePath) {
			throw new \RuntimeException('Cannot determine override path', 500);
		}

		$dir = \dirname($overridePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($overridePath, $content);

		return json_encode([
			'success'       => true,
			'saved_to'      => $overridePath,
			'is_override'   => true,
		]);
	}

	private function checkPhpSyntax(string $content): ?string
	{
		$tmpFile = tempnam(sys_get_temp_dir(), 'vb_syntax_');
		file_put_contents($tmpFile, $content);

		$output = [];
		$exitCode = 0;
		if (function_exists('exec')) {
			exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
		}

		unlink($tmpFile);

		if ($exitCode !== 0) {
			$message = implode("\n", $output);
			// Remove the temp file path from the error message for clarity
			$message = str_replace($tmpFile, 'file', $message);
			// Extract just the error line
			if (preg_match('/Parse error:.*in file on line (\d+)/', $message, $m)) {
				return 'PHP syntax error on line ' . $m[1] . ': ' . $message;
			}
			return 'PHP syntax error: ' . $message;
		}

		return null;
	}

	private function handleParse($input): string
	{
		$filePath = $this->resolveFilePath($input->getString('file', ''));
		$this->validateFilePath($filePath);

		$overridePath = $this->getOverridePath($filePath);
		$actualFile = ($overridePath && file_exists($overridePath)) ? $overridePath : $filePath;

		$parser = new ViewParser();
		$structure = $parser->parse($actualFile);

		return json_encode([
			'success'     => true,
			'file'        => $actualFile,
			'is_override' => ($actualFile === $overridePath),
			'structure'   => $structure,
		]);
	}

	private function handleRevert($input): string
	{
		$filePath = $this->resolveFilePath($input->getString('file', ''));
		$this->validateFilePath($filePath);

		$overridePath = $this->getOverridePath($filePath);
		if ($overridePath && file_exists($overridePath)) {
			unlink($overridePath);

			// Clean up empty directories
			$dir = \dirname($overridePath);
			while ($dir !== JPATH_ROOT && is_dir($dir) && count(scandir($dir)) === 2) {
				rmdir($dir);
				$dir = \dirname($dir);
			}
		}

		return json_encode([
			'success' => true,
			'reverted' => true,
		]);
	}

	/**
	 * Convert a relative path (from the frontend) to an absolute path.
	 */
	private function resolveFilePath(string $filePath): string
	{
		// If already absolute, return as-is
		if (strpos($filePath, JPATH_ROOT) === 0) {
			return $filePath;
		}

		// Treat as relative to JPATH_ROOT
		return JPATH_ROOT . '/' . ltrim(str_replace('\\', '/', $filePath), '/');
	}

	private function validateFilePath(string $filePath): void
	{
		$realPath = realpath($filePath);
		$realRoot = realpath(JPATH_ROOT);

		if ($realPath === false || strpos($realPath, $realRoot) !== 0) {
			throw new \RuntimeException('Invalid file path', 400);
		}

		if (pathinfo($realPath, PATHINFO_EXTENSION) !== 'php') {
			throw new \RuntimeException('Only PHP files can be edited', 400);
		}

		// Must be in a tmpl directory or a template html override directory
		$normalized = str_replace('\\', '/', $realPath);
		$isTmpl = (strpos($normalized, '/tmpl/') !== false);
		$isOverride = (strpos($normalized, '/html/') !== false);

		if (!$isTmpl && !$isOverride) {
			throw new \RuntimeException('File must be in a tmpl or html override directory', 400);
		}
	}

	private function getOverridePath(string $filePath): ?string
	{
		$app = $this->getApplication();
		$template = $app->getTemplate();
		$filePath = str_replace('\\', '/', $filePath);
		$root = str_replace('\\', '/', JPATH_ROOT);

		// Already an override?
		if (strpos($filePath, '/templates/' . $template . '/html/') !== false) {
			return $filePath;
		}

		// Determine component and view from path
		// Pattern: .../components/com_xxx/tmpl/viewname/file.php (Joomla 5/6)
		// Or: .../components/com_xxx/views/viewname/tmpl/file.php (legacy)
		if (preg_match('#/components/(com_\w+)/tmpl/(\w+)/(.+\.php)$#', $filePath, $matches)) {
			$component = $matches[1];
			$viewName  = $matches[2];
			$fileName  = $matches[3];
		} elseif (preg_match('#/components/(com_\w+)/views?/(\w+)/tmpl/(.+\.php)$#', $filePath, $matches)) {
			$component = $matches[1];
			$viewName  = $matches[2];
			$fileName  = $matches[3];
		} elseif (preg_match('#/administrator/components/(com_\w+)/tmpl/(\w+)/(.+\.php)$#', $filePath, $matches)) {
			$component = $matches[1];
			$viewName  = $matches[2];
			$fileName  = $matches[3];
		} elseif (preg_match('#/modules/(mod_\w+)/tmpl/(.+\.php)$#', $filePath, $matches)) {
			$component = $matches[1];
			$viewName  = '';
			$fileName  = $matches[2];
		} else {
			return null;
		}

		$isAdmin = (strpos($filePath, '/administrator/') !== false);
		$themesPath = $isAdmin ? (JPATH_ADMINISTRATOR . '/templates') : (JPATH_SITE . '/templates');

		if ($viewName) {
			return $themesPath . '/' . $template . '/html/' . $component . '/' . $viewName . '/' . $fileName;
		}

		return $themesPath . '/' . $template . '/html/' . $component . '/' . $fileName;
	}

	private function isActive(): bool
	{
		$app = $this->getApplication();

		if (!$app->isClient('site') && !$app->isClient('administrator')) {
			return false;
		}

		$active = (int) $this->params->get('active', 0);

		if ($active === 0) {
			return false;
		}

		if ($active === 1 && !$app->isClient('site')) {
			return false;
		}

		if ($active === 3) {
			$tp = $app->getInput()->getInt('tp', 0);
			if ($tp !== 1) {
				return false;
			}
		}

		$user = $app->getIdentity();
		if (!$user || $user->guest) {
			return false;
		}

		return $this->isUserAllowed($user);
	}

	private function isUserAllowed($user): bool
	{
		$allowedGroups = $this->params->get('allowed_groups', [8]);
		if (!is_array($allowedGroups)) {
			$allowedGroups = [$allowedGroups];
		}

		$userGroups = $user->getAuthorisedGroups();

		return !empty(array_intersect($allowedGroups, $userGroups));
	}
}
