/**
 * Semantic Link Inserter & Anchor Suggestion Gutenberg Sidebar
 *
 * @package AI_Post_Scheduler
 * @since 3.7.0
 */

(function (wp) {
	'use strict';

	if (!wp || !wp.plugins || !wp.element) {
		return;
	}

	const { createElement: el, useState, useEffect, useRef, useCallback, Fragment } = wp.element;
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost || wp.editor || {};
	const {
		PanelBody,
		Button,
		Spinner,
		Dashicon,
		Notice,
		Tooltip,
		RangeControl,
		SelectControl,
		TextControl
	} = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const apiFetch = wp.apiFetch;
	const __ = wp.i18n ? wp.i18n.__ : function (text) { return text; };

	const settings = window.aipsEditorSettings || {
		restUrl: '/wp-json/aips/v1/editor/',
		nonce: '',
		postId: 0,
		similarityMin: 0.60,
		maxSuggestions: 5,
		postTypes: [{ label: 'All Post Types', value: '' }],
		i18n: {}
	};

	const t = function (key, defaultText) {
		return (settings.i18n && settings.i18n[key]) ? settings.i18n[key] : defaultText;
	};

	/**
	 * Main Sidebar Component
	 */
	function SemanticLinkInserterSidebar() {
		const [suggestions, setSuggestions] = useState([]);
		const [isLoading, setIsLoading] = useState(false);
		const [errorMessage, setErrorMessage] = useState('');
		const [expandedPostId, setExpandedPostId] = useState(null);
		const [anchorsState, setAnchorsState] = useState({}); // { [postId]: { loading: bool, locations: [], error: '' } }
		const [insertedAnchors, setInsertedAnchors] = useState({});

		// Filter & search options state
		const [similarityThreshold, setSimilarityThreshold] = useState(settings.similarityMin || 0.60);
		const [maxSuggestions, setMaxSuggestions] = useState(settings.maxSuggestions || 5);
		const [selectedPostType, setSelectedPostType] = useState('');
		const [searchQuery, setSearchQuery] = useState('');

		const debounceTimerRef = useRef(null);

		// Get current editor state
		const { postId, postContent, activeBlock, allBlocks } = useSelect(function (select) {
			const editorSelect = select('core/editor');
			const blockSelect  = select('core/block-editor');

			return {
				postId: editorSelect ? editorSelect.getCurrentPostId() : settings.postId,
				postContent: editorSelect ? editorSelect.getEditedPostContent() : '',
				activeBlock: blockSelect ? blockSelect.getSelectedBlock() : null,
				allBlocks: blockSelect ? blockSelect.getBlocks() : []
			};
		}, []);

		const { updateBlockAttributes } = useDispatch('core/block-editor');
		const { createNotice } = useDispatch('core/notices');

		/**
		 * Fetch link suggestions based on current content & filters
		 */
		const fetchSuggestions = useCallback(function (forceContent) {
			const contentToScan = typeof forceContent === 'string' ? forceContent : postContent;
			const hasQuery = searchQuery && searchQuery.trim().length >= 2;

			if (!hasQuery && (!contentToScan || contentToScan.replace(/<[^>]*>/g, '').trim().length < 15)) {
				setSuggestions([]);
				return;
			}

			setIsLoading(true);
			setErrorMessage('');

			apiFetch({
				path: '/aips/v1/editor/link-suggestions',
				method: 'POST',
				data: {
					post_id: postId || 0,
					content: contentToScan,
					query: searchQuery ? searchQuery.trim() : '',
					target_post_type: selectedPostType || '',
					limit: maxSuggestions || 5,
					min_similarity: similarityThreshold || 0.60
				}
			})
			.then(function (response) {
				setIsLoading(false);
				if (response && response.success && Array.isArray(response.suggestions)) {
					setSuggestions(response.suggestions);
				} else {
					setSuggestions([]);
				}
			})
			.catch(function (error) {
				setIsLoading(false);
				setErrorMessage((error && error.message) ? error.message : 'Error fetching link suggestions.');
			});
		}, [postId, postContent, searchQuery, selectedPostType, maxSuggestions, similarityThreshold]);

		// Debounce suggestions fetch on content or filter changes
		useEffect(function () {
			if (debounceTimerRef.current) {
				clearTimeout(debounceTimerRef.current);
			}

			debounceTimerRef.current = setTimeout(function () {
				fetchSuggestions();
			}, 600);

			return function () {
				if (debounceTimerRef.current) {
					clearTimeout(debounceTimerRef.current);
				}
			};
		}, [postContent, searchQuery, selectedPostType, maxSuggestions, similarityThreshold, fetchSuggestions]);

		/**
		 * Fetch AI Anchor locations for a specific target post
		 */
		const fetchAnchorsForPost = function (targetPostId, targetTitle) {
			setAnchorsState(function (prev) {
				const next = Object.assign({}, prev);
				next[targetPostId] = { loading: true, locations: [], error: '' };
				return next;
			});

			// If active block is a paragraph and has text, analyze that or full content
			let textToAnalyze = '';
			if (activeBlock && activeBlock.name === 'core/paragraph' && activeBlock.attributes && activeBlock.attributes.content) {
				textToAnalyze = activeBlock.attributes.content;
			} else {
				textToAnalyze = postContent;
			}

			apiFetch({
				path: '/aips/v1/editor/find-anchors',
				method: 'POST',
				data: {
					source_content: textToAnalyze,
					target_post_id: targetPostId,
					limit: 3
				}
			})
			.then(function (response) {
				if (response && response.success && response.data && Array.isArray(response.data.locations)) {
					setAnchorsState(function (prev) {
						const next = Object.assign({}, prev);
						next[targetPostId] = {
							loading: false,
							locations: response.data.locations,
							targetUrl: response.data.target_url || '',
							error: response.data.locations.length === 0 ? t('noAnchorsFound', 'No natural anchor positions found.') : ''
						};
						return next;
					});
				} else {
					setAnchorsState(function (prev) {
						const next = Object.assign({}, prev);
						next[targetPostId] = { loading: false, locations: [], error: t('noAnchorsFound', 'No anchor locations found.') };
						return next;
					});
				}
			})
			.catch(function (error) {
				setAnchorsState(function (prev) {
					const next = Object.assign({}, prev);
					next[targetPostId] = {
						loading: false,
						locations: [],
						error: (error && error.message) ? error.message : 'Error finding anchor positions.'
					};
					return next;
				});
			});
		};

		/**
		 * Helper: Decode HTML entities for fuzzy matching
		 */
		const decodeEntities = function (html) {
			if (!html) {
				return '';
			}
			const txt = document.createElement('textarea');
			txt.innerHTML = html;
			return txt.value;
		};

		/**
		 * Execute 1-Click Link Insertion into Gutenberg block
		 */
		const handleInsertLink = function (targetUrl, locationObj, anchorCardKey) {
			if (!targetUrl || !locationObj) {
				return;
			}

			const rawMatch       = locationObj.match_snippet || '';
			const rawReplacement = locationObj.replacement_snippet || '';

			// Parse [[anchor]] from replacement snippet
			let anchorPhrase = '';
			const markerMatch = /\[\[(.*?)\]\]/.exec(rawReplacement);
			if (markerMatch && markerMatch[1]) {
				anchorPhrase = markerMatch[1];
			}

			if (!anchorPhrase) {
				return;
			}

			const anchorLinkHtml = '<a href="' + encodeURI(targetUrl) + '">' + anchorPhrase + '</a>';
			const targetReplacementHtml = rawReplacement.replace(/\[\[(.*?)\]\]/, anchorLinkHtml);

			const decodedMatch  = decodeEntities(rawMatch);
			const decodedAnchor = decodeEntities(anchorPhrase);

			let insertionApplied = false;

			// Helper to try replacing in block HTML
			const tryReplaceInContent = function (content) {
				if (!content) {
					return null;
				}
				if (content.indexOf(rawMatch) !== -1) {
					return content.replace(rawMatch, targetReplacementHtml);
				}
				if (decodedMatch && content.indexOf(decodedMatch) !== -1) {
					return content.replace(decodedMatch, targetReplacementHtml);
				}
				if (content.indexOf(anchorPhrase) !== -1) {
					return content.replace(anchorPhrase, anchorLinkHtml);
				}
				if (decodedAnchor && content.indexOf(decodedAnchor) !== -1) {
					return content.replace(decodedAnchor, anchorLinkHtml);
				}
				return null;
			};

			// Step 1: Check active block first
			if (activeBlock && activeBlock.name === 'core/paragraph' && activeBlock.attributes && activeBlock.attributes.content) {
				const blockHtml = activeBlock.attributes.content;
				const replacedHtml = tryReplaceInContent(blockHtml);

				if (replacedHtml !== null) {
					updateBlockAttributes(activeBlock.clientId, { content: replacedHtml });
					insertionApplied = true;
				}
			}

			// Step 2: Fallback to searching all paragraph blocks in the document
			if (!insertionApplied && Array.isArray(allBlocks)) {
				for (let i = 0; i < allBlocks.length; i++) {
					const block = allBlocks[i];
					if (block.name === 'core/paragraph' && block.attributes && block.attributes.content) {
						const blockHtml = block.attributes.content;
						const replacedHtml = tryReplaceInContent(blockHtml);

						if (replacedHtml !== null) {
							updateBlockAttributes(block.clientId, { content: replacedHtml });
							insertionApplied = true;
							break;
						}
					}
				}
			}

			if (insertionApplied) {
				setInsertedAnchors(function (prev) {
					const next = Object.assign({}, prev);
					next[anchorCardKey] = true;
					return next;
				});

				if (createNotice) {
					createNotice('success', t('linkInserted', 'Link inserted successfully!'), {
						type: 'snackbar',
						isDismissible: true
					});
				}
			} else {
				if (createNotice) {
					createNotice('warning', 'Could not locate matching text in paragraph blocks. Please highlight or place cursor in the target paragraph.', {
						type: 'snackbar',
						isDismissible: true
					});
				}
			}
		};

		/**
		 * Render highlighted snippet with [[anchor]] styled
		 */
		const renderSnippet = function (snippet) {
			if (!snippet) {
				return null;
			}

			const parts = snippet.split(/(\[\[.*?\]\])/);
			return parts.map(function (part, idx) {
				if (part.startsWith('[[') && part.endsWith(']]')) {
					const cleanText = part.slice(2, -2);
					return el('span', { key: idx, className: 'aips-anchor-highlight' }, cleanText);
				}
				return part;
			});
		};

		/**
		 * Get CSS class for similarity badge
		 */
		const getSimilarityClass = function (pct) {
			if (pct >= 80) {
				return 'is-high';
			}
			if (pct >= 68) {
				return 'is-medium';
			}
			return 'is-low';
		};

		return el(
			'div',
			{ className: 'aips-editor-sidebar-panel' },
			el('div', { className: 'aips-sidebar-toolbar' },
				el('span', { className: 'aips-status-pill' },
					el(Dashicon, { icon: 'admin-links', size: 14 }),
					suggestions.length + ' ' + t('similarity', 'Matches')
				),
				el(Button, {
					isSmall: true,
					variant: 'tertiary',
					icon: 'update',
					label: t('refresh', 'Refresh Suggestions'),
					onClick: function () { fetchSuggestions(); }
				})
			),
			el('p', { className: 'aips-sidebar-intro' },
				t('activeBlockNote', 'Context-aware internal link recommendations powered by semantic vector graph.')
			),

			el(PanelBody, {
				title: t('filtersTitle', 'Filters & Custom Search'),
				initialOpen: false
			},
				el(TextControl, {
					label: t('searchLabel', 'Topic / Keyword Search'),
					value: searchQuery,
					placeholder: t('searchPlaceholder', 'e.g. Docker caching, vector store...'),
					onChange: function (val) { setSearchQuery(val); }
				}),
				el(RangeControl, {
					label: t('similarityThresholdLabel', 'Min Similarity (%):'),
					value: Math.round(similarityThreshold * 100),
					min: 40,
					max: 90,
					step: 5,
					onChange: function (val) { setSimilarityThreshold(val / 100); }
				}),
				el(RangeControl, {
					label: t('maxSuggestionsLabel', 'Max Suggestions:'),
					value: maxSuggestions,
					min: 1,
					max: 15,
					step: 1,
					onChange: function (val) { setMaxSuggestions(val); }
				}),
				el(SelectControl, {
					label: t('postTypeLabel', 'Target Post Type:'),
					value: selectedPostType,
					options: settings.postTypes || [{ label: 'All Post Types', value: '' }],
					onChange: function (val) { setSelectedPostType(val); }
				}),
				el(Button, {
					isSecondary: true,
					isSmall: true,
					onClick: function () {
						setSearchQuery('');
						setSelectedPostType('');
						setSimilarityThreshold(settings.similarityMin || 0.60);
						setMaxSuggestions(settings.maxSuggestions || 5);
					}
				}, t('resetFilters', 'Reset Filters'))
			),

			errorMessage && el(Notice, {
				status: 'error',
				isDismissible: true,
				onDismiss: function () { setErrorMessage(''); }
			}, errorMessage),

			isLoading && el('div', { className: 'aips-loading-box' },
				el(Spinner, {}),
				el('span', {}, t('searching', 'Scanning semantic graph for relevant articles...'))
			),

			!isLoading && suggestions.length === 0 && el('div', { className: 'aips-empty-box' },
				el('p', {}, t('noSuggestions', 'No semantic link suggestions found yet. Keep writing or check back once more articles are indexed.'))
			),

			!isLoading && suggestions.length > 0 && el('div', { className: 'aips-suggestions-list' },
				suggestions.map(function (item) {
					const isExpanded  = expandedPostId === item.id;
					const anchorData  = anchorsState[item.id] || { loading: false, locations: [], error: '' };
					const simClass    = getSimilarityClass(item.similarity_pct);

					return el(
						'div',
						{
							key: item.id,
							className: 'aips-suggestion-card' + (isExpanded ? ' is-expanded' : '')
						},
						el('div', { className: 'aips-card-header' },
							el('h4', { className: 'aips-card-title' },
								el('a', {
									href: item.url,
									target: '_blank',
									rel: 'noopener noreferrer'
								}, item.title)
							),
							el('span', {
								className: 'aips-similarity-badge ' + simClass,
								title: item.is_precomputed ? t('precomputed', 'Precomputed') : t('realtime', 'Real-Time')
							}, item.similarity_pct + '%')
						),

						item.excerpt && el('p', { className: 'aips-card-excerpt' }, item.excerpt),

						el('div', { className: 'aips-card-actions' },
							el('span', { className: 'aips-card-type-tag' },
								item.post_type || 'post'
							),
							el(Button, {
								isSecondary: !isExpanded,
								isPrimary: isExpanded,
								isSmall: true,
								'aria-expanded': isExpanded,
								'aria-label': isExpanded ? __('Close anchor opportunities', 'ai-post-scheduler') : t('findAnchors', 'Find Insertion Anchors'),
								onClick: function () {
									const nextExpanded = isExpanded ? null : item.id;
									setExpandedPostId(nextExpanded);
									if (nextExpanded && (!anchorData.locations || anchorData.locations.length === 0)) {
										fetchAnchorsForPost(item.id, item.title);
									}
								}
							}, isExpanded ? __('Close Anchors', 'ai-post-scheduler') : t('findAnchors', 'Find Anchors'))
						),

						// Expanded Anchor Exploration Panel
						isExpanded && el('div', { className: 'aips-anchors-container' },
							el('div', { className: 'aips-anchors-title' },
								t('recommendedAnchor', 'Anchor Opportunities')
							),

							anchorData.loading && el('div', { className: 'aips-loading-box' },
								el(Spinner, {}),
								el('span', {}, t('findingAnchors', 'Analyzing text for anchor points...'))
							),

							anchorData.error && el('div', { className: 'aips-empty-box' },
								anchorData.error
							),

							!anchorData.loading && Array.isArray(anchorData.locations) && anchorData.locations.map(function (loc, locIdx) {
								const cardKey = item.id + '_' + locIdx;
								const isInserted = !!insertedAnchors[cardKey];

								return el(
									'div',
									{ key: locIdx, className: 'aips-anchor-card' },
									loc.reason && el('div', { className: 'aips-anchor-reason' }, loc.reason),
									el('div', { className: 'aips-anchor-snippet' },
										renderSnippet(loc.replacement_snippet || loc.match_snippet)
									),
									el(Button, {
										isPrimary: true,
										isSmall: true,
										icon: isInserted ? 'yes' : 'admin-links',
										disabled: isInserted,
										'aria-label': isInserted ? __('Link already inserted', 'ai-post-scheduler') : t('insertLink', 'Insert Link'),
										onClick: function () {
											handleInsertLink(item.url, loc, cardKey);
										}
									}, isInserted ? __('Inserted', 'ai-post-scheduler') : t('insertLink', 'Insert Link'))
								);
							})
						)
					);
				})
			)
		);
	}

	if (PluginSidebar && PluginSidebarMoreMenuItem) {
		registerPlugin('aips-semantic-link-inserter', {
			icon: 'admin-links',
			render: function () {
				return el(
					Fragment,
					{},
					el(PluginSidebarMoreMenuItem, {
						target: 'aips-semantic-link-inserter-sidebar',
						icon: 'admin-links'
					}, t('title', 'AI Link Inserter')),
					el(PluginSidebar, {
						name: 'aips-semantic-link-inserter-sidebar',
						title: t('panelTitle', 'Semantic Link Suggestions'),
						icon: 'admin-links'
					}, el(SemanticLinkInserterSidebar, {}))
				);
			}
		});
	}

})(window.wp);
