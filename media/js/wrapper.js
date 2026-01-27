/**
 * View Builder - Frontend wrapper script
 * Handles edit buttons, modals, and builder UI
 */
(function() {
	'use strict';

	var currentModal = null;
	var sortableInstance = null;

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
					alert('Error loading file: ' + (data.message || 'Unknown error'));
					return;
				}
				showEditorModal(data, filePath, ajaxUrl);
			})
			.catch(function(err) {
				alert('Error: ' + err.message);
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
			(data.is_override ? ' <span style="color:#5cb85c;font-size:11px;">[Override]</span>' : ' <span style="color:#999;font-size:11px;">[Original]</span>') +
			'</div>' +
			'<div class="vb-modal-actions">' +
			(data.is_override ? '<button type="button" class="vb-revert-btn">Revert to Original</button>' : '') +
			'<button type="button" class="vb-builder-switch-btn">Builder</button>' +
			'<button type="button" class="vb-save-btn">Save as Override</button>' +
			'<button type="button" class="vb-close-btn">Close</button>' +
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
		status.textContent = 'File loaded. Use Ctrl+S to save.';

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
			status.textContent = 'Saving...';
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
					status.textContent = 'Saved successfully to: ' + (d.saved_to || 'override');
					status.style.color = '';
				} else if (d.syntax_error) {
					status.textContent = 'NOT SAVED \u2013 ' + d.message;
					status.style.color = '#d9534f';
				} else {
					status.textContent = 'Save failed: ' + (d.message || 'Unknown error');
					status.style.color = '#d9534f';
				}
			})
			.catch(function(err) {
				status.textContent = 'Save error: ' + err.message;
				status.style.color = '#d9534f';
			});
		}

		saveBtn.addEventListener('click', saveFile);
		closeBtn.addEventListener('click', closeModal);

		if (revertBtn) {
			revertBtn.addEventListener('click', function() {
				if (!confirm('Are you sure you want to delete the override and revert to the original file?')) return;
				status.textContent = 'Reverting...';
				fetch(ajaxUrl + '&task=revert&file=' + encodeURIComponent(filePath))
					.then(function(r) { return r.json(); })
					.then(function(resp) {
						var d = unwrapResponse(resp);
						if (d.success) {
							status.textContent = 'Reverted to original. Reloading...';
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
					alert('Error parsing file: ' + (data.message || 'Unknown error'));
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
				alert('Error: ' + err.message);
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
		header.innerHTML = '<div class="vb-modal-title">Builder: ' + escapeHtml(shortName) + '</div>' +
			'<div class="vb-modal-actions">' +
			'<button type="button" class="vb-save-btn">Save Order</button>' +
			'<button type="button" class="vb-builder-switch-btn">Code Editor</button>' +
			'<button type="button" class="vb-close-btn">Close</button>' +
			'</div>';

		var body = document.createElement('div');
		body.className = 'vb-modal-body';

		var container = document.createElement('div');
		container.className = 'vb-builder-container';

		var blockList = document.createElement('div');
		blockList.className = 'vb-builder-blocks';
		blockList.id = 'vb-builder-sortable';

		if (blocks.length === 0) {
			blockList.innerHTML = '<div style="padding:20px;color:#999;text-align:center;">No blocks detected in this file. Use the code editor instead, or add &lt;!-- BLOCK_NAME --&gt; / &lt;!-- EO BLOCK_NAME --&gt; delimiters to enable the builder.</div>';
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
		status.textContent = blocks.length + ' block(s) detected. Drag to reorder movable blocks.';

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
					status.textContent = 'Order changed. Click "Save Order" to apply.';
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
						status.textContent = 'Order changed. Click "Save Order" to apply.';
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

			status.textContent = 'Saving new order...';

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
					status.textContent = 'Order saved to override. Reload the page to see changes.';
					status.style.color = '';
				} else if (d.syntax_error) {
					status.textContent = 'NOT SAVED \u2013 The new order produces invalid PHP: ' + d.message;
					status.style.color = '#d9534f';
				} else {
					status.textContent = 'Save failed: ' + (d.message || 'Unknown error');
					status.style.color = '#d9534f';
				}
			})
			.catch(function(err) {
				status.textContent = 'Save error: ' + err.message;
				status.style.color = '#d9534f';
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
})();
