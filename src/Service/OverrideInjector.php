<?php

namespace Joomla\Plugin\System\ViewBuilder\Service;

\defined('_JEXEC') or die;

/**
 * Injects @block/@endblock delimiters into view files for on-page mode.
 */
class OverrideInjector
{
	/**
	 * Ensure the override file exists and has @block/@endblock delimiters.
	 *
	 * 1. If override exists and has delimiters: do nothing.
	 * 2. If override exists without delimiters: rename to .php_old, parse _old, inject delimiters, write new override.
	 * 3. If no override: parse original, inject delimiters, write override.
	 */
	public function ensure(string $originalFile, string $overridePath): void
	{
		if (file_exists($overridePath)) {
			$content = file_get_contents($overridePath);
			if (strpos($content, '<!-- @block:') !== false) {
				return; // Already has delimiters
			}

			// Override exists but has no delimiters — rename and re-inject
			$oldPath = preg_replace('/\.php$/', '_old.php', $overridePath);
			rename($overridePath, $oldPath);
			$sourceFile = $oldPath;
		} else {
			$sourceFile = $originalFile;
		}

		if (!file_exists($sourceFile)) {
			return;
		}

		$content = file_get_contents($sourceFile);
		$injected = $this->injectDelimiters($content);

		// If nothing was injected (no movable blocks found), don't create the override
		if ($injected === $content) {
			// Restore the old file if we renamed it
			if (isset($oldPath) && file_exists($oldPath)) {
				rename($oldPath, $overridePath);
			}
			return;
		}

		$dir = \dirname($overridePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($overridePath, $injected);
	}

	/**
	 * Parse the file content and inject @block/@endblock delimiters around movable blocks.
	 */
	private function injectDelimiters(string $content): string
	{
		$lines = explode("\n", $content);

		$parser = new ViewParser();
		// Use autoDetect path — parse the content as a temp file
		$tmpFile = tempnam(sys_get_temp_dir(), 'vb_inject_');
		file_put_contents($tmpFile, $content);
		$structure = $parser->parse($tmpFile);
		unlink($tmpFile);

		$blocks = $structure['blocks'] ?? [];

		// Only keep movable blocks
		$movable = [];
		foreach ($blocks as $block) {
			if (!empty($block['movable'])) {
				$movable[] = $block;
			}
		}

		if (empty($movable)) {
			return $content;
		}

		// Sort by line_start descending so insertions don't shift earlier line numbers
		usort($movable, function ($a, $b) {
			return $b['line_start'] - $a['line_start'];
		});

		// Ensure unique block names (append _N suffix for duplicates)
		$nameCount = [];
		// First pass: count occurrences (in original order)
		$movableAsc = array_reverse($movable);
		$nameMap = [];
		foreach ($movableAsc as $idx => $block) {
			$baseName = $this->sanitizeBlockName($block['name']);
			if (!isset($nameCount[$baseName])) {
				$nameCount[$baseName] = 0;
			}
			$nameCount[$baseName]++;
			$nameMap[$idx] = $baseName;
		}
		// Second pass: assign unique names
		$usedNames = [];
		foreach ($movableAsc as $idx => $block) {
			$baseName = $nameMap[$idx];
			if ($nameCount[$baseName] > 1) {
				$suffix = 1;
				$uniqueName = $baseName . '_' . $suffix;
				while (isset($usedNames[$uniqueName])) {
					$suffix++;
					$uniqueName = $baseName . '_' . $suffix;
				}
				$movableAsc[$idx]['unique_name'] = $uniqueName;
			} else {
				$movableAsc[$idx]['unique_name'] = $baseName;
			}
			$usedNames[$movableAsc[$idx]['unique_name']] = true;
		}
		// Reverse back to descending order
		$movable = array_reverse($movableAsc);

		foreach ($movable as $block) {
			$name = $block['unique_name'];
			$startIdx = $block['line_start'] - 1;
			$endIdx = $block['line_end'] - 1;

			// Determine indentation from the first line of the block
			$indent = '';
			if (preg_match('/^(\s*)/', $lines[$startIdx], $m)) {
				$indent = $m[1];
			}

			// Insert endblock after the block's last line
			array_splice($lines, $endIdx + 1, 0, [$indent . '<!-- @endblock:' . $name . ' -->']);
			// Insert block before the block's first line
			array_splice($lines, $startIdx, 0, [$indent . '<!-- @block:' . $name . ' -->']);
		}

		return implode("\n", $lines);
	}

	/**
	 * Sanitize a block name for use in delimiters (no spaces, safe characters only).
	 */
	private function sanitizeBlockName(string $name): string
	{
		// Replace spaces and special chars with underscores
		$name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
		// Collapse multiple underscores
		$name = preg_replace('/_+/', '_', $name);
		return trim($name, '_');
	}
}
