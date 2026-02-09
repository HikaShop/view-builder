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

	// Click handler for on-page edit and reset buttons
	document.addEventListener('click', function(e) {
		var editBtn = e.target.closest('.vb-onpage-edit');
		if (editBtn) {
			e.preventDefault();
			e.stopPropagation();
			openBlockEditor(editBtn.dataset.vbBlock, editBtn.dataset.vbFile, editBtn.dataset.vbAjax);
			return;
		}

		var deleteBtn = e.target.closest('.vb-onpage-delete');
		if (deleteBtn) {
			e.preventDefault();
			e.stopPropagation();
			var blockName = deleteBtn.dataset.vbBlock;
			var displayName = blockName.replace(/_/g, ' ');
			if (!confirm(t('PLG_SYSTEM_VIEWBUILDER_JS_CONFIRM_DELETE_BLOCK', displayName))) return;
			showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_DELETING_BLOCK'), 'success');
			var formData = new FormData();
			formData.append('file', deleteBtn.dataset.vbFile);
			formData.append('block', blockName);
			fetch(deleteBtn.dataset.vbAjax + '&task=delete_block', {
				method: 'POST',
				body: formData
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var d = unwrapResponse(resp);
				if (d.success) {
					showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_BLOCK_DELETED'), 'success');
					setTimeout(function() { location.reload(); }, 1000);
				} else {
					showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_DELETE_FAILED', d.message || 'Error'), 'error');
				}
			})
			.catch(function(err) {
				showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_DELETE_FAILED', err.message), 'error');
			});
			return;
		}

		var resetBtn = e.target.closest('.vb-onpage-reset');
		if (resetBtn) {
			e.preventDefault();
			e.stopPropagation();
			if (!confirm(t('PLG_SYSTEM_VIEWBUILDER_JS_CONFIRM_RESET'))) return;
			showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_RESETTING'), 'success');
			fetch(resetBtn.dataset.vbAjax + '&task=revert&file=' + encodeURIComponent(resetBtn.dataset.vbFile))
				.then(function(r) { return r.json(); })
				.then(function(resp) {
					var d = unwrapResponse(resp);
					if (d.success) {
						showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_RESET_SUCCESS'), 'success');
						setTimeout(function() { location.reload(); }, 1000);
					} else {
						showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_RESET_FAILED', d.message || 'Error'), 'error');
					}
				})
				.catch(function(err) {
					showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_RESET_FAILED', err.message), 'error');
				});
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

	// ========================================================================
	// Form Field Builder
	// ========================================================================

	var formFieldSortables = [];

	// Click handler for form field edit, delete buttons
	document.addEventListener('click', function(e) {
		var editBtn = e.target.closest('.vb-form-field-edit');
		if (editBtn) {
			e.preventDefault();
			e.stopPropagation();
			openFormFieldEditor(editBtn.dataset.vbForm, editBtn.dataset.vbField, editBtn.dataset.vbAjax);
			return;
		}

		var deleteBtn = e.target.closest('.vb-form-field-delete');
		if (deleteBtn) {
			e.preventDefault();
			e.stopPropagation();
			var fieldName = deleteBtn.dataset.vbField;
			if (!confirm(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_CONFIRM_DELETE_FIELD', fieldName))) return;
			var formData = new FormData();
			formData.append('form', deleteBtn.dataset.vbForm);
			formData.append('field', fieldName);
			fetch(deleteBtn.dataset.vbAjax + '&task=delete_form_field', {
				method: 'POST',
				body: formData
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var d = unwrapResponse(resp);
				if (d.success) {
					showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_DELETED'), 'success');
					setTimeout(function() { location.reload(); }, 1000);
				} else {
					showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_DELETE_FAILED', d.message || 'Error'), 'error');
				}
			})
			.catch(function(err) {
				showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_DELETE_FAILED', err.message), 'error');
			});
			return;
		}

		// Popup mode: form-level Edit button
		var formEditBtn = e.target.closest('.vb-form-edit-btn');
		if (formEditBtn) {
			e.preventDefault();
			e.stopPropagation();
			openFormXmlEditor(formEditBtn.dataset.vbForm, formEditBtn.dataset.vbAjax);
			return;
		}

		// Popup mode: form-level Builder button
		var formBuilderBtn = e.target.closest('.vb-form-builder-btn');
		if (formBuilderBtn) {
			e.preventDefault();
			e.stopPropagation();
			openFormBuilder(formBuilderBtn.dataset.vbForm, formBuilderBtn.dataset.vbAjax);
			return;
		}

		// Form override revert button (both modes)
		var revertBtn = e.target.closest('.vb-form-revert-btn');
		if (revertBtn) {
			e.preventDefault();
			e.stopPropagation();
			var formName = revertBtn.dataset.vbForm;
			if (!confirm(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_CONFIRM_REVERT'))) return;
			fetch(revertBtn.dataset.vbAjax + '&task=revert_form&form=' + encodeURIComponent(formName))
				.then(function(r) { return r.json(); })
				.then(function(resp) {
					var d = unwrapResponse(resp);
					if (d.success) {
						showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_REVERTED'), 'success');
						setTimeout(function() { location.reload(); }, 1000);
					} else {
						showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_XML_SAVE_FAILED', d.message || 'Error'), 'error');
					}
				})
				.catch(function(err) {
					showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_XML_SAVE_FAILED', err.message), 'error');
				});
			return;
		}
	});

	function openFormFieldEditor(formName, fieldName, ajaxUrl) {
		fetch(ajaxUrl + '&task=load_form_field_xml&form=' + encodeURIComponent(formName) + '&field=' + encodeURIComponent(fieldName))
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var data = unwrapResponse(resp);
				if (!data.success) {
					alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR', data.message || 'Unknown error'));
					return;
				}
				showFormFieldEditorModal(data, formName, fieldName, ajaxUrl);
			})
			.catch(function(err) {
				alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR', err.message));
			});
	}

	function showFormFieldEditorModal(data, formName, fieldName, ajaxUrl) {
		closeModal();

		var overlay = document.createElement('div');
		overlay.className = 'vb-modal-overlay';
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});

		var modal = document.createElement('div');
		modal.className = 'vb-modal';

		var header = document.createElement('div');
		header.className = 'vb-modal-header';
		header.innerHTML = '<div class="vb-modal-title">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_EDIT_TITLE', fieldName)) + ' <span style="color:#999;font-size:11px;">' + escapeHtml(formName) + '</span></div>' +
			'<div class="vb-modal-actions">' +
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
				saveFormField();
			}
		});
		body.appendChild(textarea);

		var status = document.createElement('div');
		status.className = 'vb-modal-status';
		status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_LOADED');

		modal.appendChild(header);
		modal.appendChild(body);
		modal.appendChild(status);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		currentModal = overlay;
		textarea.focus();

		var saveBtn = header.querySelector('.vb-save-btn');
		var closeBtn = header.querySelector('.vb-close-btn');

		function saveFormField() {
			status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_SAVING');
			var formData = new FormData();
			formData.append('form', formName);
			formData.append('field', fieldName);
			formData.append('content', textarea.value);

			fetch(ajaxUrl + '&task=save_form_field_xml', {
				method: 'POST',
				body: formData
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var d = unwrapResponse(resp);
				if (d.success) {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_SAVED');
					status.style.color = '';
				} else {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_SAVE_FAILED', d.message || 'Unknown error');
					status.style.color = '#d9534f';
				}
			})
			.catch(function(err) {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ERROR', err.message);
				status.style.color = '#d9534f';
			});
		}

		saveBtn.addEventListener('click', saveFormField);
		closeBtn.addEventListener('click', closeModal);
		document.addEventListener('keydown', escHandler);
	}

	function openFormXmlEditor(formName, ajaxUrl) {
		fetch(ajaxUrl + '&task=load_form_xml&form=' + encodeURIComponent(formName))
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var data = unwrapResponse(resp);
				if (!data.success) {
					alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR_LOADING_FILE', data.message || 'Unknown error'));
					return;
				}
				showFormXmlEditorModal(data, formName, ajaxUrl);
			})
			.catch(function(err) {
				alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR', err.message));
			});
	}

	function showFormXmlEditorModal(data, formName, ajaxUrl) {
		closeModal();

		var overlay = document.createElement('div');
		overlay.className = 'vb-modal-overlay';
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});

		var modal = document.createElement('div');
		modal.className = 'vb-modal';

		var header = document.createElement('div');
		header.className = 'vb-modal-header';
		header.innerHTML = '<div class="vb-modal-title">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_EDIT_TITLE', formName)) +
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
				saveFormXml();
			}
		});
		body.appendChild(textarea);

		var status = document.createElement('div');
		status.className = 'vb-modal-status';
		status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_XML_LOADED');

		modal.appendChild(header);
		modal.appendChild(body);
		modal.appendChild(status);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		currentModal = overlay;
		textarea.focus();

		var saveBtn = header.querySelector('.vb-save-btn');
		var closeBtn = header.querySelector('.vb-close-btn');
		var revertBtn = header.querySelector('.vb-revert-btn');
		var builderSwitchBtn = header.querySelector('.vb-builder-switch-btn');

		function saveFormXml() {
			status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_SAVING_XML');
			var formData = new FormData();
			formData.append('form', formName);
			formData.append('content', textarea.value);

			fetch(ajaxUrl + '&task=save_form_xml', {
				method: 'POST',
				body: formData
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var d = unwrapResponse(resp);
				if (d.success) {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_XML_SAVED');
					status.style.color = '';
				} else {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_XML_SAVE_FAILED', d.message || 'Unknown error');
					status.style.color = '#d9534f';
				}
			})
			.catch(function(err) {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ERROR', err.message);
				status.style.color = '#d9534f';
			});
		}

		saveBtn.addEventListener('click', saveFormXml);
		closeBtn.addEventListener('click', closeModal);

		if (revertBtn) {
			revertBtn.addEventListener('click', function() {
				if (!confirm(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_CONFIRM_REVERT'))) return;
				fetch(ajaxUrl + '&task=revert_form&form=' + encodeURIComponent(formName))
					.then(function(r) { return r.json(); })
					.then(function(resp) {
						var d = unwrapResponse(resp);
						if (d.success) {
							status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_REVERTED');
							setTimeout(function() { location.reload(); }, 1000);
						}
					});
			});
		}

		if (builderSwitchBtn) {
			builderSwitchBtn.addEventListener('click', function() {
				closeModal();
				openFormBuilder(formName, ajaxUrl);
			});
		}

		document.addEventListener('keydown', escHandler);
	}

	function openFormBuilder(formName, ajaxUrl) {
		fetch(ajaxUrl + '&task=parse_form&form=' + encodeURIComponent(formName))
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var data = unwrapResponse(resp);
				if (!data.success) {
					alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR_PARSING', data.message || 'Unknown error'));
					return;
				}
				showFormBuilderModal(data, formName, ajaxUrl);
			})
			.catch(function(err) {
				alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR', err.message));
			});
	}

	function showFormBuilderModal(parseData, formName, ajaxUrl) {
		closeModal();

		var overlay = document.createElement('div');
		overlay.className = 'vb-modal-overlay';
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});

		var modal = document.createElement('div');
		modal.className = 'vb-modal';
		var fields = parseData.fields || [];

		var header = document.createElement('div');
		header.className = 'vb-modal-header';
		header.innerHTML = '<div class="vb-modal-title">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_BUILDER_TITLE', formName)) + '</div>' +
			'<div class="vb-modal-actions">' +
			'<button type="button" class="vb-save-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_SAVE_ORDER')) + '</button>' +
			'<button type="button" class="vb-builder-switch-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_CODE_EDITOR')) + '</button>' +
			'<button type="button" class="vb-close-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_CLOSE')) + '</button>' +
			'</div>';

		var body = document.createElement('div');
		body.className = 'vb-modal-body';

		var container = document.createElement('div');
		container.className = 'vb-builder-container';

		// Group fields by fieldset
		var fieldsets = {};
		var fieldsetOrder = [];
		fields.forEach(function(field) {
			var fs = field.fieldset || 'default';
			if (!fieldsets[fs]) {
				fieldsets[fs] = [];
				fieldsetOrder.push(fs);
			}
			fieldsets[fs].push(field);
		});

		fieldsetOrder.forEach(function(fsName) {
			var fsLabel = document.createElement('div');
			fsLabel.className = 'vb-form-fieldset-label';
			fsLabel.textContent = fsName;
			container.appendChild(fsLabel);

			var blockList = document.createElement('div');
			blockList.className = 'vb-builder-blocks vb-form-builder-fieldset';
			blockList.dataset.fieldset = fsName;

			fieldsets[fsName].forEach(function(field) {
				var item = document.createElement('div');
				item.className = 'vb-block-item';
				item.dataset.fieldName = field.name;
				item.dataset.fieldset = fsName;
				item.innerHTML =
					'<span class="vb-block-grip">&#9776;</span>' +
					'<span class="vb-block-name">' + escapeHtml(field.name) + '</span>' +
					'<span class="vb-block-type vb-type-layout">' + escapeHtml(field.type) + '</span>' +
					'<span class="vb-block-lines">' + escapeHtml(field.label) + '</span>';
				blockList.appendChild(item);
			});

			container.appendChild(blockList);
		});

		body.appendChild(container);

		var status = document.createElement('div');
		status.className = 'vb-modal-status';
		status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELDS_DETECTED', fields.length);

		modal.appendChild(header);
		modal.appendChild(body);
		modal.appendChild(status);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		currentModal = overlay;

		// Initialize SortableJS on each fieldset list
		function initFormSortable() {
			var fieldsetLists = container.querySelectorAll('.vb-form-builder-fieldset');
			fieldsetLists.forEach(function(list) {
				Sortable.create(list, {
					animation: 150,
					handle: '.vb-block-grip',
					ghostClass: 'sortable-ghost',
					dragClass: 'sortable-drag',
					onEnd: function() {
						status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_ORDER_CHANGED');
					}
				});
			});
		}

		if (typeof Sortable !== 'undefined') {
			initFormSortable();
		} else {
			var script = document.createElement('script');
			script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
			script.onload = initFormSortable;
			document.head.appendChild(script);
		}

		// Wire buttons
		var saveBtn = header.querySelector('.vb-save-btn');
		var closeBtn = header.querySelector('.vb-close-btn');
		var editorSwitchBtn = header.querySelector('.vb-builder-switch-btn');

		saveBtn.addEventListener('click', function() {
			// Collect new order per fieldset and send move requests
			var fieldsetLists = container.querySelectorAll('.vb-form-builder-fieldset');
			var changed = false;
			var promises = [];

			fieldsetLists.forEach(function(list) {
				var fsName = list.dataset.fieldset;
				var items = list.querySelectorAll('.vb-block-item');
				var newOrder = [];
				items.forEach(function(item) { newOrder.push(item.dataset.fieldName); });

				// Compare with original
				var origFields = fieldsets[fsName] || [];
				var origOrder = origFields.map(function(f) { return f.name; });
				var orderChanged = origOrder.some(function(name, i) { return name !== newOrder[i]; });

				if (!orderChanged) return;
				changed = true;

				// Send move requests sequentially for this fieldset
				// We'll rebuild the full order by sending save_form_xml with reordered XML
			});

			if (!changed) {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_NO_CHANGES');
				return;
			}

			// Load full XML, reorder fields per fieldset, and save
			status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVING_ORDER');
			fetch(ajaxUrl + '&task=load_form_xml&form=' + encodeURIComponent(formName))
				.then(function(r) { return r.json(); })
				.then(function(resp) {
					var d = unwrapResponse(resp);
					if (!d.success) {
						status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_FAILED', d.message || 'Error');
						status.style.color = '#d9534f';
						return;
					}

					// Parse and reorder XML
					var parser = new DOMParser();
					var xmlDoc = parser.parseFromString(d.content, 'text/xml');

					fieldsetLists.forEach(function(list) {
						var fsName = list.dataset.fieldset;
						var items = list.querySelectorAll('.vb-block-item');
						var newOrder = [];
						items.forEach(function(item) { newOrder.push(item.dataset.fieldName); });

						// Find the fieldset element in the XML
						var fsElements = xmlDoc.querySelectorAll('fieldset[name="' + fsName + '"]');
						if (fsElements.length === 0) return;
						var fsEl = fsElements[0];

						// Extract field elements
						var fieldMap = {};
						var fieldNodes = fsEl.querySelectorAll(':scope > field');
						fieldNodes.forEach(function(node) {
							fieldMap[node.getAttribute('name')] = node;
						});

						// Remove all field elements
						fieldNodes.forEach(function(node) { fsEl.removeChild(node); });

						// Re-add in new order
						newOrder.forEach(function(name) {
							if (fieldMap[name]) {
								fsEl.appendChild(fieldMap[name]);
							}
						});
					});

					// Serialize back to string
					var serializer = new XMLSerializer();
					var newXml = serializer.serializeToString(xmlDoc.documentElement);

					// Format with basic indentation
					newXml = formatXml(newXml);

					var formData = new FormData();
					formData.append('form', formName);
					formData.append('content', newXml);

					return fetch(ajaxUrl + '&task=save_form_xml', {
						method: 'POST',
						body: formData
					});
				})
				.then(function(r) { if (r) return r.json(); })
				.then(function(resp) {
					if (!resp) return;
					var d = unwrapResponse(resp);
					if (d.success) {
						status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_ORDER_SAVED');
						status.style.color = '';
					} else {
						status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_FAILED', d.message || 'Error');
						status.style.color = '#d9534f';
					}
				})
				.catch(function(err) {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_SAVE_ERROR', err.message);
					status.style.color = '#d9534f';
				});
		});

		closeBtn.addEventListener('click', closeModal);

		if (editorSwitchBtn) {
			editorSwitchBtn.addEventListener('click', function() {
				closeModal();
				openFormXmlEditor(formName, ajaxUrl);
			});
		}

		document.addEventListener('keydown', escHandler);
	}

	/**
	 * Basic XML formatter — adds newlines and indentation.
	 */
	function formatXml(xml) {
		var formatted = '';
		var indent = '';
		var tab = '\t';
		xml.split(/>\s*</).forEach(function(node) {
			if (node.match(/^\/\w/)) {
				indent = indent.substring(tab.length);
			}
			formatted += indent + '<' + node + '>\n';
			if (node.match(/^<?\w[^>]*[^\/]$/) && !node.match(/^(input|br|hr|img|meta|link)/i)) {
				indent += tab;
			}
		});
		return formatted.substring(1, formatted.length - 2);
	}

	/**
	 * Initialize on-page sortable for form fields.
	 * Groups fields by form name + fieldset for drag-and-drop reordering.
	 */
	function initFormFieldSortable() {
		var fields = document.querySelectorAll('.vb-form-field-onpage');
		if (fields.length === 0) return;

		// Group fields by form
		var formGroups = {};
		fields.forEach(function(field) {
			var formName = field.dataset.vbForm;
			if (!formGroups[formName]) {
				formGroups[formName] = [];
			}
			formGroups[formName].push(field);
		});

		Object.keys(formGroups).forEach(function(formName) {
			var groupFields = formGroups[formName];
			if (groupFields.length < 2) return;

			var ajaxUrl = groupFields[0].dataset.vbAjax;

			// Sub-group by parent DOM node
			var parentMap = new Map();
			groupFields.forEach(function(field) {
				var parent = field.parentNode;
				if (!parentMap.has(parent)) {
					parentMap.set(parent, []);
				}
				parentMap.get(parent).push(field);
			});

			var groupNameSafe = 'vb-form-' + formName.replace(/[^a-zA-Z0-9]/g, '_');

			parentMap.forEach(function(siblings, parent) {
				if (siblings.length < 2) return;

				var instance = Sortable.create(parent, {
					group: groupNameSafe,
					animation: 150,
					handle: '.vb-form-field-handle',
					draggable: '.vb-form-field-onpage[data-vb-form="' + formName.replace(/"/g, '\\"') + '"]',
					ghostClass: 'vb-form-field-ghost',
					dragClass: 'vb-form-field-drag',
					onStart: function() {
						document.body.classList.add('vb-form-dragging');
					},
					onEnd: function(evt) {
						document.body.classList.remove('vb-form-dragging');

						var movedField = evt.item;
						var fieldName = movedField.dataset.vbField;
						var fieldGroup = movedField.dataset.vbFieldGroup || '';

						// Find "after" field (previous sibling in new container)
						var prevSibling = movedField.previousElementSibling;
						var afterField = '';
						while (prevSibling) {
							if (prevSibling.classList.contains('vb-form-field-onpage') &&
								prevSibling.dataset.vbForm === formName) {
								afterField = prevSibling.dataset.vbField;
								break;
							}
							prevSibling = prevSibling.previousElementSibling;
						}

						// Find "before" field (next sibling) for cross-fieldset positioning
						var nextSibling = movedField.nextElementSibling;
						var beforeField = '';
						while (nextSibling) {
							if (nextSibling.classList.contains('vb-form-field-onpage') &&
								nextSibling.dataset.vbForm === formName) {
								beforeField = nextSibling.dataset.vbField;
								break;
							}
							nextSibling = nextSibling.nextElementSibling;
						}

						var formData = new FormData();
						formData.append('form', formName);
						formData.append('field', fieldName);
						formData.append('after', afterField);
						formData.append('before', beforeField);
						formData.append('fieldset', fieldGroup);

						fetch(ajaxUrl + '&task=move_form_field', {
							method: 'POST',
							body: formData
						})
						.then(function(r) { return r.json(); })
						.then(function(resp) {
							var d = unwrapResponse(resp);
							if (d.success) {
								showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_MOVED'), 'success');
							} else {
								showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_MOVE_FAILED', d.message || 'Error'), 'error');
								location.reload();
							}
						})
						.catch(function(err) {
							showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_FORM_FIELD_MOVE_FAILED', err.message), 'error');
							location.reload();
						});
					}
				});

				formFieldSortables.push(instance);
			});
		});
	}

	// Initialize form field sortable after DOM ready
	function initFormFields() {
		if (document.querySelectorAll('.vb-form-field-onpage').length === 0) return;

		if (typeof Sortable !== 'undefined') {
			initFormFieldSortable();
		} else {
			var script = document.createElement('script');
			script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
			script.onload = function() {
				initFormFieldSortable();
			};
			document.head.appendChild(script);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initFormFields);
	} else {
		initFormFields();
	}

	// ========================================================================
	// Translation Editor
	// ========================================================================

	var transEditorConfig = null;
	var transAjaxBase = '';

	function initTranslationEditor() {
		var config = Joomla.getOptions('viewbuilder.translationEditor');
		if (!config || !config.enabled) return;

		transEditorConfig = config;

		var translationsMap = Joomla.getOptions('viewbuilder.translations');
		if (!translationsMap || Object.keys(translationsMap).length === 0) return;

		// Build the AJAX base URL
		var token = Joomla.getOptions('csrf.token') || '';
		transAjaxBase = 'index.php?option=com_ajax&plugin=viewbuilder&group=system&format=json&' + token + '=1';

		// Walk DOM text nodes and wrap matches
		var skipTags = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1, INPUT: 1, SELECT: 1, OPTION: 1 };
		var skipClasses = ['vb-label', 'vb-modal', 'vb-modal-overlay', 'vb-translation-popup',
			'vb-trans-modal', 'vb-onpage-handle', 'vb-onpage-edit', 'vb-onpage-delete',
			'vb-form-field-label', 'vb-form-field-handle', 'vb-translation-edit-btn'];

		var walker = document.createTreeWalker(
			document.body,
			NodeFilter.SHOW_TEXT,
			{
				acceptNode: function(node) {
					var parent = node.parentNode;
					if (!parent || !parent.tagName) return NodeFilter.FILTER_REJECT;
					if (skipTags[parent.tagName]) return NodeFilter.FILTER_REJECT;

					// Skip VB UI elements
					var el = parent;
					while (el && el !== document.body) {
						if (el.classList) {
							for (var i = 0; i < skipClasses.length; i++) {
								if (el.classList.contains(skipClasses[i])) return NodeFilter.FILTER_REJECT;
							}
						}
						el = el.parentNode;
					}

					// Already wrapped
					if (parent.classList && parent.classList.contains('vb-translatable')) {
						return NodeFilter.FILTER_REJECT;
					}

					var text = node.textContent.trim();
					if (text.length < 2) return NodeFilter.FILTER_REJECT;

					return NodeFilter.FILTER_ACCEPT;
				}
			}
		);

		var textNodes = [];
		while (walker.nextNode()) {
			textNodes.push(walker.currentNode);
		}

		textNodes.forEach(function(node) {
			var text = node.textContent.trim();
			var normalized = text.toLowerCase();

			if (translationsMap[normalized]) {
				var keys = translationsMap[normalized];
				var wrap = document.createElement('vb-t');
				wrap.className = 'vb-translatable';
				wrap.setAttribute('data-vb-trans-keys', keys.join(','));
				node.parentNode.insertBefore(wrap, node);
				wrap.appendChild(node);
			}
		});

		// Delegated hover behavior
		document.body.addEventListener('mouseenter', function(e) {
			var el = e.target;
			if (!el.classList || !el.classList.contains('vb-translatable')) return;
			if (el.querySelector('.vb-translation-edit-btn')) return;

			var btn = document.createElement('button');
			btn.className = 'vb-translation-edit-btn';
			btn.type = 'button';
			btn.innerHTML = '&#9998;';
			btn.title = 'Edit translation';
			btn.addEventListener('click', function(ev) {
				ev.preventDefault();
				ev.stopPropagation();
				var keys = el.getAttribute('data-vb-trans-keys').split(',');
				openTranslationEditor(keys, el);
			});
			el.appendChild(btn);
		}, true);

		document.body.addEventListener('mouseleave', function(e) {
			var el = e.target;
			if (!el.classList || !el.classList.contains('vb-translatable')) return;
			var btn = el.querySelector('.vb-translation-edit-btn');
			if (btn) btn.remove();
		}, true);
	}

	function openTranslationEditor(keys, element) {
		if (keys.length > 1) {
			// Show key picker
			showKeyPicker(keys, element);
		} else {
			loadAndShowTranslation(keys[0], element);
		}
	}

	function showKeyPicker(keys, element) {
		closeModal();

		var overlay = document.createElement('div');
		overlay.className = 'vb-modal-overlay';
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});

		var modal = document.createElement('div');
		modal.className = 'vb-modal vb-trans-modal vb-trans-key-picker';

		var header = document.createElement('div');
		header.className = 'vb-modal-header';
		header.innerHTML = '<div class="vb-modal-title">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_PICK_KEY')) + '</div>' +
			'<div class="vb-modal-actions"><button type="button" class="vb-close-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_CLOSE')) + '</button></div>';

		var body = document.createElement('div');
		body.className = 'vb-modal-body';
		body.style.overflow = 'auto';
		body.style.padding = '16px';

		keys.forEach(function(key) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'vb-trans-key-option';
			btn.textContent = key;
			btn.addEventListener('click', function() {
				closeModal();
				loadAndShowTranslation(key, element);
			});
			body.appendChild(btn);
		});

		modal.appendChild(header);
		modal.appendChild(body);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		currentModal = overlay;

		header.querySelector('.vb-close-btn').addEventListener('click', closeModal);
		document.addEventListener('keydown', escHandler);
	}

	function loadAndShowTranslation(key, element) {
		closeModal();

		// Show loading overlay
		var overlay = document.createElement('div');
		overlay.className = 'vb-modal-overlay';
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});

		var modal = document.createElement('div');
		modal.className = 'vb-modal vb-trans-modal';

		var header = document.createElement('div');
		header.className = 'vb-modal-header';
		header.innerHTML = '<div class="vb-modal-title">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_LOADING')) + '</div>' +
			'<div class="vb-modal-actions"><button type="button" class="vb-close-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_CLOSE')) + '</button></div>';

		modal.appendChild(header);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		currentModal = overlay;

		header.querySelector('.vb-close-btn').addEventListener('click', closeModal);
		document.addEventListener('keydown', escHandler);

		var clientId = transEditorConfig.clientId || 0;
		fetch(transAjaxBase + '&task=load_translation&key=' + encodeURIComponent(key) + '&client_id=' + clientId)
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var data = unwrapResponse(resp);
				if (!data.success) {
					alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR', data.message || 'Unknown error'));
					closeModal();
					return;
				}
				showTranslationModal(data, key, element);
			})
			.catch(function(err) {
				alert(t('PLG_SYSTEM_VIEWBUILDER_JS_ERROR', err.message));
				closeModal();
			});
	}

	function showTranslationModal(data, key, element) {
		closeModal();

		var overlay = document.createElement('div');
		overlay.className = 'vb-modal-overlay';
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});

		var modal = document.createElement('div');
		modal.className = 'vb-modal vb-trans-modal';

		var header = document.createElement('div');
		header.className = 'vb-modal-header';
		header.innerHTML = '<div class="vb-modal-title">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_EDIT_TITLE', key)) + '</div>' +
			'<div class="vb-modal-actions">' +
			'<button type="button" class="vb-save-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVE')) + '</button>' +
			'<button type="button" class="vb-close-btn">' + escapeHtml(t('PLG_SYSTEM_VIEWBUILDER_JS_CLOSE')) + '</button>' +
			'</div>';

		var body = document.createElement('div');
		body.className = 'vb-modal-body';
		body.style.overflow = 'auto';
		body.style.padding = '16px';

		var keyDisplay = document.createElement('div');
		keyDisplay.className = 'vb-trans-key';
		keyDisplay.textContent = key;
		body.appendChild(keyDisplay);

		var languages = data.languages || [];
		languages.forEach(function(lang) {
			var row = document.createElement('div');
			row.className = 'vb-trans-lang-row';
			row.dataset.tag = lang.tag;

			var label = document.createElement('div');
			label.className = 'vb-trans-lang-label';
			label.textContent = lang.name + ' (' + lang.tag + ')';

			var input = document.createElement('input');
			input.type = 'text';
			input.className = 'vb-trans-lang-input';
			input.value = lang.value;
			input.dataset.tag = lang.tag;
			input.dataset.originalValue = lang.value;
			input.dataset.baseValue = lang.base_value;

			var badge = document.createElement('b');
			badge.className = 'vb-trans-lang-badge ' + (lang.is_override ? 'vb-trans-lang-badge-override' : 'vb-trans-lang-badge-original');
			badge.textContent = lang.is_override
				? t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_OVERRIDE')
				: t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_ORIGINAL');

			row.appendChild(label);
			row.appendChild(input);
			row.appendChild(badge);

			// Add remove override button if this language has an override
			if (lang.is_override) {
				var removeBtn = document.createElement('button');
				removeBtn.type = 'button';
				removeBtn.className = 'vb-trans-remove-btn';
				removeBtn.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_REMOVE_OVERRIDE');
				removeBtn.dataset.tag = lang.tag;
				removeBtn.addEventListener('click', function() {
					removeTranslationOverride(key, lang.tag, input, badge, removeBtn, status, element);
				});
				row.appendChild(removeBtn);
			}

			body.appendChild(row);
		});

		var status = document.createElement('div');
		status.className = 'vb-modal-status';
		status.textContent = '';

		modal.appendChild(header);
		modal.appendChild(body);
		modal.appendChild(status);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		currentModal = overlay;

		var saveBtn = header.querySelector('.vb-save-btn');
		var closeBtn = header.querySelector('.vb-close-btn');

		saveBtn.addEventListener('click', function() {
			var inputs = body.querySelectorAll('.vb-trans-lang-input');
			var translations = {};
			var hasChanges = false;

			inputs.forEach(function(inp) {
				if (inp.value !== inp.dataset.originalValue) {
					translations[inp.dataset.tag] = inp.value;
					hasChanges = true;
				}
			});

			if (!hasChanges) {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_NO_CHANGES');
				return;
			}

			status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVING');
			saveBtn.disabled = true;

			var formData = new FormData();
			formData.append('key', key);
			formData.append('client_id', transEditorConfig.clientId || 0);
			Object.keys(translations).forEach(function(tag) {
				formData.append('translations[' + tag + ']', translations[tag]);
			});

			fetch(transAjaxBase + '&task=save_translation', {
				method: 'POST',
				body: formData
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var d = unwrapResponse(resp);
				if (d.success) {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVED');
					status.style.color = '';
					saveBtn.disabled = false;

					// Update DOM text if current language was changed
					var currentLang = transEditorConfig.currentLang;
					if (translations[currentLang] !== undefined) {
						// Update the text node inside the element
						var textNodes = [];
						var tw = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
						while (tw.nextNode()) textNodes.push(tw.currentNode);
						if (textNodes.length > 0) {
							textNodes[0].textContent = translations[currentLang];
						}
					}

					// Update badges
					inputs.forEach(function(inp) {
						if (inp.value !== inp.dataset.originalValue) {
							inp.dataset.originalValue = inp.value;
							var badge = inp.parentNode.querySelector('.vb-trans-lang-badge');
							if (badge) {
								badge.className = 'vb-trans-lang-badge vb-trans-lang-badge-override';
								badge.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_OVERRIDE');
							}
						}
					});

					showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVED'), 'success');
				} else {
					status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVE_FAILED', d.message || 'Unknown error');
					status.style.color = '#d9534f';
					saveBtn.disabled = false;
				}
			})
			.catch(function(err) {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVE_FAILED', err.message);
				status.style.color = '#d9534f';
				saveBtn.disabled = false;
			});
		});

		closeBtn.addEventListener('click', closeModal);
		document.addEventListener('keydown', escHandler);
	}

	function removeTranslationOverride(key, tag, input, badge, removeBtn, status, element) {
		status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_REMOVING');
		removeBtn.disabled = true;

		var formData = new FormData();
		formData.append('key', key);
		formData.append('tag', tag);
		formData.append('client_id', transEditorConfig.clientId || 0);

		fetch(transAjaxBase + '&task=remove_translation_override', {
			method: 'POST',
			body: formData
		})
		.then(function(r) { return r.json(); })
		.then(function(resp) {
			var d = unwrapResponse(resp);
			if (d.success) {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_OVERRIDE_REMOVED');
				status.style.color = '';
				// Restore base value
				input.value = d.base_value || '';
				input.dataset.originalValue = d.base_value || '';
				input.dataset.baseValue = d.base_value || '';
				// Update badge to Original
				badge.className = 'vb-trans-lang-badge vb-trans-lang-badge-original';
				badge.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_ORIGINAL');
				// Remove the remove button
				removeBtn.remove();
				// Update DOM text if current language
				var currentLang = transEditorConfig.currentLang;
				if (tag === currentLang && d.base_value) {
					var textNodes = [];
					var tw = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
					while (tw.nextNode()) textNodes.push(tw.currentNode);
					if (textNodes.length > 0) {
						textNodes[0].textContent = d.base_value;
					}
				}
				showOnPageNotification(t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_OVERRIDE_REMOVED'), 'success');
			} else {
				status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVE_FAILED', d.message || 'Error');
				status.style.color = '#d9534f';
				removeBtn.disabled = false;
			}
		})
		.catch(function(err) {
			status.textContent = t('PLG_SYSTEM_VIEWBUILDER_JS_TRANS_SAVE_FAILED', err.message);
			status.style.color = '#d9534f';
			removeBtn.disabled = false;
		});
	}

	// Initialize translation editor after DOM ready
	function initTransEditor() {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initTranslationEditor);
		} else {
			initTranslationEditor();
		}
	}
	initTransEditor();
})();
