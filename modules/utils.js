'use strict';
/* global $:off */

/**
 * @constant
 */
const NEW_TOPIC_COMMENT_ID = 'new|' + mw.config.get( 'wgRelevantPageName' );

/**
 * @param {Node} node
 * @return {boolean} Node is a block element
 */
function isBlockElement( node ) {
	return node instanceof HTMLElement && ve.isBlockElement( node );
}

const solTransparentLinkRegexp = /(?:^|\s)mw:PageProp\/(?:Category|redirect|Language)(?=$|\s)/;

/**
 * @param {Node} node
 * @return {boolean} Node is considered a rendering-transparent node in Parsoid
 */
function isRenderingTransparentNode( node ) {
	const nextSibling = node.nextSibling;
	return (
		node.nodeType === Node.COMMENT_NODE ||
		node.nodeType === Node.ELEMENT_NODE && (
			node.tagName.toLowerCase() === 'meta' ||
			(
				node.tagName.toLowerCase() === 'link' &&
				solTransparentLinkRegexp.test( node.getAttribute( 'rel' ) || '' )
			) ||
			// Empty inline templates, e.g. tracking templates
			(
				node.tagName.toLowerCase() === 'span' &&
				( node.getAttribute( 'typeof' ) || '' ).split( ' ' ).indexOf( 'mw:Transclusion' ) !== -1 &&
				!htmlTrim( node.innerHTML ) &&
				(
					!nextSibling || nextSibling.nodeType !== Node.ELEMENT_NODE ||
					// Maybe we should be checking all of the about-grouped nodes to see if they're empty,
					// but that's prooobably not needed in practice, and it leads to a quadratic worst case.
					nextSibling.getAttribute( 'about' ) !== node.getAttribute( 'about' )
				)
			)
		)
	);
}

/**
 * @param {Node} node
 * @return {boolean} Node was added to the page by DiscussionTools
 */
function isOurGeneratedNode( node ) {
	return node.nodeType === Node.ELEMENT_NODE && (
		node.classList.contains( 'ext-discussiontools-init-replylink-buttons' ) ||
		node.hasAttribute( 'data-mw-comment-start' ) ||
		node.hasAttribute( 'data-mw-comment-end' )
	);
}

// Elements which can't have element children (but some may have text content).
const noElementChildrenElementTypes = [
	// https://html.spec.whatwg.org/multipage/syntax.html#elements-2
	// Void elements
	'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
	'link', 'meta', 'param', 'source', 'track', 'wbr',
	// Raw text elements
	'script', 'style',
	// Escapable raw text elements
	'textarea', 'title',
	// Foreign elements
	'math', 'svg',
	// Treated like text when scripting is enabled in the parser
	// https://html.spec.whatwg.org/#the-noscript-element
	'noscript',
	// Replaced elements (that aren't already included above)
	// https://html.spec.whatwg.org/multipage/rendering.html#replaced-elements
	// They might allow element children, but they aren't rendered on the page.
	'audio', 'canvas', 'iframe', 'object', 'video'
];

/**
 * @param {Node} node
 * @return {boolean} If true, node can't have element children. If false, it's complicated.
 */
function cantHaveElementChildren( node ) {
	return (
		node.nodeType === Node.COMMENT_NODE ||
		node.nodeType === Node.ELEMENT_NODE && (
			noElementChildrenElementTypes.indexOf( node.tagName.toLowerCase() ) !== -1 ||
			// Thumbnail wrappers generated by MediaTransformOutput::linkWrap (T301427),
			// for compatibility with TimedMediaHandler.
			// There is no better way to detect them, and we can't insert markers here,
			// because the media DOM CSS depends on specific tag names and their order :(
			// TODO See if we can remove this condition when wgParserEnableLegacyMediaDOM=false
			// is enabled everywhere.
			(
				[ 'a', 'span' ].indexOf( node.tagName.toLowerCase() ) !== -1 &&
				node.firstChild &&
				cantHaveElementChildren( node.firstChild )
			) ||
			// Do not insert anything inside figures when using wgParserEnableLegacyMediaDOM=false,
			// because their CSS can't handle it (T320285).
			node.tagName.toLowerCase() === 'figure'
		)
	);
}

/**
 * Check whether the node is a comment separator (instead of a part of the comment).
 *
 * @param {Node} node
 * @return {boolean}
 */
function isCommentSeparator( node ) {
	if ( node.nodeType !== Node.ELEMENT_NODE ) {
		return false;
	}

	const tagName = node.tagName.toLowerCase();
	if ( tagName === 'br' || tagName === 'hr' ) {
		return true;
	}

	// TemplateStyles followed by any of the others
	if ( node.nextSibling &&
		( tagName === 'link' || tagName === 'style' ) &&
		isCommentSeparator( node.nextSibling )
	) {
		return true;
	}

	const classList = node.classList;
	if (
		// Anything marked as not containing comments
		classList.contains( 'mw-notalk' ) ||
		// {{outdent}} templates
		classList.contains( 'outdent-template' ) ||
		// {{tracked}} templates (T313097)
		classList.contains( 'mw-trackedTemplate' )
	) {
		return true;
	}

	// Wikitext definition list term markup (`;`) when used as a fake heading (T265964)
	if ( tagName === 'dl' &&
		node.childNodes.length === 1 &&
		node.firstChild.nodeType === Node.ELEMENT_NODE &&
		node.firstChild.nodeName.toLowerCase() === 'dt'
	) {
		return true;
	}

	return false;
}

/**
 * Check whether the node is a comment content. It's a little vague what this means…
 *
 * @param {Node} node Node, should be a leaf node (a node with no children)
 * @return {boolean}
 */
function isCommentContent( node ) {
	return (
		( node.nodeType === Node.TEXT_NODE && htmlTrim( node.textContent ) !== '' ) ||
		( cantHaveElementChildren( node ) )
	);
}

/**
 * Get the index of a node in its parentNode's childNode list
 *
 * @param {Node} child
 * @return {number} Index in parentNode's childNode list
 */
function childIndexOf( child ) {
	let i = 0;
	while ( ( child = child.previousSibling ) ) {
		i++;
	}
	return i;
}

/**
 * Find closest ancestor element using one of the given tag names.
 *
 * @param {Node} node
 * @param {string[]} tagNames
 * @return {HTMLElement|null}
 */
function closestElement( node, tagNames ) {
	do {
		if (
			node.nodeType === Node.ELEMENT_NODE &&
			tagNames.indexOf( node.tagName.toLowerCase() ) !== -1
		) {
			return node;
		}
		node = node.parentNode;
	} while ( node );
	return null;
}

/**
 * Find the transclusion node which rendered the current node, if it exists.
 *
 * 1. Find the closest ancestor with an 'about' attribute
 * 2. Find the main node of the about-group (first sibling with the same 'about' attribute)
 * 3. If this is an mw:Transclusion node, return it; otherwise, go to step 1
 *
 * @param {Node} node
 * @return {HTMLElement|null} Transclusion node, null if not found
 */
function getTranscludedFromElement( node ) {
	while ( node ) {
		// 1.
		if (
			node.nodeType === Node.ELEMENT_NODE &&
			node.getAttribute( 'about' ) &&
			/^#mwt\d+$/.test( node.getAttribute( 'about' ) )
		) {
			const about = node.getAttribute( 'about' );

			// 2.
			while (
				node.previousSibling &&
				node.previousSibling.nodeType === Node.ELEMENT_NODE &&
				node.previousSibling.getAttribute( 'about' ) === about
			) {
				node = node.previousSibling;
			}

			// 3.
			if (
				node.getAttribute( 'typeof' ) &&
				node.getAttribute( 'typeof' ).split( ' ' ).indexOf( 'mw:Transclusion' ) !== -1
			) {
				break;
			}
		}

		node = node.parentNode;
	}
	return node;
}

/**
 * Given a heading node, return the node on which the ID attribute is set.
 *
 * @param {HTMLElement} heading Heading node (`<h1>`-`<h6>`)
 * @return {HTMLElement}
 */
function getHeadlineNode( heading ) {
	// This code assumes that $wgFragmentMode is [ 'html5', 'legacy' ] or [ 'html5' ]
	let headline = heading;

	if ( headline.hasAttribute( 'data-mw-comment-start' ) ) {
		// JS only: Support output from the PHP CommentFormatter
		headline = headline.parentNode;
	}

	if ( !headline.getAttribute( 'id' ) ) {
		// JS only: Support output after HandleSectionLinks OutputTransform has been applied
		headline = headline.querySelector( '.mw-headline' );
		if ( !headline ) {
			headline = heading;
		}
	}

	return headline;
}

/**
 * Trim ASCII whitespace, as defined in the HTML spec.
 *
 * @param {string} str
 * @return {string}
 */
function htmlTrim( str ) {
	// https://infra.spec.whatwg.org/#ascii-whitespace
	return str.replace( /^[\t\n\f\r ]+/, '' ).replace( /[\t\n\f\r ]+$/, '' );
}

/**
 * Get the indent level of the node, relative to rootNode.
 *
 * The indent level is the number of lists inside of which it is nested.
 *
 * @private
 * @param {Node} node
 * @param {Element} rootNode
 * @return {number}
 */
function getIndentLevel( node, rootNode ) {
	let indent = 0;
	while ( node ) {
		if ( node === rootNode ) {
			break;
		}
		const tagName = node instanceof HTMLElement ? node.tagName.toLowerCase() : null;
		if ( tagName === 'li' || tagName === 'dd' ) {
			indent++;
		}
		node = node.parentNode;
	}
	return indent;
}

/**
 * Get an array of sibling nodes that contain parts of the given range.
 *
 * @param {Range} range
 * @return {Node[]}
 */
function getCoveredSiblings( range ) {
	const ancestor = range.commonAncestorContainer;

	const siblings = ancestor.childNodes;
	let start = 0;
	let end = siblings.length - 1;

	// Find first of the siblings that contains the item
	if ( ancestor === range.startContainer ) {
		start = range.startOffset;
	} else {
		while ( !siblings[ start ].contains( range.startContainer ) ) {
			start++;
		}
	}

	// Find last of the siblings that contains the item
	if ( ancestor === range.endContainer ) {
		end = range.endOffset - 1;
	} else {
		while ( !siblings[ end ].contains( range.endContainer ) ) {
			end--;
		}
	}

	return Array.prototype.slice.call( siblings, start, end + 1 );
}

/**
 * Get the nodes (if any) that contain the given thread item, and nothing else.
 *
 * @param {ThreadItem} item Thread item
 * @param {Node} [excludedAncestorNode] Node that shouldn't be included in the result, even if it
 *     contains the item and nothing else. This is intended to avoid traversing outside of a node
 *     which is a container for all the thread items.
 * @return {Node[]|null}
 */
function getFullyCoveredSiblings( item, excludedAncestorNode ) {
	let siblings = getCoveredSiblings( item.getRange() );

	function makeRange( sibs ) {
		const range = sibs[ 0 ].ownerDocument.createRange();
		range.setStartBefore( sibs[ 0 ] );
		range.setEndAfter( sibs[ sibs.length - 1 ] );
		return range;
	}

	const matches = compareRanges( makeRange( siblings ), item.getRange() ) === 'equal';

	if ( matches ) {
		// If these are all of the children (or the only child), go up one more level
		let parent;
		while (
			( parent = siblings[ 0 ].parentNode ) &&
			parent !== excludedAncestorNode &&
			compareRanges( makeRange( [ parent ] ), item.getRange() ) === 'equal'
		) {
			siblings = [ parent ];
		}
		return siblings;
	}
	return null;
}

/**
 * Get a MediaWiki page title from a URL.
 *
 * @private
 * @param {string} url Absolute URL
 * @return {string|null} Page title, or null if this isn't a link to a page
 */
function getTitleFromUrl( url ) {
	if ( !url ) {
		return null;
	}
	const parsedUrl = new URL( url );
	if ( parsedUrl.searchParams.get( 'title' ) ) {
		return parsedUrl.searchParams.get( 'title' );
	}

	// wgArticlePath is site config so is trusted
	// eslint-disable-next-line security/detect-non-literal-regexp
	const articlePathRegexp = new RegExp(
		mw.util.escapeRegExp( mw.config.get( 'wgArticlePath' ) )
			.replace( '\\$1', '(.*)' )
	);
	let match;
	if ( ( match = parsedUrl.pathname.match( articlePathRegexp ) ) ) {
		return decodeURIComponent( match[ 1 ] );
	}

	return null;
}

/**
 * Traverse the document in depth-first order, calling the callback whenever entering and leaving
 * a node. The walk starts before the given node and ends when callback returns a truthy value, or
 * after reaching the end of the document.
 *
 * You might also think about this as processing XML token stream linearly (rather than XML
 * nodes), as if we were parsing the document.
 *
 * @param {Node} node Node to start at
 * @param {Function} callback Function accepting two arguments: `event` ('enter' or 'leave') and
 *     `node` (DOMNode)
 * @return {any} Final return value of the callback
 */
function linearWalk( node, callback ) {
	let
		result = null,
		withinNode = node.parentNode,
		beforeNode = node;

	while ( beforeNode || withinNode ) {
		if ( beforeNode ) {
			result = callback( 'enter', beforeNode );
			withinNode = beforeNode;
			beforeNode = beforeNode.firstChild;
		} else {
			result = callback( 'leave', withinNode );
			beforeNode = withinNode.nextSibling;
			withinNode = withinNode.parentNode;
		}

		if ( result ) {
			return result;
		}
	}
	return result;
}

/**
 * Like #linearWalk, but it goes backwards.
 *
 * @inheritdoc #linearWalk
 */
function linearWalkBackwards( node, callback ) {
	let
		result = null,
		withinNode = node.parentNode,
		beforeNode = node;

	while ( beforeNode || withinNode ) {
		if ( beforeNode ) {
			result = callback( 'enter', beforeNode );
			withinNode = beforeNode;
			beforeNode = beforeNode.lastChild;
		} else {
			result = callback( 'leave', withinNode );
			beforeNode = withinNode.previousSibling;
			withinNode = withinNode.parentNode;
		}

		if ( result ) {
			return result;
		}
	}
	return result;
}

/**
 * @param {Range} range
 * @return {Node}
 */
function getRangeFirstNode( range ) {
	return range.startContainer.childNodes.length ?
		range.startContainer.childNodes[ range.startOffset ] :
		range.startContainer;
}

/**
 * @param {Range} range
 * @return {Node}
 */
function getRangeLastNode( range ) {
	return range.endContainer.childNodes.length ?
		range.endContainer.childNodes[ range.endOffset - 1 ] :
		range.endContainer;
}

/**
 * Check whether two ranges overlap, and how.
 *
 * Includes a hack to check for "almost equal" ranges (whose start/end boundaries only differ by
 * "uninteresting" nodes that we ignore when detecting comments), and treat them as equal.
 *
 * Illustration of return values:
 *          [    equal    ]
 *          |[ contained ]|
 *        [ |  contains   | ]
 *  [overlap|start]       |
 *          |     [overlap|end]
 * [before] |             |
 *          |             | [after]
 *
 * @param {Range} a
 * @param {Range} b
 * @return {string} One of:
 *     - 'equal': Ranges A and B are equal
 *     - 'contains': Range A contains range B
 *     - 'contained': Range A is contained within range B
 *     - 'after': Range A is before range B
 *     - 'before': Range A is after range B
 *     - 'overlapstart': Start of range A overlaps range B
 *     - 'overlapend': End of range A overlaps range B
 */
function compareRanges( a, b ) {
	// Compare the positions of: start of A to start of B, start of A to end of B, and so on.
	// Watch out, the constant names are the opposite of what they should be.
	let startToStart = a.compareBoundaryPoints( Range.START_TO_START, b );
	const startToEnd = a.compareBoundaryPoints( Range.END_TO_START, b );
	const endToStart = a.compareBoundaryPoints( Range.START_TO_END, b );
	let endToEnd = a.compareBoundaryPoints( Range.END_TO_END, b );

	// Check for almost equal ranges (boundary points only differing by uninteresting nodes)
	if (
		( startToStart < 0 && compareRangesAlmostEqualBoundaries( a, b, 'start' ) ) ||
		( startToStart > 0 && compareRangesAlmostEqualBoundaries( b, a, 'start' ) )
	) {
		startToStart = 0;
	}
	if (
		( endToEnd < 0 && compareRangesAlmostEqualBoundaries( a, b, 'end' ) ) ||
		( endToEnd > 0 && compareRangesAlmostEqualBoundaries( b, a, 'end' ) )
	) {
		endToEnd = 0;
	}

	if ( startToStart === 0 && endToEnd === 0 ) {
		return 'equal';
	}
	if ( startToStart <= 0 && endToEnd >= 0 ) {
		return 'contains';
	}
	if ( startToStart >= 0 && endToEnd <= 0 ) {
		return 'contained';
	}
	if ( startToEnd >= 0 ) {
		return 'after';
	}
	if ( endToStart <= 0 ) {
		return 'before';
	}
	if ( startToStart > 0 && startToEnd < 0 && endToEnd >= 0 ) {
		return 'overlapstart';
	}
	if ( endToEnd < 0 && endToStart > 0 && startToStart <= 0 ) {
		return 'overlapend';
	}

	throw new Error( 'Unreachable' );
}

/**
 * Check if the given boundary points of ranges A and B are almost equal (only differing by
 * uninteresting nodes).
 *
 * Boundary of A must be before the boundary of B in the tree.
 *
 * @param {Range} a
 * @param {Range} b
 * @param {string} boundary 'start' or 'end'
 * @return {boolean}
 */
function compareRangesAlmostEqualBoundaries( a, b, boundary ) {
	// This code is awful, but several attempts to rewrite it made it even worse.
	// You're welcome to give it a try.

	const from = boundary === 'end' ? getRangeLastNode( a ) : getRangeFirstNode( a );
	const to = boundary === 'end' ? getRangeLastNode( b ) : getRangeFirstNode( b );

	let skipNode = null;
	if ( boundary === 'end' ) {
		skipNode = from;
	}

	let foundContent = false;
	linearWalk(
		from,
		( event, n ) => {
			if ( n === to && event === ( boundary === 'end' ? 'leave' : 'enter' ) ) {
				return true;
			}
			if ( skipNode ) {
				if ( n === skipNode && event === 'leave' ) {
					skipNode = null;
				}
				return;
			}

			if ( event === 'enter' ) {
				if (
					isCommentSeparator( n ) ||
					isRenderingTransparentNode( n ) ||
					isOurGeneratedNode( n )
				) {
					skipNode = n;

				} else if (
					isCommentContent( n )
				) {
					foundContent = true;
					return true;
				}
			}
		}
	);

	return !foundContent;
}

/**
 * Get the ID for a new topics subscription from a page title
 *
 * @param {mw.Title} title Page title
 * @return {string} ID for a new topics subscription
 */
function getNewTopicsSubscriptionId( title ) {
	return 'p-topics-' + title.getNamespaceId() + ':' + title.getMain();
}

/**
 * Check whether a jQuery event represents a plain left click, without any modifiers
 *
 * @param {jQuery.Event} e
 * @return {boolean} Whether it was an unmodified left click
 */
function isUnmodifiedLeftClick( e ) {
	return e.which === OO.ui.MouseButtons.LEFT && !( e.shiftKey || e.altKey || e.ctrlKey || e.metaKey );
}

module.exports = {
	NEW_TOPIC_COMMENT_ID: NEW_TOPIC_COMMENT_ID,
	isBlockElement: isBlockElement,
	isRenderingTransparentNode: isRenderingTransparentNode,
	isCommentSeparator: isCommentSeparator,
	isCommentContent: isCommentContent,
	cantHaveElementChildren: cantHaveElementChildren,
	childIndexOf: childIndexOf,
	closestElement: closestElement,
	getIndentLevel: getIndentLevel,
	getCoveredSiblings: getCoveredSiblings,
	getFullyCoveredSiblings: getFullyCoveredSiblings,
	getTranscludedFromElement: getTranscludedFromElement,
	getHeadlineNode: getHeadlineNode,
	htmlTrim: htmlTrim,
	getTitleFromUrl: getTitleFromUrl,
	linearWalk: linearWalk,
	linearWalkBackwards: linearWalkBackwards,
	compareRanges: compareRanges,
	getNewTopicsSubscriptionId: getNewTopicsSubscriptionId,
	isUnmodifiedLeftClick: isUnmodifiedLeftClick
};
