<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ViewBuilder
 *
 * @copyright   (C) 2026 Hikari Software. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */


namespace Joomla\Plugin\System\ViewBuilder\Extension;

use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\ViewBuilder\Autoload\FormFieldOverrideLoader;
use Joomla\Plugin\System\ViewBuilder\Autoload\TextOverrideLoader;
use Joomla\Plugin\System\ViewBuilder\Autoload\ViewOverrideLoader;
use Joomla\Plugin\System\ViewBuilder\Service\FormBuilderHelper;
use Joomla\Plugin\System\ViewBuilder\Service\TranslationEditorHelper;
use Joomla\Plugin\System\ViewBuilder\Service\ViewBuilderHelper;
use Joomla\Plugin\System\ViewBuilder\Service\ViewParser;

\defined('_JEXEC') or die;

final class ViewBuilderPlugin extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterInitialise' => 'onAfterInitialise',
			'onContentPrepareForm' => 'onContentPrepareForm',
			'onBeforeCompileHead' => 'onBeforeCompileHead',
			'onAjaxViewbuilder' => 'onAjaxViewbuilder',
		];
	}

	public function onAfterInitialise(): void
	{
		if (!$this->isActive()) {
			return;
		}

		$this->loadLanguage();

		ViewBuilderHelper::init($this->params);

		$pluginPath = \dirname(__DIR__, 2);
		$loader = new ViewOverrideLoader(
			JPATH_LIBRARIES . '/src/MVC/View/HtmlView.php',
			$pluginPath . '/cache/OriginalHtmlView.php',
			\dirname(__DIR__) . '/View/HtmlView.php'
		);

		\spl_autoload_register([$loader, 'loadClass'], true, true);

		$formFieldLoader = new FormFieldOverrideLoader(
			JPATH_LIBRARIES . '/src/Form/FormField.php',
			$pluginPath . '/cache/OriginalFormField.php',
			\dirname(__DIR__) . '/Form/FormField.php'
		);

		\spl_autoload_register([$formFieldLoader, 'loadClass'], true, true);

		if ((int) $this->params->get('enable_translation_editor', 1)) {
			$textLoader = new TextOverrideLoader(
				JPATH_LIBRARIES . '/src/Language/Text.php',
				$pluginPath . '/cache/OriginalText.php',
				\dirname(__DIR__) . '/Language/Text.php'
			);

			\spl_autoload_register([$textLoader, 'loadClass'], true, true);
		}
	}

	public function onContentPrepareForm($event): void
	{
		if (!$this->isActive()) {
			return;
		}

		$form = $event->getArgument('subject');
		if (!($form instanceof Form)) {
			return;
		}

		$formName = $form->getName();

		// Load override XML if it exists
		$overridePath = FormBuilderHelper::getFormOverridePath($formName);
		if ($overridePath && file_exists($overridePath)) {
			FormBuilderHelper::applyFormOverride($form, $overridePath);
			FormBuilderHelper::markOverridden($formName, $overridePath);
		}

		// Register form for field wrapping
		if (ViewBuilderHelper::shouldWrap()) {
			FormBuilderHelper::registerForm($form);
			FormBuilderHelper::enableWrapping();
		}
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

		// Register language strings for JavaScript
		$jsKeys = [
			'PLG_SYSTEM_VIEWBUILDER_JS_ERROR_LOADING_FILE',
			'PLG_SYSTEM_VIEWBUILDER_JS_ERROR',
			'PLG_SYSTEM_VIEWBUILDER_JS_OVERRIDE_LABEL',
			'PLG_SYSTEM_VIEWBUILDER_JS_ORIGINAL_LABEL',
			'PLG_SYSTEM_VIEWBUILDER_JS_REVERT_TO_ORIGINAL',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVE_AS_OVERRIDE',
			'PLG_SYSTEM_VIEWBUILDER_JS_CLOSE',
			'PLG_SYSTEM_VIEWBUILDER_JS_FILE_LOADED',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVING',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVED_TO',
			'PLG_SYSTEM_VIEWBUILDER_JS_NOT_SAVED',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVE_FAILED',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ERROR',
			'PLG_SYSTEM_VIEWBUILDER_JS_CONFIRM_REVERT',
			'PLG_SYSTEM_VIEWBUILDER_JS_REVERTING',
			'PLG_SYSTEM_VIEWBUILDER_JS_REVERTED',
			'PLG_SYSTEM_VIEWBUILDER_JS_BUILDER_TITLE',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ORDER',
			'PLG_SYSTEM_VIEWBUILDER_JS_CODE_EDITOR',
			'PLG_SYSTEM_VIEWBUILDER_JS_BUILDER',
			'PLG_SYSTEM_VIEWBUILDER_JS_NO_BLOCKS',
			'PLG_SYSTEM_VIEWBUILDER_JS_BLOCKS_DETECTED',
			'PLG_SYSTEM_VIEWBUILDER_JS_ORDER_CHANGED',
			'PLG_SYSTEM_VIEWBUILDER_JS_NO_CHANGES',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVING_ORDER',
			'PLG_SYSTEM_VIEWBUILDER_JS_ORDER_SAVED',
			'PLG_SYSTEM_VIEWBUILDER_JS_NOT_SAVED_INVALID_PHP',
			'PLG_SYSTEM_VIEWBUILDER_JS_CHECKING_DEPS',
			'PLG_SYSTEM_VIEWBUILDER_JS_REORDER_WARNING',
			'PLG_SYSTEM_VIEWBUILDER_JS_DEP_USES',
			'PLG_SYSTEM_VIEWBUILDER_JS_DEP_DEFINES',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ANYWAY',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVE_CANCELLED',
			'PLG_SYSTEM_VIEWBUILDER_JS_ERROR_PARSING',
			'PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_TITLE',
			'PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_IN_FILE',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVE_BLOCK',
			'PLG_SYSTEM_VIEWBUILDER_JS_EDITING_BLOCK',
			'PLG_SYSTEM_VIEWBUILDER_JS_SAVING_BLOCK',
			'PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_SAVED',
			'PLG_SYSTEM_VIEWBUILDER_JS_ERROR_LOADING_BLOCK',
			'PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_MOVED',
			'PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_MOVED_SHORT',
			'PLG_SYSTEM_VIEWBUILDER_JS_MOVE_WARNING',
			'PLG_SYSTEM_VIEWBUILDER_JS_DEP_USES_VARS',
			'PLG_SYSTEM_VIEWBUILDER_JS_DEP_DEFINES_VARS',
			'PLG_SYSTEM_VIEWBUILDER_JS_PROCEED_ANYWAY',
			'PLG_SYSTEM_VIEWBUILDER_JS_MOVE_FAILED',
			'PLG_SYSTEM_VIEWBUILDER_JS_MOVE_REVERTED',
			'PLG_SYSTEM_VIEWBUILDER_JS_MOVE_ERROR',
			'PLG_SYSTEM_VIEWBUILDER_JS_CONFIRM_DELETE_BLOCK',
			'PLG_SYSTEM_VIEWBUILDER_JS_DELETING_BLOCK',
			'PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_DELETED',
			'PLG_SYSTEM_VIEWBUILDER_JS_DELETE_FAILED',
			'PLG_SYSTEM_VIEWBUILDER_JS_CONFIRM_RESET',
			'PLG_SYSTEM_VIEWBUILDER_JS_RESETTING',
			'PLG_SYSTEM_VIEWBUILDER_JS_RESET_SUCCESS',
			'PLG_SYSTEM_VIEWBUILDER_JS_RESET_FAILED',
			// Form field builder
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_EDIT_TITLE',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_BUILDER_TITLE',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_EDIT_TITLE',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_SAVING',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_SAVED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_SAVE_FAILED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_LOADED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_DELETED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_DELETE_FAILED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_CONFIRM_DELETE_FIELD',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_MOVED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_MOVE_FAILED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_XML_LOADED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_SAVING_XML',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_XML_SAVED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_XML_SAVE_FAILED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_CONFIRM_REVERT',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_REVERTED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELDS_DETECTED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_SAVE_ORDER',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_ORDER_SAVED',
			'PLG_SYSTEM_VIEWBUILDER_JS_FORM_NO_CHANGES',
		];
		foreach ($jsKeys as $key) {
			Text::script($key);
		}

		// Translation Editor: inject used translations map for JS
		if ((int) $this->params->get('enable_translation_editor', 1) && method_exists(Text::class, 'getUsedTranslations')) {
			$usedTranslations = Text::getUsedTranslations();

			if (!empty($usedTranslations)) {
				// Build a value-to-keys reverse map for JS matching
				$reverseMap = [];
				foreach ($usedTranslations as $key => $value) {
					$normalized = mb_strtolower(trim($value));
					if (mb_strlen($normalized) < 2) {
						continue;
					}
					if (!isset($reverseMap[$normalized])) {
						$reverseMap[$normalized] = [];
					}
					$reverseMap[$normalized][] = $key;
				}

				$doc->addScriptOptions('viewbuilder.translations', $reverseMap);
			}

			$lang = $app->getLanguage();
			$clientId = $app->isClient('administrator') ? 1 : 0;
			$doc->addScriptOptions('viewbuilder.translationEditor', [
				'enabled'     => true,
				'currentLang' => $lang->getTag(),
				'clientId'    => $clientId,
			]);

			// Register translation editor JS language keys
			$transEditorKeys = [
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_EDIT_TITLE',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVE',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVING',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVED',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVE_FAILED',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_OVERRIDE',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_ORIGINAL',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_PICK_KEY',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_LOADING',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_REMOVE_OVERRIDE',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_REMOVING',
				'PLG_SYSTEM_VIEWBUILDER_JS_TRANS_OVERRIDE_REMOVED',
			];
			foreach ($transEditorKeys as $key) {
				Text::script($key);
			}
		}
	}

	public function onAjaxViewbuilder(AjaxEvent $event): void
	{
		$app = $this->getApplication();
		$user = $app->getIdentity();

		if (!$user || $user->guest) {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_VIEWBUILDER_ACCESS_DENIED'), 403);
		}

		if (!$this->isUserAllowed($user)) {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_VIEWBUILDER_ACCESS_DENIED'), 403);
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
			case 'delete_block':
				$result = $this->handleDeleteBlock($input);
				break;
			case 'load_form_xml':
				$result = $this->handleLoadFormXml($input);
				break;
			case 'save_form_xml':
				$result = $this->handleSaveFormXml($input);
				break;
			case 'parse_form':
				$result = $this->handleParseForm($input);
				break;
			case 'move_form_field':
				$result = $this->handleMoveFormField($input);
				break;
			case 'delete_form_field':
				$result = $this->handleDeleteFormField($input);
				break;
			case 'revert_form':
				$result = $this->handleRevertForm($input);
				break;
			case 'load_form_field_xml':
				$result = $this->handleLoadFormFieldXml($input);
				break;
			case 'save_form_field_xml':
				$result = $this->handleSaveFormFieldXml($input);
				break;
			case 'load_translation':
				$key = $input->getString('key', '');
				$clientId = $input->getInt('client_id', 0);
				$result = json_encode(TranslationEditorHelper::loadTranslation($key, $clientId));
				break;
			case 'save_translation':
				$key = $input->getString('key', '');
				$clientId = $input->getInt('client_id', 0);
				$translations = $input->get('translations', [], 'array');
				$result = json_encode(TranslationEditorHelper::saveTranslation($key, $translations, $clientId));
				break;
			case 'remove_translation_override':
				$key = $input->getString('key', '');
				$tag = $input->getString('tag', '');
				$clientId = $input->getInt('client_id', 0);
				$result = json_encode(TranslationEditorHelper::removeTranslationOverride($key, $tag, $clientId));
				break;
			default:
				throw new \RuntimeException(Text::_('PLG_SYSTEM_VIEWBUILDER_UNKNOWN_TASK'), 400);
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
			throw new \RuntimeException(Text::_('PLG_SYSTEM_VIEWBUILDER_CANNOT_DETERMINE_OVERRIDE_PATH'), 500);
		}

		$dir = \dirname($overridePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($overridePath, $this->stripAutoGeneratedMarker($content));

		return json_encode([
			'success'       => true,
			'saved_to'      => $overridePath,
			'is_override'   => true,
		]);
	}

	/**
	 * Strip the @vb-auto-generated marker from content so the override
	 * becomes a user-modified override after any edit.
	 */
	private function stripAutoGeneratedMarker(string $content): string
	{
		return str_replace('<?php /* @vb-auto-generated */', '<?php', $content);
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
				return Text::sprintf('PLG_SYSTEM_VIEWBUILDER_SYNTAX_ERROR_ON_LINE', $m[1], $message);
			}
			return Text::sprintf('PLG_SYSTEM_VIEWBUILDER_SYNTAX_ERROR', $message);
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
				'message' => Text::sprintf('PLG_SYSTEM_VIEWBUILDER_BLOCK_NOT_FOUND_IN_FILE', $blockName),
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
		$actualFile = ($overridePath && file_exists($overridePath)) ? $overridePath : $filePath;
		$savePath = $overridePath ?: $filePath;

		$fileContent = file_get_contents($actualFile);

		// Try @block/@endblock style first
		$pattern = '/(<!--\s*@block:' . preg_quote($blockName, '/') . '\s*-->)(.*?)(<!--\s*@endblock:' . preg_quote($blockName, '/') . '\s*-->)/s';
		$matched = preg_match($pattern, $fileContent);

		if (!$matched) {
			// Try HikaShop style: <!-- NAME --> ... <!-- EO NAME -->
			$hikaName = str_replace('_', ' ', $blockName);
			$pattern = '/(<!--\s+' . preg_quote($hikaName, '/') . '\s+-->)(.*?)(<!--\s+EO\s+' . preg_quote($hikaName, '/') . '\s+-->)/s';
			$matched = preg_match($pattern, $fileContent);
		}

		if (!$matched) {
			return json_encode([
				'success' => false,
				'message' => Text::sprintf('PLG_SYSTEM_VIEWBUILDER_BLOCK_NOT_FOUND_IN_OVERRIDE', $blockName),
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

		$dir = \dirname($savePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		file_put_contents($savePath, $this->stripAutoGeneratedMarker($updatedContent));

		return json_encode([
			'success'  => true,
			'saved_to' => $savePath,
		]);
	}

	private function handleDeleteBlock($input): string
	{
		$filePath = $this->resolveFilePath($input->getString('file', ''));
		$blockName = $input->getString('block', '');
		$this->validateFilePath($filePath);

		// Resolve paths: read from override if it exists, otherwise from original
		$overridePath = $this->getOverridePath($filePath);
		$isAlreadyOverride = (strpos(str_replace('\\', '/', $filePath), '/html/') !== false);

		if ($isAlreadyOverride) {
			// File path is already the override
			$readFrom = $filePath;
			$saveTo = $filePath;
		} elseif ($overridePath && file_exists($overridePath)) {
			// Override exists, work on it
			$readFrom = $overridePath;
			$saveTo = $overridePath;
		} elseif ($overridePath) {
			// No override yet — read from original, save as new override
			$readFrom = $filePath;
			$saveTo = $overridePath;
		} else {
			return json_encode([
				'success' => false,
				'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_CANNOT_DETERMINE_OVERRIDE_PATH'),
			]);
		}

		$content = file_get_contents($readFrom);

		// Detect delimiter style and build removal pattern
		$hikaStyle = (strpos($content, '<!-- @block:') === false);
		if ($hikaStyle) {
			$hikaName = str_replace('_', ' ', $blockName);
			$pattern = '/\n?<!--\s+' . preg_quote($hikaName, '/') . '\s+-->.*?<!--\s+EO\s+' . preg_quote($hikaName, '/') . '\s+-->\n?/s';
		} else {
			$pattern = '/\n?<!--\s*@block:' . preg_quote($blockName, '/') . '\s*-->.*?<!--\s*@endblock:' . preg_quote($blockName, '/') . '\s*-->\n?/s';
		}

		if (!preg_match($pattern, $content)) {
			return json_encode([
				'success' => false,
				'message' => Text::sprintf('PLG_SYSTEM_VIEWBUILDER_BLOCK_NOT_FOUND_IN_FILE', $blockName),
			]);
		}

		$newContent = preg_replace($pattern, "\n", $content, 1);

		// Syntax check
		$syntaxError = $this->checkPhpSyntax($newContent);
		if ($syntaxError) {
			return json_encode([
				'success'      => false,
				'syntax_error' => true,
				'message'      => $syntaxError,
			]);
		}

		// Create override directory if needed
		$dir = \dirname($saveTo);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($saveTo, $this->stripAutoGeneratedMarker($newContent));

		return json_encode(['success' => true]);
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
				'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_FILE_NOT_FOUND'),
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
				'message' => Text::sprintf('PLG_SYSTEM_VIEWBUILDER_BLOCK_NOT_FOUND', $blockName),
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
					'message' => Text::sprintf('PLG_SYSTEM_VIEWBUILDER_TARGET_BLOCK_NOT_FOUND', $afterBlock),
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

		file_put_contents($savePath, $this->stripAutoGeneratedMarker($newContent));

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
				'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_MISSING_ORDER_DATA'),
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
				'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_FILE_NOT_FOUND'),
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
		// Try @block/@endblock style first
		$pattern = '/<!--\s*@block:' . preg_quote($blockName, '/') . '\s*-->\n?(.*?)\n?<!--\s*@endblock:' . preg_quote($blockName, '/') . '\s*-->/s';
		if (preg_match($pattern, $fileContent, $m)) {
			return $m[1];
		}

		// Try HikaShop style: <!-- NAME --> ... <!-- EO NAME -->
		// Block names come from JS with underscores, HikaShop uses spaces
		$hikaName = str_replace('_', ' ', $blockName);
		$pattern = '/<!--\s+' . preg_quote($hikaName, '/') . '\s+-->\n?(.*?)\n?<!--\s+EO\s+' . preg_quote($hikaName, '/') . '\s+-->/s';
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

	// ========================================================================
	// Form AJAX Handlers
	// ========================================================================

	/**
	 * Strip group prefix from a field identifier.
	 * E.g. "params.language" → "language", "name" → "name"
	 */
	private function bareFieldName(string $fieldId): string
	{
		if (strpos($fieldId, '.') !== false) {
			return substr($fieldId, strrpos($fieldId, '.') + 1);
		}
		return $fieldId;
	}

	/**
	 * Resolve a form XML path: returns [originalPath, overridePath, snapshotPath, actualPath, isOverride].
	 *
	 * Priority for reading: override > snapshot > original
	 * The snapshot contains the live form XML including plugin-injected fields.
	 */
	private function resolveFormPaths(string $formName): array
	{
		$originalPath = FormBuilderHelper::getOriginalFormPath($formName);
		$overridePath = FormBuilderHelper::getFormOverridePath($formName);
		$snapshotPath = FormBuilderHelper::getSnapshotPath($formName);
		$isOverride = false;

		if ($overridePath && file_exists($overridePath)) {
			$actualPath = $overridePath;
			$isOverride = true;
		} elseif (file_exists($snapshotPath)) {
			$actualPath = $snapshotPath;
		} else {
			$actualPath = $originalPath;
		}

		return [$originalPath, $overridePath, $snapshotPath, $actualPath, $isOverride];
	}

	private function handleLoadFormXml($input): string
	{
		$formName = $input->getString('form', '');
		if (empty($formName)) {
			return json_encode(['success' => false, 'message' => 'Missing form name']);
		}

		[$originalPath, $overridePath, $snapshotPath, $actualPath, $isOverride] = $this->resolveFormPaths($formName);

		if (!$actualPath || !file_exists($actualPath)) {
			return json_encode(['success' => false, 'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_FILE_NOT_FOUND')]);
		}

		return json_encode([
			'success'       => true,
			'content'       => file_get_contents($actualPath),
			'form'          => $formName,
			'is_override'   => $isOverride,
			'override_path' => $overridePath,
		]);
	}

	private function handleSaveFormXml($input): string
	{
		$formName = $input->getString('form', '');
		$content  = $input->getRaw('content', '');

		if (empty($formName)) {
			return json_encode(['success' => false, 'message' => 'Missing form name']);
		}

		// Validate XML syntax
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($content);
		if ($xml === false) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			$msg = !empty($errors) ? $errors[0]->message : 'Invalid XML';
			return json_encode(['success' => false, 'message' => 'XML error: ' . trim($msg)]);
		}
		libxml_use_internal_errors(false);

		$overridePath = FormBuilderHelper::getFormOverridePath($formName);
		if (!$overridePath) {
			return json_encode(['success' => false, 'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_CANNOT_DETERMINE_OVERRIDE_PATH')]);
		}

		$dir = \dirname($overridePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($overridePath, $content);

		return json_encode([
			'success'  => true,
			'saved_to' => $overridePath,
		]);
	}

	private function handleParseForm($input): string
	{
		$formName = $input->getString('form', '');
		if (empty($formName)) {
			return json_encode(['success' => false, 'message' => 'Missing form name']);
		}

		[$originalPath, $overridePath, $snapshotPath, $actualPath, $isOverride] = $this->resolveFormPaths($formName);

		if (!$actualPath || !file_exists($actualPath)) {
			return json_encode(['success' => false, 'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_FILE_NOT_FOUND')]);
		}

		$xml = simplexml_load_file($actualPath);
		if (!$xml) {
			return json_encode(['success' => false, 'message' => 'Failed to parse XML']);
		}

		$fields = [];
		// Use XPath to find all fieldsets at any nesting level (e.g. inside <fields name="params">)
		$fieldsets = $xml->xpath('//fieldset');
		$seenFields = [];
		foreach ($fieldsets as $fieldset) {
			$fieldsetName = (string) $fieldset['name'];
			foreach ($fieldset->children() as $field) {
				if ($field->getName() !== 'field') {
					continue;
				}
				$name = (string) $field['name'];
				// Avoid duplicates when same fieldset appears multiple times
				$key = $fieldsetName . '.' . $name;
				if (isset($seenFields[$key])) {
					continue;
				}
				$seenFields[$key] = true;
				$fields[] = [
					'name'     => $name,
					'type'     => (string) $field['type'],
					'label'    => (string) ($field['label'] ?? $field['name']),
					'fieldset' => $fieldsetName,
				];
			}
		}

		return json_encode([
			'success'     => true,
			'form'        => $formName,
			'is_override' => $isOverride,
			'fields'      => $fields,
		]);
	}

	private function handleMoveFormField($input): string
	{
		$formName    = $input->getString('form', '');
		$fieldName   = $input->getString('field', '');
		$afterField  = $input->getString('after', '');
		$beforeField = $input->getString('before', '');

		if (empty($formName) || empty($fieldName)) {
			return json_encode(['success' => false, 'message' => 'Missing parameters']);
		}

		[$originalPath, $overridePath, $snapshotPath, $actualPath, $isOverride] = $this->resolveFormPaths($formName);

		if (!$actualPath || !file_exists($actualPath)) {
			return json_encode(['success' => false, 'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_FILE_NOT_FOUND')]);
		}

		$savePath = $overridePath ?: $actualPath;

		$xml = simplexml_load_file($actualPath);
		if (!$xml) {
			return json_encode(['success' => false, 'message' => 'Failed to parse XML']);
		}

		$bareFieldName = $this->bareFieldName($fieldName);
		$bareAfterField = !empty($afterField) ? $this->bareFieldName($afterField) : '';
		$bareBeforeField = !empty($beforeField) ? $this->bareFieldName($beforeField) : '';

		// Resolve ALL XPath lookups to DOM nodes BEFORE any DOM mutations,
		// because SimpleXML's internal state can become inconsistent after direct DOM changes.
		$movedFields = $xml->xpath('//field[@name="' . $bareFieldName . '"]');
		if (empty($movedFields)) {
			return json_encode(['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_VIEWBUILDER_BLOCK_NOT_FOUND', $fieldName)]);
		}
		$movedNode = dom_import_simplexml($movedFields[0]);

		$afterNode = null;
		$beforeNode = null;
		if (!empty($bareAfterField)) {
			$matches = $xml->xpath('//field[@name="' . $bareAfterField . '"]');
			if (!empty($matches)) {
				$afterNode = dom_import_simplexml($matches[0]);
			}
		}
		if (!empty($bareBeforeField)) {
			$matches = $xml->xpath('//field[@name="' . $bareBeforeField . '"]');
			if (!empty($matches)) {
				$beforeNode = dom_import_simplexml($matches[0]);
			}
		}
		$fallbackFieldsets = $xml->xpath('//fieldset');

		// Now perform DOM mutations
		$movedNode->parentNode->removeChild($movedNode);

		if ($afterNode) {
			// Insert after the afterNode (in whatever fieldset it's in)
			$targetParent = $afterNode->parentNode;
			if ($afterNode->nextSibling) {
				$targetParent->insertBefore($movedNode, $afterNode->nextSibling);
			} else {
				$targetParent->appendChild($movedNode);
			}
		} elseif ($beforeNode) {
			// Insert before the beforeNode (cross-fieldset: after="" but before=username)
			$targetParent = $beforeNode->parentNode;
			$targetParent->insertBefore($movedNode, $beforeNode);
		} else {
			// No reference field — insert at beginning of first fieldset
			if (!empty($fallbackFieldsets)) {
				$targetDom = dom_import_simplexml($fallbackFieldsets[0]);
				$firstField = null;
				foreach ($targetDom->childNodes as $node) {
					if ($node->nodeType === XML_ELEMENT_NODE && $node->localName === 'field') {
						$firstField = $node;
						break;
					}
				}
				if ($firstField) {
					$targetDom->insertBefore($movedNode, $firstField);
				} else {
					$targetDom->appendChild($movedNode);
				}
			}
		}

		$dir = \dirname($savePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		$doc = $movedNode->ownerDocument;
		$doc->formatOutput = true;
		file_put_contents($savePath, $doc->saveXML($doc->documentElement));

		return json_encode(['success' => true, 'saved_to' => $savePath]);
	}

	private function handleDeleteFormField($input): string
	{
		$formName  = $input->getString('form', '');
		$fieldName = $input->getString('field', '');

		if (empty($formName) || empty($fieldName)) {
			return json_encode(['success' => false, 'message' => 'Missing parameters']);
		}

		$bareFieldName = $this->bareFieldName($fieldName);

		[$originalPath, $overridePath, $snapshotPath, $actualPath, $isOverride] = $this->resolveFormPaths($formName);

		if (!$actualPath || !file_exists($actualPath)) {
			return json_encode(['success' => false, 'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_FILE_NOT_FOUND')]);
		}

		$savePath = $overridePath ?: $actualPath;

		$xml = simplexml_load_file($actualPath);
		if (!$xml) {
			return json_encode(['success' => false, 'message' => 'Failed to parse XML']);
		}

		// Mark the field as deleted rather than removing it, so that
		// applyFormOverride won't re-add it from the live form as a "new" field.
		$matches = $xml->xpath('//field[@name="' . $bareFieldName . '"]');
		if (empty($matches)) {
			return json_encode(['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_VIEWBUILDER_BLOCK_NOT_FOUND', $fieldName)]);
		}
		$node = dom_import_simplexml($matches[0]);
		$node->setAttribute('vb-deleted', 'true');

		$dir = \dirname($savePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		$doc = dom_import_simplexml($xml)->ownerDocument;
		$doc->formatOutput = true;
		file_put_contents($savePath, $doc->saveXML($doc->documentElement));

		return json_encode(['success' => true]);
	}

	private function handleRevertForm($input): string
	{
		$formName = $input->getString('form', '');
		if (empty($formName)) {
			return json_encode(['success' => false, 'message' => 'Missing form name']);
		}

		$overridePath = FormBuilderHelper::getFormOverridePath($formName);
		if ($overridePath && file_exists($overridePath)) {
			unlink($overridePath);

			// Clean up empty directories
			$dir = \dirname($overridePath);
			while ($dir !== JPATH_ROOT && is_dir($dir) && count(scandir($dir)) === 2) {
				rmdir($dir);
				$dir = \dirname($dir);
			}
		}

		return json_encode(['success' => true, 'reverted' => true]);
	}

	private function handleLoadFormFieldXml($input): string
	{
		$formName  = $input->getString('form', '');
		$fieldName = $input->getString('field', '');

		if (empty($formName) || empty($fieldName)) {
			return json_encode(['success' => false, 'message' => 'Missing parameters']);
		}

		[$originalPath, $overridePath, $snapshotPath, $actualPath, $isOverride] = $this->resolveFormPaths($formName);

		if (!$actualPath || !file_exists($actualPath)) {
			return json_encode(['success' => false, 'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_FILE_NOT_FOUND')]);
		}

		$xml = simplexml_load_file($actualPath);
		if (!$xml) {
			return json_encode(['success' => false, 'message' => 'Failed to parse XML']);
		}

		$bareFieldName = $this->bareFieldName($fieldName);
		$fields = $xml->xpath('//field[@name="' . $bareFieldName . '"]');
		if (empty($fields)) {
			return json_encode(['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_VIEWBUILDER_BLOCK_NOT_FOUND', $fieldName)]);
		}

		$fieldXml = $fields[0]->asXML();

		return json_encode([
			'success' => true,
			'content' => $fieldXml,
			'field'   => $fieldName,
			'form'    => $formName,
		]);
	}

	private function handleSaveFormFieldXml($input): string
	{
		$formName  = $input->getString('form', '');
		$fieldName = $input->getString('field', '');
		$content   = $input->getRaw('content', '');

		if (empty($formName) || empty($fieldName) || empty($content)) {
			return json_encode(['success' => false, 'message' => 'Missing parameters']);
		}

		$bareFieldName = $this->bareFieldName($fieldName);

		// Validate the field XML
		libxml_use_internal_errors(true);
		$newFieldXml = simplexml_load_string($content);
		if ($newFieldXml === false) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			$msg = !empty($errors) ? $errors[0]->message : 'Invalid XML';
			return json_encode(['success' => false, 'message' => 'XML error: ' . trim($msg)]);
		}
		libxml_use_internal_errors(false);

		[$originalPath, $overridePath, $snapshotPath, $actualPath, $isOverride] = $this->resolveFormPaths($formName);

		if (!$actualPath || !file_exists($actualPath)) {
			return json_encode(['success' => false, 'message' => Text::_('PLG_SYSTEM_VIEWBUILDER_FILE_NOT_FOUND')]);
		}

		$savePath = $overridePath ?: $actualPath;

		$xml = simplexml_load_file($actualPath);
		if (!$xml) {
			return json_encode(['success' => false, 'message' => 'Failed to parse XML']);
		}

		// Find and replace the field (XPath searches through all nesting levels)
		$matches = $xml->xpath('//field[@name="' . $bareFieldName . '"]');
		if (empty($matches)) {
			return json_encode(['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_VIEWBUILDER_BLOCK_NOT_FOUND', $fieldName)]);
		}
		$oldNode = dom_import_simplexml($matches[0]);
		$newDom = dom_import_simplexml($newFieldXml);
		$imported = $oldNode->ownerDocument->importNode($newDom, true);
		$oldNode->parentNode->replaceChild($imported, $oldNode);

		$dir = \dirname($savePath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		$doc = dom_import_simplexml($xml)->ownerDocument;
		$doc->formatOutput = true;
		file_put_contents($savePath, $doc->saveXML($doc->documentElement));

		return json_encode(['success' => true, 'saved_to' => $savePath]);
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
			throw new \RuntimeException(Text::_('PLG_SYSTEM_VIEWBUILDER_INVALID_FILE_PATH'), 400);
		}

		if (pathinfo($realPath, PATHINFO_EXTENSION) !== 'php') {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_VIEWBUILDER_ONLY_PHP_FILES'), 400);
		}

		// Must be in a tmpl directory or a template html override directory
		$normalized = str_replace('\\', '/', $realPath);
		$isTmpl = (strpos($normalized, '/tmpl/') !== false);
		$isOverride = (strpos($normalized, '/html/') !== false);

		if (!$isTmpl && !$isOverride) {
			throw new \RuntimeException(Text::_('PLG_SYSTEM_VIEWBUILDER_FILE_MUST_BE_IN_TMPL'), 400);
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

		// For Joomla 4+ child templates, also check the parent template
		$parentTemplate = ViewBuilderHelper::getParentTemplate();
		if (!empty($parentTemplate) && strpos($filePath, '/templates/' . $parentTemplate . '/html/') !== false) {
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
