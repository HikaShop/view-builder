/**
 * View Builder - Frontend wrapper script
 * Handles edit buttons, modals, and builder UI
 */
(function() {
	'use strict';

	var currentModal = null;
	var sortableInstance = null;

	/**
	 * Get a translated string, with optional sprintf-style %s substitution.
	 */
	function t(key) {
		var str = Joomla.Text._(key) || key;
		var args = Array.prototype.slice.call(arguments, 1);
		if (args.length > 0) {
			var i = 0;
			str = str.replace(/%s/g, function() { return args[i++] || ''; });
		}
		return str;
	}

	/**
	 * Unwrap com_ajax response.
	 * com_ajax returns {success:true, data:["json string"]} where data is an array
	 * with plugin results. Our plugin returns a JSON string, so data[0] is that string.
	 */
	function unwrapResponse(resp) {
		if (!resp || !resp.success) {
			return resp || {};
		}
		var raw = resp.data;
		// data is an array of plugin results; take the first one
		if (Array.isArray(raw)) {
			raw = raw[0];
		}
		// raw might be a JSON string
		if (typeof raw === 'string') {
			try { return JSON.parse(raw); } catch(e) { return { success: false, message: raw }; }
		}
		// already an object
		if (raw && typeof raw === 'object') {
			return raw;
		}
		return resp;
	}

	document.addEventListener('click', function(e) {
		var editBtn = e.target.closest('.vb-btn-edit');
		if (editBtn) {
			e.preventDefault();
			e.stopPropagation();
			openEditor(editBtn.dataset.vbFile, editBtn.dataset.vbAjax);
			return;
		}

		var builderBtn = e.target.closest('.vb-btn-builder');
		if (builderBtn) {
			e.preventDefault();
			e.stopPropagation();
			openBuilder(builderBtn.dataset.vbFile, builderBtn.dataset.vbAjax);
			return;
		}
	});

	function openEditor(filePath, ajaxUrl) {
		fetch(ajaxUrl + '&task=load&file=' + encodeURIComponent(filePath))
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var data = unwrapResponse(resp);
				if (!data.success) {
					alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR_LOADING_FILE', data.message || 'Unknown error'));
					return;
				}
				showEditorModal(data, filePath, ajaxUrl);
			})
			.catch(function(err) {
				alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR', err.message));
			});
	}

	function showEditorModal(data, filePath, ajaxUrl) {
		closeModal();

		var overlay = document.createElement('div');
		overlay.className = 'vb-modal-overlay';
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});

		var modal = document.createElement('div');
		modal.className = 'vb-modal';

		var shortName = filePath.replace(/\\/g, '/').split('/').slice(-3).join('/');

		var header = document.createElement('div');
		header.className = 'vb-modal-header';
		header.innerHTML = '<div class="vb-modal-title">' + escapeHtml(shortName) +
			(data.is_override ? ' <span style="color:#5cb85c;font-size:11px;">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_OVERRIDE_LABEL')) + '</span>' : ' <span style="color:#999;font-size:11px;">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_ORIGINAL_LABEL')) + '</span>') +
			'</div>' +
			'<div class="vb-modal-actions">' +
			(data.is_override ? '<button type="button" class="vb-revert-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_REVERT_TO_ORIGINAL')) + '</button>' : '') +
			'<button type="button" class="vb-builder-switch-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_BUILDER')) + '</button>' +
			'<button type="button" class="vb-save-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_AS_OVERRIDE')) + '</button>' +
			'<button type="button" class="vb-close-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_CLOSE')) + '</button>' +
			'</div>';

		var body = document.createElement('div');
		body.className = 'vb-modal-body';

		var textarea = document.createElement('textarea');
		textarea.value = data.content;
		textarea.spellcheck = false;
		textarea.addEventListener('keydown', function(e) {
			if (e.key === 'Tab') {
				e.preventDefault();
				var start = this.selectionStart;
				var end = this.selectionEnd;
				this.value = this.value.substring(0, start) + '\t' + this.value.substring(end);
				this.selectionStart = this.selectionEnd = start + 1;
			}
			if ((e.ctrlKey || e.metaKey) && e.key === 's') {
				e.preventDefault();
				saveFile();
			}
		});
		body.appendChild(textarea);

		var status = document.createElement('div');
		status.className = 'vb-modal-status';
		status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FILE_LOADED');

		modal.appendChild(header);
		modal.appendChild(body);
		modal.appendChild(status);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		currentModal = overlay;

		textarea.focus();

		// Wire up buttons
		var saveBtn = header.querySelector('.vb-save-btn');
		var closeBtn = header.querySelector('.vb-close-btn');
		var revertBtn = header.querySelector('.vb-revert-btn');
		var builderSwitchBtn = header.querySelector('.vb-builder-switch-btn');

		function saveFile() {
			status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVING');
			var formData = new FormData();
			formData.append('file', filePath);
			formData.append('content', textarea.value);

			fetch(ajaxUrl + '&task=save', {
				method: 'POST',
				body: formData
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var d = unwrapResponse(resp);
				if (d.success) {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVED_TO', d.saved_to || 'override');
					status.style.color = '';
				} else if (d.syntax_error) {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_NOT_SAVED', d.message);
					status.style.color = '#d9534f';
				} else {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_FAILED', d.message || 'Unknown error');
					status.style.color = '#d9534f';
				}
			})
			.catch(function(err) {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ERROR', err.message);
				status.style.color = '#d9534f';
			});
		}

		saveBtn.addEventListener('click', saveFile);
		closeBtn.addEventListener('click', closeModal);

		if (revertBtn) {
			revertBtn.addEventListener('click', function() {
				if (!confirm(t('PLG_SYSTEM_VIEWBUILDER_JS_CONFIRM_REVERT'))) return;
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_REVERTING');
				fetch(ajaxUrl + '&task=revert&file=' + encodeURIComponent(filePath))
					.then(function(r) { return r.json(); })
					.then(function(resp) {
						var d = unwrapResponse(resp);
						if (d.success) {
							status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_REVERTED');
							setTimeout(function() { location.reload(); }, 1000);
						}
					});
			});
		}

		if (builderSwitchBtn) {
			builderSwitchBtn.addEventListener('click', function() {
				closeModal();
				openBuilder(filePath, ajaxUrl);
			});
		}

		// ESC to close
		document.addEventListener('keydown', escHandler);
	}

	function openBuilder(filePath, ajaxUrl) {
		fetch(ajaxUrl + '&task=parse&file=' + encodeURIComponent(filePath))
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var data = unwrapResponse(resp);
				if (!data.success) {
					alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR_PARSING', data.message || 'Unknown error'));
					return;
				}
				// Also load the file content for saving after reorder
				fetch(ajaxUrl + '&task=load&file=' + encodeURIComponent(filePath))
					.then(function(r) { return r.json(); })
					.then(function(resp2) {
						var fileData = unwrapResponse(resp2);
						showBuilderModal(data, fileData, filePath, ajaxUrl);
					});
			})
			.catch(function(err) {
				alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR', err.message));
			});
	}

	function showBuilderModal(parseData, fileData, filePath, ajaxUrl) {
		closeModal();

		var overlay = document.createElement('div');
		overlay.className = 'vb-modal-overlay';
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});

		var modal = document.createElement('div');
		modal.className = 'vb-modal';

		var shortName = filePath.replace(/\\/g, '/').split('/').slice(-3).join('/');
		var blocks = parseData.structure ? parseData.structure.blocks || [] : [];

		var header = document.createElement('div');
		header.className = 'vb-modal-header';
		header.innerHTML = '<div class="vb-modal-title">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_BUILDER_TITLE', shortName)) + '</div>' +
			'<div class="vb-modal-actions">' +
			'<button type="button" class="vb-save-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ORDER')) + '</button>' +
			'<button type="button" class="vb-builder-switch-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_CODE_EDITOR')) + '</button>' +
			'<button type="button" class="vb-close-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_CLOSE')) + '</button>' +
			'</div>';

		var body = document.createElement('div');
		body.className = 'vb-modal-body';

		var container = document.createElement('div');
		container.className = 'vb-builder-container';

		var blockList = document.createElement('div');
		blockList.className = 'vb-builder-blocks';
		blockList.id = 'vb-builder-sortable';

		if (blocks.length === 0) {
			blockList.innerHTML = '<div style="padding:20px;color:#999;text-align:center;">' + t('PLG_SYSTEM_VIEWBUILDER_JS_NO_BLOCKS') + '</div>';
		} else {
			blocks.forEach(function(block, idx) {
				var item = document.createElement('div');
				item.className = 'vb-block-item' + (block.movable === false ? ' vb-block-locked' : '');
				item.dataset.blockIndex = idx;
				item.dataset.lineStart = block.line_start;
				item.dataset.lineEnd = block.line_end;
				item.dataset.blockName = block.name;
				item.innerHTML =
					'<span class="vb-block-grip">' + (block.movable !== false ? '&#9776;' : '&#128274;') + '</span>' +
					'<span class="vb-block-name">' + escapeHtml(block.name) + '</span>' +
					'<span class="vb-block-type vb-type-' + escapeHtml(block.type) + '">' + escapeHtml(block.type) + '</span>' +
					'<span class="vb-block-lines">L' + block.line_start + (block.line_end !== block.line_start ? '-' + block.line_end : '') + '</span>';
				blockList.appendChild(item);
			});
		}

		container.appendChild(blockList);
		body.appendChild(container);

		var status = document.createElement('div');
		status.className = 'vb-modal-status';
		status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_BLOCKS_DETECTED', blocks.length);

		modal.appendChild(header);
		modal.appendChild(body);
		modal.appendChild(status);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		currentModal = overlay;

		// Initialize SortableJS if available
		if (typeof Sortable !== 'undefined' && blocks.length > 0) {
			sortableInstance = Sortable.create(blockList, {
				animation: 150,
				handle: '.vb-block-grip',
				filter: '.vb-block-locked',
				ghostClass: 'sortable-ghost',
				dragClass: 'sortable-drag',
				onEnd: function() {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_ORDER_CHANGED');
				}
			});
		} else if (blocks.length > 0) {
			// Load SortableJS dynamically
			var script = document.createElement('script');
			script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
			script.onload = function() {
				sortableInstance = Sortable.create(blockList, {
					animation: 150,
					handle: '.vb-block-grip',
					filter: '.vb-block-locked',
					ghostClass: 'sortable-ghost',
					dragClass: 'sortable-drag',
					onEnd: function() {
						status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_ORDER_CHANGED');
					}
				});
			};
			document.head.appendChild(script);
		}

		// Wire buttons
		var saveBtn = header.querySelector('.vb-save-btn');
		var closeBtn = header.querySelector('.vb-close-btn');
		var editorSwitchBtn = header.querySelector('.vb-builder-switch-btn');

		saveBtn.addEventListener('click', function() {
			var items = blockList.querySelectorAll('.vb-block-item');
			var newOrder = [];
			items.forEach(function(item) {
				newOrder.push({
					name: item.dataset.blockName,
					original_line_start: parseInt(item.dataset.lineStart),
					original_line_end: parseInt(item.dataset.lineEnd)
				});
			});

			// Build original order from parse data and new order names
			var originalOrder = blocks.map(function(b) { return b.name; });
			var newOrderNames = newOrder.map(function(b) { return b.name; });

			// Check if order actually changed
			var orderChanged = originalOrder.some(function(name, i) {
				return name !== newOrderNames[i];
			});

			if (!orderChanged) {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_NO_CHANGES');
				return;
			}

			// Helper function to perform the actual save
			function doSaveReorder() {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVING_ORDER');

				var lines = fileData.content.split('\n');
				var blockContents = [];
				var usedRanges = [];

				// Extract block contents based on original line numbers
				newOrder.forEach(function(block) {
					var startIdx = block.original_line_start - 1;
					var endIdx = block.original_line_end;
					blockContents.push(lines.slice(startIdx, endIdx).join('\n'));
					usedRanges.push({ start: startIdx, end: endIdx });
				});

				// Sort used ranges by start position to process
				var sortedRanges = usedRanges.slice().sort(function(a, b) { return a.start - b.start; });

				// Build new file: keep non-block lines, replace block positions with reordered blocks
				var result = [];
				var blockIdx = 0;
				var currentLine = 0;

				sortedRanges.forEach(function(range) {
					// Add non-block lines before this range
					while (currentLine < range.start) {
						result.push(lines[currentLine]);
						currentLine++;
					}
					// Insert the next block in the new order
					result.push(blockContents[blockIdx]);
					blockIdx++;
					currentLine = range.end;
				});

				// Add remaining lines after all blocks
				while (currentLine < lines.length) {
					result.push(lines[currentLine]);
					currentLine++;
				}

				var newContent = result.join('\n');

				var formData = new FormData();
				formData.append('file', filePath);
				formData.append('content', newContent);

				fetch(ajaxUrl + '&task=save', {
					method: 'POST',
					body: formData
				})
				.then(function(r) { return r.json(); })
				.then(function(resp) {
					var d = unwrapResponse(resp);
					if (d.success) {
						status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_ORDER_SAVED');
						status.style.color = '';
					} else if (d.syntax_error) {
						status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_NOT_SAVED_INVALID_PHP', d.message);
						status.style.color = '#d9534f';
					} else {
						status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_FAILED', d.message || 'Unknown error');
						status.style.color = '#d9534f';
					}
				})
				.catch(function(err) {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ERROR', err.message);
					status.style.color = '#d9534f';
				});
			}

			// First, check for dependency issues
			status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_CHECKING_DEPS');
			var checkData = new FormData();
			checkData.append('file', filePath);
			originalOrder.forEach(function(name) { checkData.append('original_order[]', name); });
			newOrderNames.forEach(function(name) { checkData.append('new_order[]', name); });

			fetch(ajaxUrl + '&task=check_reorder', {
				method: 'POST',
				body: checkData
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var d = unwrapResponse(resp);
				if (d.dependency_warning) {
					var msg = t('PLG_SYSTEM_VIEWBUILDER_JS_REORDER_WARNING') + '\n\n';
					d.warnings.forEach(function(w) {
						var vars = w.variables.map(function(v) { return '$' + v; }).join(', ');
						msg += '\u2022 "' + w.block + '" ' +
							(w.direction === 'loses_definitions'
								? t('PLG_SYSTEM_VIEWBUILDER_JS_DEP_USES', vars)
								: t('PLG_SYSTEM_VIEWBUILDER_JS_DEP_DEFINES', vars)) + '\n';
					});
					msg += '\n' + t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ANYWAY');
					if (!confirm(msg)) {
						status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_CANCELLED');
						return;
					}
				}
				// Proceed with actual save
				doSaveReorder();
			})
			.catch(function(err) {
				// If check fails, still allow save (fail-open)
				console.warn('Dependency check failed:', err);
				doSaveReorder();
			});
		});

		closeBtn.addEventListener('click', closeModal);

		if (editorSwitchBtn) {
			editorSwitchBtn.addEventListener('click', function() {
				closeModal();
				openEditor(filePath, ajaxUrl);
			});
		}

		document.addEventListener('keydown', escHandler);
	}

	function escHandler(e) {
		if (e.key === 'Escape') {
			closeModal();
		}
	}

	function closeModal() {
		if (currentModal) {
			currentModal.remove();
			currentModal = null;
		}
		if (sortableInstance) {
			sortableInstance.destroy();
			sortableInstance = null;
		}
		document.removeEventListener('keydown', escHandler);
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	// ========================================================================
	// On-Page Mode
	// ========================================================================

	var onpageSortables = [];

	// Click handler for on-page edit buttons
	document.addEventListener('click', function(e) {
		var editBtn = e.target.closest('.vb-onpage-edit');
		if (editBtn) {
			e.preventDefault();
			e.stopPropagation();
			openBlockEditor(editBtn.dataset.vbBlock, editBtn.dataset.vbFile, editBtn.dataset.vbAjax);
		}
	});

	function openBlockEditor(blockName, filePath, ajaxUrl) {
		fetch(ajaxUrl + '&task=load_block&file=' + encodeURIComponent(filePath) + '&block=' + encodeURIComponent(blockName))
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var data = unwrapResponse(resp);
				if (!data.success) {
					alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR_LOADING_BLOCK', data.message || 'Unknown error'));
					return;
				}
				showBlockEditorModal(data, blockName, filePath, ajaxUrl);
			})
			.catch(function(err) {
				alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR', err.message));
			});
	}

	function showBlockEditorModal(data, blockName, filePath, ajaxUrl) {
		closeModal();

		var overlay = document.createElement('div');
		overlay.className = 'vb-modal-overlay';
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});

		var modal = document.createElement('div');
		modal.className = 'vb-modal';

		var shortName = filePath.replace(/\\/g, '/').split('/').slice(-3).join('/');

		var header = document.createElement('div');
		header.className = 'vb-modal-header';
		header.innerHTML = '<div class="vb-modal-title">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_TITLE', blockName)) + ' <span style="color:#999;font-size:11px;">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_IN_FILE', shortName)) + '</span></div>' +
			'<div class="vb-modal-actions">' +
			'<button type="button" class="vb-save-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_BLOCK')) + '</button>' +
			'<button type="button" class="vb-close-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_CLOSE')) + '</button>' +
			'</div>';

		var body = document.createElement('div');
		body.className = 'vb-modal-body';

		var textarea = document.createElement('textarea');
		textarea.value = data.content;
		textarea.spellcheck = false;
		textarea.addEventListener('keydown', function(e) {
			if (e.key === 'Tab') {
				e.preventDefault();
				var start = this.selectionStart;
				var end = this.selectionEnd;
				this.value = this.value.substring(0, start) + '\t' + this.value.substring(end);
				this.selectionStart = this.selectionEnd = start + 1;
			}
			if ((e.ctrlKey || e.metaKey) && e.key === 's') {
				e.preventDefault();
				saveBlock();
			}
		});
		body.appendChild(textarea);

		var status = document.createElement('div');
		status.className = 'vb-modal-status';
		status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_EDITING_BLOCK', blockName);

		modal.appendChild(header);
		modal.appendChild(body);
		modal.appendChild(status);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		currentModal = overlay;

		textarea.focus();

		var saveBtn = header.querySelector('.vb-save-btn');
		var closeBtn = header.querySelector('.vb-close-btn');

		function saveBlock() {
			status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVING_BLOCK');
			var formData = new FormData();
			formData.append('file', filePath);
			formData.append('block', blockName);
			formData.append('content', textarea.value);

			fetch(ajaxUrl + '&task=save_block', {
				method: 'POST',
				body: formData
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var d = unwrapResponse(resp);
				if (d.success) {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_SAVED');
					status.style.color = '';
				} else if (d.syntax_error) {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_NOT_SAVED', d.message);
					status.style.color = '#d9534f';
				} else {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_FAILED', d.message || 'Unknown error');
					status.style.color = '#d9534f';
				}
			})
			.catch(function(err) {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ERROR', err.message);
				status.style.color = '#d9534f';
			});
		}

		saveBtn.addEventListener('click', saveBlock);
		closeBtn.addEventListener('click', closeModal);
		document.addEventListener('keydown', escHandler);
	}

	/**
	 * Initialize on-page SortableJS for drag-and-drop block reordering.
	 * Groups blocks by file + depth, then sub-groups by parent DOM node.
	 * All containers for the same file+depth share a SortableJS group so
	 * blocks can be dragged between areas (e.g. top/left/right/bottom).
	 */
	function initOnPageSortable() {
		var blocks = document.querySelectorAll('.vb-onpage-block');
		if (blocks.length === 0) return;

		// Collect all blocks for the same file+depth in document order
		var fileDepthGroups = {};
		blocks.forEach(function(block) {
			var key = block.dataset.vbFile + '::' + block.dataset.vbDepth;
			if (!fileDepthGroups[key]) {
				fileDepthGroups[key] = [];
			}
			fileDepthGroups[key].push(block);
		});

		// For each file+depth group, sub-group by parentNode
		Object.keys(fileDepthGroups).forEach(function(key) {
			var groupBlocks = fileDepthGroups[key];
			var file = groupBlocks[0].dataset.vbFile;
			var depth = groupBlocks[0].dataset.vbDepth;
			var ajaxUrl = groupBlocks[0].dataset.vbAjax;

			// Sub-group by actual parent DOM node
			var parentMap = new Map();
			groupBlocks.forEach(function(block) {
				var parent = block.parentNode;
				if (!parentMap.has(parent)) {
					parentMap.set(parent, []);
				}
				parentMap.get(parent).push(block);
			});

			// Need at least 2 blocks total across all containers to enable dragging
			if (groupBlocks.length < 2) return;

			// Shared group name so SortableJS allows cross-container dragging
			var groupName = 'vb-' + key.replace(/[^a-zA-Z0-9]/g, '_');

			// Build an onEnd handler shared by all containers in this group.
			// When a block is dropped, we determine its "after" block by looking
			// at the previous sibling in the target container. If there is no
			// previous sibling block in the target container, we need to find
			// the last block in the preceding container (in document order)
			// that belongs to the same file+depth.
			var onEndHandler = function(evt) {
				var movedBlock = evt.item;
				var blockName = movedBlock.dataset.vbBlock;

				// Find the "after" block: the block just before in the same container
				var prevSibling = movedBlock.previousElementSibling;
				var afterBlock = '';
				while (prevSibling) {
					if (prevSibling.classList.contains('vb-onpage-block') &&
						prevSibling.dataset.vbFile === file &&
						prevSibling.dataset.vbDepth === depth) {
						afterBlock = prevSibling.dataset.vbBlock;
						break;
					}
					prevSibling = prevSibling.previousElementSibling;
				}

				// If no previous sibling in this container, check if there is a
				// preceding container. The block should go after the last block
				// of the previous container (in document order).
				if (!afterBlock) {
					var targetParent = movedBlock.parentNode;
					var parents = Array.from(parentMap.keys());
					var targetIdx = parents.indexOf(targetParent);
					for (var pi = targetIdx - 1; pi >= 0; pi--) {
						var prevParent = parents[pi];
						// Find the last matching block in this parent
						var children = prevParent.querySelectorAll(
							'.vb-onpage-block[data-vb-file="' + file.replace(/"/g, '\\"') + '"][data-vb-depth="' + depth + '"]'
						);
						if (children.length > 0) {
							afterBlock = children[children.length - 1].dataset.vbBlock;
							break;
						}
					}
				}

				// Send AJAX move request
				var formData = new FormData();
				formData.append('file', file);
				formData.append('block', blockName);
				formData.append('after', afterBlock);

				fetch(ajaxUrl + '&task=move_block', {
					method: 'POST',
					body: formData
				})
				.then(function(r) { return r.json(); })
				.then(function(resp) {
					var d = unwrapResponse(resp);
					if (d.success) {
						showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_MOVED'), 'success');
					} else if (d.dependency_warning) {
						var msg = t('PLG_SYSTEM_VIEWBUILDER_JS_MOVE_WARNING') + '\n\n';
						d.warnings.forEach(function(w) {
							var vars = w.variables.map(function(v) { return '$' + v; }).join(', ');
							msg += '\u2022 "' + w.block + '" ' +
								(w.direction === 'loses_definitions'
									? t('PLG_SYSTEM_VIEWBUILDER_JS_DEP_USES_VARS', vars)
									: t('PLG_SYSTEM_VIEWBUILDER_JS_DEP_DEFINES_VARS', vars)) + '\n';
						});
						msg += '\n' + t('PLG_SYSTEM_VIEWBUILDER_JS_PROCEED_ANYWAY');

						if (confirm(msg)) {
							var forceData = new FormData();
							forceData.append('file', file);
							forceData.append('block', blockName);
							forceData.append('after', afterBlock);
							forceData.append('force', '1');
							fetch(ajaxUrl + '&task=move_block', {
								method: 'POST',
								body: forceData
							})
							.then(function(r) { return r.json(); })
							.then(function(resp2) {
								var d2 = unwrapResponse(resp2);
								if (d2.success) {
									showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_MOVED_SHORT'), 'success');
								} else {
									showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_MOVE_FAILED', d2.message || 'Error'), 'error');
									location.reload();
								}
							});
						} else {
							location.reload();
						}
					} else if (d.syntax_error) {
						showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_MOVE_REVERTED', d.message), 'error');
						location.reload();
					} else {
						showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_MOVE_FAILED', d.message || 'Unknown error'), 'error');
						location.reload();
					}
				})
				.catch(function(err) {
					showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_MOVE_ERROR', err.message), 'error');
					location.reload();
				});
			};

			// Init Sortable on each parent container, all sharing the same group
			parentMap.forEach(function(siblingBlocks, parent) {
				var instance = Sortable.create(parent, {
					group: groupName,
					animation: 150,
					handle: '.vb-onpage-handle-' + depth,
					draggable: '.vb-onpage-block[data-vb-file="' + file.replace(/"/g, '\\"') + '"][data-vb-depth="' + depth + '"]',
					ghostClass: 'vb-onpage-ghost',
					dragClass: 'vb-onpage-drag',
					onStart: function() {
						document.body.classList.add('vb-dragging', 'vb-dragging-depth-' + depth);
					},
					onEnd: function(evt) {
						document.body.classList.remove('vb-dragging', 'vb-dragging-depth-' + depth);
						onEndHandler(evt);
					}
				});

				onpageSortables.push(instance);
			});
		});
	}

	function showOnPageNotification(message, type) {
		var notif = document.createElement('div');
		notif.className = 'vb-onpage-notification vb-onpage-notification-' + type;
		notif.textContent = message;
		document.body.appendChild(notif);
		setTimeout(function() {
			notif.classList.add('vb-onpage-notification-hide');
			setTimeout(function() { notif.remove(); }, 400);
		}, 3000);
	}

	// Initialize on-page sortable after DOM is ready and SortableJS is loaded
	function initOnPage() {
		if (document.querySelectorAll('.vb-onpage-block').length === 0) return;

		if (typeof Sortable !== 'undefined') {
			initOnPageSortable();
		} else {
			var script = document.createElement('script');
			script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
			script.onload = function() {
				initOnPageSortable();
			};
			document.head.appendChild(script);
		}
	}

	// Run after DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initOnPage);
	} else {
		initOnPage();
	}
})();
