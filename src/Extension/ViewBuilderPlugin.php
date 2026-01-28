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
			case 'load_block':
				$result = $this->handleLoadBlock($input);
				break;
			case 'save_block':
				$result = $this->handleSaveBlock($input);
				break;
			case 'move_block':
				$result = $this->handleMoveBlock($input);
				break;
			case 'check_reorder':
				$result = $this->handleCheckReorder($input);
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

	private function handleLoadBlock($input): string
	{
		$filePath = $this->resolveFilePath($input->getString('file', ''));
		$blockName = $input->getString('block', '');
		$this->validateFilePath($filePath);

		$overridePath = $this->getOverridePath($filePath);
		$actualFile = ($overridePath && file_exists($overridePath)) ? $overridePath : $filePath;

		$content = file_get_contents($actualFile);
		$blockContent = $this->extractBlockContent($content, $blockName);

		if ($blockContent === null) {
			return json_encode([
				'success' => false,
				'message' => 'Block "' . $blockName . '" not found in file.',
			]);
		}

		return json_encode([
			'success' => true,
			'content' => $blockContent,
			'block'   => $blockName,
			'file'    => $actualFile,
		]);
	}

	private function handleSaveBlock($input): string
	{
		$filePath = $this->resolveFilePath($input->getString('file', ''));
		$blockName = $input->getString('block', '');
		$newContent = $input->getRaw('content', '');
		$this->validateFilePath($filePath);

		$overridePath = $this->getOverridePath($filePath);
		if (!$overridePath || !file_exists($overridePath)) {
			return json_encode([
				'success' => false,
				'message' => 'Override file not found.',
			]);
		}

		$fileContent = file_get_contents($overridePath);
		$pattern = '/(<!--\s*@block:' . preg_quote($blockName, '/') . '\s*-->)(.*?)(<!--\s*@endblock:' . preg_quote($blockName, '/') . '\s*-->)/s';

		if (!preg_match($pattern, $fileContent)) {
			return json_encode([
				'success' => false,
				'message' => 'Block "' . $blockName . '" not found in override file.',
			]);
		}

		$updatedContent = preg_replace($pattern, '${1}' . "\n" . $newContent . "\n" . '${3}', $fileContent);

		$syntaxError = $this->checkPhpSyntax($updatedContent);
		if ($syntaxError) {
			return json_encode([
				'success'      => false,
				'syntax_error' => true,
				'message'      => $syntaxError,
			]);
		}

		file_put_contents($overridePath, $updatedContent);

		return json_encode([
			'success'  => true,
			'saved_to' => $overridePath,
		]);
	}

	private function handleMoveBlock($input): string
	{
		$filePath = $this->resolveFilePath($input->getString('file', ''));
		$blockName = $input->getString('block', '');
		$afterBlock = $input->getString('after', '');
		$force = $input->getInt('force', 0);
		$this->validateFilePath($filePath);

		// Read from the override if it exists, otherwise from the original
		$overridePath = $this->getOverridePath($filePath);
		if ($overridePath && file_exists($overridePath)) {
			$readPath = $overridePath;
		} elseif (file_exists($filePath)) {
			$readPath = $filePath;
		} else {
			return json_encode([
				'success' => false,
				'message' => 'File not found.',
			]);
		}

		// Always save to the override path
		$savePath = $overridePath ?: $filePath;

		$content = file_get_contents($readPath);

		// Detect delimiter style: @block or HikaShop (<!-- NAME --> / <!-- EO NAME -->)
		$hikaStyle = (strpos($content, '<!-- @block:') === false);
		if ($hikaStyle) {
			$hikaName = str_replace('_', ' ', $blockName);
			$hikaAfter = !empty($afterBlock) ? str_replace('_', ' ', $afterBlock) : '';
		}

		// Check for variable dependency issues (unless force=1)
		if (!$force) {
			$warnings = $this->checkMoveDependencies($content, $blockName, $afterBlock);
			if ($warnings) {
				return json_encode([
					'success'            => false,
					'dependency_warning' => true,
					'warnings'           => $warnings,
				]);
			}
		}

		// Extract the block being moved (including its delimiters)
		if ($hikaStyle) {
			$blockPattern = '/(<!--\s+' . preg_quote($hikaName, '/') . '\s+-->.*?<!--\s+EO\s+' . preg_quote($hikaName, '/') . '\s+-->)/s';
		} else {
			$blockPattern = '/(<!--\s*@block:' . preg_quote($blockName, '/') . '\s*-->.*?<!--\s*@endblock:' . preg_quote($blockName, '/') . '\s*-->)/s';
		}
		if (!preg_match($blockPattern, $content, $m)) {
			return json_encode([
				'success' => false,
				'message' => 'Block "' . $blockName . '" not found.',
			]);
		}

		$movedBlock = $m[1];

		// Remove the block from its current position (and any surrounding newline)
		$contentWithout = preg_replace('/\n?' . preg_quote($movedBlock, '/') . '\n?/s', "\n", $content, 1);

		if (empty($afterBlock)) {
			// Move to the beginning — insert before the first block delimiter
			if ($hikaStyle) {
				$firstPattern = '/(<!--\s+[A-Z][A-Z0-9 _]+?\s+-->)/s';
			} else {
				$firstPattern = '/(<!--\s*@block:\S+\s*-->)/s';
			}
			if (preg_match($firstPattern, $contentWithout, $firstMatch, PREG_OFFSET_CAPTURE)) {
				$pos = $firstMatch[0][1];
				$newContent = substr($contentWithout, 0, $pos) . $movedBlock . "\n" . substr($contentWithout, $pos);
			} else {
				$newContent = $contentWithout;
			}
		} else {
			// Insert after the specified block's end delimiter
			if ($hikaStyle) {
				$afterPattern = '/(<!--\s+EO\s+' . preg_quote($hikaAfter, '/') . '\s+-->)/s';
			} else {
				$afterPattern = '/(<!--\s*@endblock:' . preg_quote($afterBlock, '/') . '\s*-->)/s';
			}
			if (preg_match($afterPattern, $contentWithout, $afterMatch, PREG_OFFSET_CAPTURE)) {
				$pos = $afterMatch[0][1] + strlen($afterMatch[0][0]);
				$newContent = substr($contentWithout, 0, $pos) . "\n" . $movedBlock . substr($contentWithout, $pos);
			} else {
				return json_encode([
					'success' => false,
					'message' => 'Target block "' . $afterBlock . '" not found.',
				]);
			}
		}

		$syntaxError = $this->checkPhpSyntax($newContent);
		if ($syntaxError) {
			return json_encode([
				'success'      => false,
				'syntax_error' => true,
				'message'      => $syntaxError,
			]);
		}

		$dir = \dirname($savePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($savePath, $newContent);

		return json_encode([
			'success'  => true,
			'saved_to' => $savePath,
		]);
	}

	/**
	 * Handle check_reorder AJAX task - checks for variable dependencies before reordering in builder mode.
	 */
	private function handleCheckReorder($input): string
	{
		$filePath = $this->resolveFilePath($input->getString('file', ''));
		$this->validateFilePath($filePath);

		// Get original and new order arrays
		$originalOrder = $input->get('original_order', [], 'array');
		$newOrder = $input->get('new_order', [], 'array');

		if (empty($originalOrder) || empty($newOrder)) {
			return json_encode([
				'success' => false,
				'message' => 'Missing order data.',
			]);
		}

		// Read the file (could be original or override)
		$overridePath = $this->getOverridePath($filePath);
		if ($overridePath && file_exists($overridePath)) {
			$content = file_get_contents($overridePath);
		} elseif (file_exists($filePath)) {
			$content = file_get_contents($filePath);
		} else {
			return json_encode([
				'success' => false,
				'message' => 'File not found.',
			]);
		}

		$warnings = $this->checkReorderDependencies($content, $originalOrder, $newOrder);

		if ($warnings) {
			return json_encode([
				'success'            => false,
				'dependency_warning' => true,
				'warnings'           => $warnings,
			]);
		}

		return json_encode(['success' => true]);
	}

	/**
	 * Extract the content between @block and @endblock delimiters for a given block name.
	 */
	private function extractBlockContent(string $fileContent, string $blockName): ?string
	{
		$pattern = '/<!--\s*@block:' . preg_quote($blockName, '/') . '\s*-->\n?(.*?)\n?<!--\s*@endblock:' . preg_quote($blockName, '/') . '\s*-->/s';

		if (preg_match($pattern, $fileContent, $m)) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Extract variable names that are DEFINED (assigned) in the given code.
	 * Detects: $var =, $var .=, $var +=, foreach (... as $var), list($var) =, [$var] =
	 *
	 * @return array Variable names without the $ prefix
	 */
	private function extractVariableDefinitions(string $code): array
	{
		$vars = [];

		// Match: $var = (but not ==, ===, =>, <=, >=, !=)
		// Also match compound assignments: .= += -= *= /= %= &= |= ^= <<= >>= ??=
		if (preg_match_all('/\$([a-zA-Z_]\w*)\s*(?:[.+\-*\/\%&|^]|<<|>>|\?\?)?=[^=>]/', $code, $m)) {
			$vars = array_merge($vars, $m[1]);
		}

		// Match: foreach (... as $key => $value) or foreach (... as $value)
		if (preg_match_all('/\bas\s+\$([a-zA-Z_]\w*)(?:\s*=>\s*\$([a-zA-Z_]\w*))?/', $code, $m)) {
			$vars = array_merge($vars, array_filter($m[1]));
			$vars = array_merge($vars, array_filter($m[2]));
		}

		// Match: list($a, $b, $c) = or [$a, $b, $c] =
		if (preg_match_all('/(?:list\s*\(|\[)\s*((?:\$[a-zA-Z_]\w*[\s,]*)+)\s*(?:\)|\])\s*=/', $code, $m)) {
			foreach ($m[1] as $listVars) {
				if (preg_match_all('/\$([a-zA-Z_]\w*)/', $listVars, $listMatches)) {
					$vars = array_merge($vars, $listMatches[1]);
				}
			}
		}

		return array_unique($vars);
	}

	/**
	 * Extract all variable names that are USED (referenced) in the given code.
	 * Excludes $this and superglobals.
	 *
	 * @return array Variable names without the $ prefix
	 */
	private function extractVariableUsages(string $code): array
	{
		$excluded = [
			'this',
			'_GET', '_POST', '_REQUEST', '_SESSION', '_COOKIE', '_SERVER', '_FILES', '_ENV', 'GLOBALS'
		];

		$vars = [];
		if (preg_match_all('/\$([a-zA-Z_]\w*)/', $code, $m)) {
			foreach ($m[1] as $var) {
				if (!in_array($var, $excluded, true)) {
					$vars[] = $var;
				}
			}
		}

		return array_unique($vars);
	}

	/**
	 * Extract all blocks from the file content with their names and content.
	 *
	 * @return array Array of ['name' => string, 'content' => string] in document order
	 */
	private function extractAllBlocks(string $content): array
	{
		$blocks = [];

		// Try @block/@endblock style first
		$pattern = '/<!--\s*@block:(\S+)\s*-->(.*?)<!--\s*@endblock:\1\s*-->/s';
		if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$blocks[] = [
					'name'    => $m[1],
					'content' => $m[2],
				];
			}
			return $blocks;
		}

		// Fall back to HikaShop-style <!-- NAME --> ... <!-- EO NAME -->
		$hikaPattern = '/<!--\s+([A-Z][A-Z0-9 _]+?)\s+-->(.*?)<!--\s+EO\s+\1\s+-->/s';
		if (preg_match_all($hikaPattern, $content, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$blocks[] = [
					'name'    => str_replace(' ', '_', trim($m[1])),
					'content' => $m[2],
				];
			}
		}

		return $blocks;
	}

	/**
	 * Check for variable dependency issues when moving a single block.
	 * Used by on-page mode's move_block operation.
	 *
	 * @param string $content    The file content
	 * @param string $blockName  The block being moved
	 * @param string $afterBlock The target block name (empty = move to beginning)
	 *
	 * @return array|null Array of warnings or null if no issues
	 */
	private function checkMoveDependencies(string $content, string $blockName, string $afterBlock): ?array
	{
		$blocks = $this->extractAllBlocks($content);
		if (count($blocks) < 2) {
			return null;
		}

		// Find indices
		$oldIndex = -1;
		$newIndex = -1; // -1 means "before all blocks" (when $afterBlock is empty)

		foreach ($blocks as $i => $block) {
			if ($block['name'] === $blockName) {
				$oldIndex = $i;
			}
			if ($block['name'] === $afterBlock) {
				$newIndex = $i;
			}
		}

		if ($oldIndex === -1) {
			return null; // Block not found
		}

		// If afterBlock is empty, newIndex should be -1 (move to beginning)
		// After the move, the block will be at position 0
		$targetPosition = empty($afterBlock) ? 0 : $newIndex + 1;

		// No actual movement?
		if ($oldIndex === $targetPosition || ($oldIndex === $targetPosition - 1 && !empty($afterBlock))) {
			return null;
		}

		$movedBlockContent = $blocks[$oldIndex]['content'];
		$movedDefs = $this->extractVariableDefinitions($movedBlockContent);
		$movedUsages = $this->extractVariableUsages($movedBlockContent);

		$warnings = [];

		if ($oldIndex < $targetPosition) {
			// Moving DOWN: check blocks between old position and new position
			// These blocks will now run BEFORE the moved block
			// Issue: they may USE variables that the moved block DEFINES
			for ($i = $oldIndex + 1; $i <= $targetPosition && $i < count($blocks); $i++) {
				if ($i === $oldIndex) continue;
				$otherContent = $blocks[$i]['content'];
				$otherUsages = $this->extractVariableUsages($otherContent);
				$conflicts = array_intersect($movedDefs, $otherUsages);
				if (!empty($conflicts)) {
					$warnings[] = [
						'block'     => $blocks[$i]['name'],
						'variables' => array_values($conflicts),
						'direction' => 'loses_definitions',
					];
				}
			}
		} else {
			// Moving UP: check blocks between new position and old position
			// These blocks will now run AFTER the moved block
			// Issue: the moved block may USE variables that these blocks DEFINE
			$startCheck = empty($afterBlock) ? 0 : $newIndex + 1;
			for ($i = $startCheck; $i < $oldIndex; $i++) {
				$otherContent = $blocks[$i]['content'];
				$otherDefs = $this->extractVariableDefinitions($otherContent);
				$conflicts = array_intersect($movedUsages, $otherDefs);
				if (!empty($conflicts)) {
					$warnings[] = [
						'block'     => $blocks[$i]['name'],
						'variables' => array_values($conflicts),
						'direction' => 'provides_definitions',
					];
				}
			}
		}

		return empty($warnings) ? null : $warnings;
	}

	/**
	 * Check for variable dependency issues when reordering multiple blocks.
	 * Used by builder mode's check_reorder operation.
	 *
	 * @param string $content       The file content
	 * @param array  $originalOrder Array of block names in original order
	 * @param array  $newOrder      Array of block names in new order
	 *
	 * @return array|null Array of warnings or null if no issues
	 */
	private function checkReorderDependencies(string $content, array $originalOrder, array $newOrder): ?array
	{
		$blocks = $this->extractAllBlocks($content);
		if (count($blocks) < 2) {
			return null;
		}

		// Build a map of block name => content and variable info
		$blockData = [];
		foreach ($blocks as $block) {
			$blockData[$block['name']] = [
				'content' => $block['content'],
				'defs'    => $this->extractVariableDefinitions($block['content']),
				'usages'  => $this->extractVariableUsages($block['content']),
			];
		}

		// Build position maps
		$oldPos = array_flip($originalOrder);
		$newPos = array_flip($newOrder);

		$warnings = [];

		foreach ($newOrder as $newIdx => $blockName) {
			if (!isset($blockData[$blockName]) || !isset($oldPos[$blockName])) {
				continue;
			}

			$oldIdx = $oldPos[$blockName];
			$data = $blockData[$blockName];

			if ($newIdx > $oldIdx) {
				// Block moved DOWN: check if blocks now before it used its definitions
				foreach ($newOrder as $checkIdx => $checkName) {
					if ($checkIdx >= $newIdx) break;
					if (!isset($blockData[$checkName]) || !isset($oldPos[$checkName])) continue;

					$checkOldIdx = $oldPos[$checkName];
					// Only check blocks that were originally AFTER this block (now before)
					if ($checkOldIdx > $oldIdx) {
						$checkUsages = $blockData[$checkName]['usages'];
						$conflicts = array_intersect($data['defs'], $checkUsages);
						if (!empty($conflicts)) {
							$warnings[] = [
								'block'     => $checkName,
								'variables' => array_values($conflicts),
								'direction' => 'loses_definitions',
							];
						}
					}
				}
			} elseif ($newIdx < $oldIdx) {
				// Block moved UP: check if it uses definitions from blocks now after it
				foreach ($newOrder as $checkIdx => $checkName) {
					if ($checkIdx <= $newIdx) continue;
					if (!isset($blockData[$checkName]) || !isset($oldPos[$checkName])) continue;

					$checkOldIdx = $oldPos[$checkName];
					// Only check blocks that were originally BEFORE this block (now after)
					if ($checkOldIdx < $oldIdx) {
						$checkDefs = $blockData[$checkName]['defs'];
						$conflicts = array_intersect($data['usages'], $checkDefs);
						if (!empty($conflicts)) {
							$warnings[] = [
								'block'     => $blockName,
								'variables' => array_values($conflicts),
								'direction' => 'provides_definitions',
							];
						}
					}
				}
			}
		}

		// Remove duplicates (same block+variables can appear multiple times)
		$unique = [];
		foreach ($warnings as $w) {
			$key = $w['block'] . '|' . implode(',', $w['variables']) . '|' . $w['direction'];
			if (!isset($unique[$key])) {
				$unique[$key] = $w;
			}
		}

		return empty($unique) ? null : array_values($unique);
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
