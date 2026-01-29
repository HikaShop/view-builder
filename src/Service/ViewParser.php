<?php

namespace Joomla\Plugin\System\ViewBuilder\Service;

\defined('_JEXEC') or die;

class ViewParser
{
	public function parse(string $filePath): array
	{
		if (!file_exists($filePath)) {
			return ['blocks' => [], 'groups' => []];
		}

		$content = file_get_contents($filePath);
		$lines = explode("\n", $content);

		// Try delimiter-based parsing first (HikaShop-style)
		$blocks = $this->parseDelimiters($lines);

		if (!empty($blocks)) {
			return [
				'has_delimiters' => true,
				'blocks' => $blocks,
				'groups' => $this->buildGroups($blocks),
			];
		}

		// Fall back to auto-detection
		$blocks = $this->autoDetect($lines);

		return [
			'has_delimiters' => false,
			'blocks' => $blocks,
			'groups' => $this->buildGroups($blocks),
		];
	}

	/**
	 * Parse HikaShop-style delimiters or @block/@endblock delimiters
	 */
	private function parseDelimiters(array $lines): array
	{
		$blocks = [];
		$openBlocks = [];

		foreach ($lines as $lineIdx => $line) {
			$lineNum = $lineIdx + 1;
			$trimmed = trim($line);

			// <!-- @block:NAME --> style
			if (preg_match('/^<!--\s*@block:(\S+)\s*-->/', $trimmed, $m)) {
				$openBlocks[] = ['name' => $m[1], 'line_start' => $lineNum, 'style' => 'at'];
				continue;
			}
			if (preg_match('/^<!--\s*@endblock:(\S+)\s*-->/', $trimmed, $m)) {
				for ($i = count($openBlocks) - 1; $i >= 0; $i--) {
					if ($openBlocks[$i]['name'] === $m[1] && $openBlocks[$i]['style'] === 'at') {
						$blocks[] = [
							'name'       => $openBlocks[$i]['name'],
							'type'       => 'delimiter',
							'line_start' => $openBlocks[$i]['line_start'],
							'line_end'   => $lineNum,
							'movable'    => true,
						];
						array_splice($openBlocks, $i, 1);
						break;
					}
				}
				continue;
			}

			// <!-- BLOCK_NAME --> / <!-- EO BLOCK_NAME --> style (HikaShop)
			if (preg_match('/^<!--\s+([A-Z][A-Z0-9 _]+?)\s+-->$/', $trimmed, $m)) {
				$name = trim($m[1]);
				if (strpos($name, 'EO ') === 0) {
					$blockName = substr($name, 3);
					for ($i = count($openBlocks) - 1; $i >= 0; $i--) {
						if ($openBlocks[$i]['name'] === $blockName && $openBlocks[$i]['style'] === 'hika') {
							$blocks[] = [
								'name'       => $openBlocks[$i]['name'],
								'type'       => 'delimiter',
								'line_start' => $openBlocks[$i]['line_start'],
								'line_end'   => $lineNum,
								'movable'    => true,
							];
							array_splice($openBlocks, $i, 1);
							break;
						}
					}
				} else {
					$openBlocks[] = ['name' => $name, 'line_start' => $lineNum, 'style' => 'hika'];
				}
			}
		}

		return $blocks;
	}

	/**
	 * Auto-detect blocks by scanning for common patterns.
	 * Only marks blocks as movable if they are self-contained
	 * (balanced PHP control structures and balanced HTML tags).
	 */
	private function autoDetect(array $lines): array
	{
		$blocks = [];

		// 1. Detect top-level conditional blocks with proper nesting
		$conditionals = $this->detectConditionalBlocks($lines);

		// 2. Detect standalone expression lines (loadTemplate, LayoutHelper, echo)
		$standalones = $this->detectStandaloneLines($lines);

		// Merge: conditionals that wrap a single standalone absorb it
		$blocks = $this->mergeBlocks($conditionals, $standalones, $lines);

		// Sort by line_start
		usort($blocks, function ($a, $b) {
			return $a['line_start'] - $b['line_start'];
		});

		// Check self-containedness and improve labels for each block
		foreach ($blocks as &$block) {
			$blockLines = array_slice($lines, $block['line_start'] - 1, $block['line_end'] - $block['line_start'] + 1);

			if ($block['movable'] && $block['line_start'] !== $block['line_end']) {
				$block['movable'] = $this->isSelfContained($blockLines);
			}

			// Try to find a better label from translation keys inside the block
			$betterName = $this->extractLabelFromContent($blockLines);
			if ($betterName) {
				$block['name'] = $betterName;
			}
		}
		unset($block);

		return $blocks;
	}

	/**
	 * Look inside block lines for Text::_('KEY') calls and use the translated
	 * string as the block label. Falls back to the raw key if translation is
	 * not available. Returns null if no translation call is found.
	 */
	private function extractLabelFromContent(array $blockLines): ?string
	{
		$combined = implode("\n", $blockLines);

		// 1. Match Text::_('KEY'), JText::_('KEY')
		if (preg_match('/(?:Text|JText)::_\s*\(\s*[\'"]([A-Z0-9_]+)[\'"]/', $combined, $m)) {
			$key = $m[1];

			// Try to translate via Joomla's language system
			try {
				$lang = \Joomla\CMS\Factory::getApplication()->getLanguage();
				$translated = $lang->_($key);
				if ($translated !== $key) {
					return $translated;
				}
			} catch (\Exception $e) {
				// Language not available, use the key
			}

			return $key;
		}

		// 2. Match loadTemplate('string_literal') inside the block
		if (preg_match('/->loadTemplate\s*\(\s*[\'"](\w+)[\'"]\s*\)/', $combined, $m)) {
			return \Joomla\CMS\Language\Text::sprintf('PLG_SYSTEM_VIEWBUILDER_TEMPLATE_PREFIX', $m[1]);
		}

		return null;
	}

	/**
	 * Detect standalone single-line expressions that are safe to move:
	 * - $this->loadTemplate('xxx')
	 * - LayoutHelper::render('xxx', ...)
	 * - echo $this->item->event->xxx
	 * - echo $this->item->introtext
	 * Only if the line is a complete PHP statement (opens and closes PHP on the same line
	 * and is not inside a control structure).
	 */
	private function detectStandaloneLines(array $lines): array
	{
		$blocks = [];
		$controlDepth = 0;

		foreach ($lines as $lineIdx => $line) {
			$trimmed = trim($line);

			// Track control structure depth to skip nested lines
			$controlDepth += $this->countControlOpens($trimmed);
			$controlDepth -= $this->countControlCloses($trimmed);
			$controlDepth = max(0, $controlDepth);

			// Only detect at top level (depth 0) or depth 1 (inside the main wrapper div)
			if ($controlDepth > 1) {
				continue;
			}

			$lineNum = $lineIdx + 1;

			if (preg_match('/\$this->loadTemplate\s*\(\s*[\'"](\w+)[\'"]\s*\)/', $trimmed, $m)) {
				$blocks[] = [
					'name'       => 'Sub: ' . $m[1],
					'type'       => 'sub_template',
					'line_start' => $lineNum,
					'line_end'   => $lineNum,
					'movable'    => true,
				];
			} elseif (preg_match('/LayoutHelper::render\s*\(\s*[\'"]([\\w.]+)[\'"]/', $trimmed, $m)) {
				$blocks[] = [
					'name'       => 'Layout: ' . $m[1],
					'type'       => 'layout',
					'line_start' => $lineNum,
					'line_end'   => $lineNum,
					'movable'    => true,
				];
			} elseif (preg_match('/^\s*<\?php\s+echo\s+\$this->item->event->(\w+)\s*;/', $trimmed, $m)) {
				$blocks[] = [
					'name'       => 'Event: ' . $m[1],
					'type'       => 'expression',
					'line_start' => $lineNum,
					'line_end'   => $lineNum,
					'movable'    => true,
				];
			} elseif (preg_match('/^\s*<\?php\s+echo\s+\$this->item->(\w+)\s*;/', $trimmed, $m)) {
				$blocks[] = [
					'name'       => 'Output: ' . $m[1],
					'type'       => 'expression',
					'line_start' => $lineNum,
					'line_end'   => $lineNum,
					'movable'    => true,
				];
			}
		}

		return $blocks;
	}

	/**
	 * Detect top-level conditional blocks (if/endif with alternative syntax).
	 * Only detects the outermost level (depth 0).
	 */
	private function detectConditionalBlocks(array $lines): array
	{
		$blocks = [];
		$stack = [];

		foreach ($lines as $lineIdx => $line) {
			$lineNum = $lineIdx + 1;
			$trimmed = trim($line);

			// Count if-opens on this line (alternative syntax only)
			$opens = $this->countControlOpens($trimmed);
			for ($i = 0; $i < $opens; $i++) {
				$depth = count($stack);
				$paramName = '';

				if ($depth === 0) {
					// Extract a meaningful name from the condition
					if (preg_match('/if\s*\((.+?)\)\s*:/', $trimmed, $cm)) {
						$condition = $cm[1];
						if (preg_match('/->get\s*\(\s*[\'"](\w+)[\'"]/', $condition, $pm)) {
							$paramName = $pm[1];
						} elseif (preg_match('/\$this->([\w]+(?:->[\w]+)*)/', $condition, $pm)) {
							// Use the last segment: $this->lead_items => lead_items, $this->item->state => state
							$parts = explode('->', $pm[1]);
							$paramName = end($parts);
						} elseif (preg_match('/\$(\w+)/', $condition, $pm)) {
							$paramName = $pm[1];
						}
					}
				}

				$stack[] = [
					'line_start' => $lineNum,
					'param'      => $paramName,
					'depth'      => $depth,
				];
			}

			// Count endif-closes on this line
			$closes = $this->countControlCloses($trimmed);
			for ($i = 0; $i < $closes; $i++) {
				if (!empty($stack)) {
					$open = array_pop($stack);
					// Only report depth-0 blocks (truly top-level)
					if ($open['depth'] === 0 && ($lineNum - $open['line_start']) >= 1) {
						$name = $open['param'] ?: ('condition_L' . $open['line_start']);
						$blocks[] = [
							'name'       => $name,
							'type'       => 'conditional',
							'line_start' => $open['line_start'],
							'line_end'   => $lineNum,
							'movable'    => true,
						];
					}
				}
			}
		}

		return $blocks;
	}

	/**
	 * Count how many PHP alternative-syntax control structures OPEN on this line.
	 * Matches: if (...) :, elseif (...) :, else :, foreach (...) :, for (...) :, while (...) :
	 * Does NOT count brace-style { } blocks.
	 */
	private function countControlOpens(string $line): int
	{
		$count = 0;
		// if/elseif/foreach/for/while with alternative syntax colon
		$count += preg_match_all('/\b(?:if|foreach|for|while)\s*\(.*?\)\s*:\s*/', $line);
		return $count;
	}

	/**
	 * Count how many PHP alternative-syntax control structures CLOSE on this line.
	 */
	private function countControlCloses(string $line): int
	{
		$count = 0;
		$count += preg_match_all('/\b(?:endif|endforeach|endfor|endwhile)\b/', $line);
		return $count;
	}

	/**
	 * Merge conditional blocks and standalone lines.
	 * If a conditional wraps exactly one standalone line (plus whitespace/comments),
	 * absorb the standalone into the conditional as a single movable unit.
	 * Standalone lines that are inside a conditional are removed from the standalone list.
	 */
	private function mergeBlocks(array $conditionals, array $standalones, array $lines): array
	{
		$result = [];
		$absorbedStandalones = [];

		foreach ($conditionals as $cond) {
			// Find standalones inside this conditional
			$innerStandalones = [];
			foreach ($standalones as $sIdx => $s) {
				if ($s['line_start'] >= $cond['line_start'] && $s['line_end'] <= $cond['line_end']) {
					$innerStandalones[] = $sIdx;
				}
			}

			if (count($innerStandalones) === 1) {
				// Conditional wraps exactly one standalone: use standalone's name, conditional's range
				$sIdx = $innerStandalones[0];
				$s = $standalones[$sIdx];
				$result[] = [
					'name'       => $s['name'],
					'type'       => 'conditional',
					'line_start' => $cond['line_start'],
					'line_end'   => $cond['line_end'],
					'movable'    => $cond['movable'],
				];
				$absorbedStandalones[$sIdx] = true;
			} else {
				// Keep the conditional as-is
				$result[] = $cond;
				// Mark all inner standalones as absorbed (they're part of this conditional)
				foreach ($innerStandalones as $sIdx) {
					$absorbedStandalones[$sIdx] = true;
				}
			}
		}

		// Add standalones that were NOT absorbed by any conditional
		foreach ($standalones as $sIdx => $s) {
			if (!isset($absorbedStandalones[$sIdx])) {
				$result[] = $s;
			}
		}

		return $result;
	}

	/**
	 * Check if a block of lines is self-contained:
	 * - PHP control structures are balanced (every if has endif, etc.)
	 * - HTML structural tags are balanced (every <div> has </div>, etc.)
	 */
	private function isSelfContained(array $blockLines): bool
	{
		return $this->hasBalancedPhpControls($blockLines) && $this->hasBalancedHtmlTags($blockLines);
	}

	/**
	 * Check that PHP alternative-syntax control structures are balanced.
	 */
	private function hasBalancedPhpControls(array $blockLines): bool
	{
		$depth = 0;
		foreach ($blockLines as $line) {
			$trimmed = trim($line);
			$depth += $this->countControlOpens($trimmed);
			$depth -= $this->countControlCloses($trimmed);

			if ($depth < 0) {
				return false;
			}
		}

		return $depth === 0;
	}

	/**
	 * Check that HTML structural tags are balanced within the block.
	 * Only checks major structural tags: div, section, article, form, main,
	 * header, footer, aside, nav, ul, ol, table, h1-h6.
	 */
	private function hasBalancedHtmlTags(array $blockLines): bool
	{
		$tagPattern = 'div|section|article|form|main|header|footer|aside|nav|ul|ol|table|h[1-6]';
		$stack = [];

		$combined = implode("\n", $blockLines);

		// Find all opening and closing tags
		if (preg_match_all('/<\/?(' . $tagPattern . ')(?:\s[^>]*)?\s*>/i', $combined, $matches, PREG_OFFSET_CAPTURE)) {
			foreach ($matches[0] as $idx => $match) {
				$fullTag = $match[0];
				$tagName = strtolower($matches[1][$idx][0]);
				$isClosing = (strpos($fullTag, '</') === 0);

				if ($isClosing) {
					if (empty($stack) || end($stack) !== $tagName) {
						return false;
					}
					array_pop($stack);
				} else {
					// Check for self-closing (e.g. <div />)
					if (substr(rtrim($fullTag), -2) === '/>') {
						continue;
					}
					$stack[] = $tagName;
				}
			}
		}

		return empty($stack);
	}

	private function buildGroups(array $blocks): array
	{
		if (empty($blocks)) {
			return [];
		}

		$names = array_map(function ($b) { return $b['name']; }, $blocks);

		return [
			[
				'name'   => 'main',
				'width'  => 'full',
				'blocks' => $names,
			],
		];
	}
}
