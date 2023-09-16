<?php

namespace MediaWiki\Extension\DiscussionTools;

use LogicException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\DiscussionTools\ThreadItem\ContentCommentItem;
use MediaWiki\Extension\DiscussionTools\ThreadItem\ContentThreadItem;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;
use Wikimedia\Assert\Assert;
use Wikimedia\Parsoid\DOM\Comment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\DOM\Text;
use Wikimedia\Parsoid\Utils\DOMCompat;

class CommentUtils {

	private function __construct() {
	}

	private const BLOCK_ELEMENT_TYPES = [
		'div', 'p',
		// Tables
		'table', 'tbody', 'thead', 'tfoot', 'caption', 'th', 'tr', 'td',
		// Lists
		'ul', 'ol', 'li', 'dl', 'dt', 'dd',
		// HTML5 heading content
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hgroup',
		// HTML5 sectioning content
		'article', 'aside', 'body', 'nav', 'section', 'footer', 'header', 'figure',
		'figcaption', 'fieldset', 'details', 'blockquote',
		// Other
		'hr', 'button', 'canvas', 'center', 'col', 'colgroup', 'embed',
		'map', 'object', 'pre', 'progress', 'video'
	];

	/**
	 * @param Node $node
	 * @return bool Node is a block element
	 */
	public static function isBlockElement( Node $node ): bool {
		return $node instanceof Element &&
			in_array( strtolower( $node->tagName ), static::BLOCK_ELEMENT_TYPES, true );
	}

	private const SOL_TRANSPARENT_LINK_REGEX =
		'/(?:^|\s)mw:PageProp\/(?:Category|redirect|Language)(?=$|\s)/D';

	/**
	 * @param Node $node
	 * @return bool Node is considered a rendering-transparent node in Parsoid
	 */
	public static function isRenderingTransparentNode( Node $node ): bool {
		$nextSibling = $node->nextSibling;
		return (
			$node instanceof Comment ||
			$node instanceof Element && (
				strtolower( $node->tagName ) === 'meta' ||
				(
					strtolower( $node->tagName ) === 'link' &&
					preg_match( static::SOL_TRANSPARENT_LINK_REGEX, $node->getAttribute( 'rel' ) ?? '' )
				) ||
				// Empty inline templates, e.g. tracking templates. (T269036)
				// But not empty nodes that are just the start of a non-empty template about-group. (T290940)
				(
					strtolower( $node->tagName ) === 'span' &&
					in_array( 'mw:Transclusion', explode( ' ', $node->getAttribute( 'typeof' ) ?? '' ), true ) &&
					!static::htmlTrim( DOMCompat::getInnerHTML( $node ) ) &&
					(
						!$nextSibling || !( $nextSibling instanceof Element ) ||
						// Maybe we should be checking all of the about-grouped nodes to see if they're empty,
						// but that's prooobably not needed in practice, and it leads to a quadratic worst case.
						$nextSibling->getAttribute( 'about' ) !== $node->getAttribute( 'about' )
					)
				)
			)
		);
	}

	/**
	 * @param Node $node
	 * @return bool Node was added to the page by DiscussionTools
	 */
	public static function isOurGeneratedNode( Node $node ): bool {
		return $node instanceof Element && (
			DOMCompat::getClassList( $node )->contains( 'ext-discussiontools-init-replylink-buttons' ) ||
			$node->hasAttribute( 'data-mw-comment-start' ) ||
			$node->hasAttribute( 'data-mw-comment-end' )
		);
	}

	/**
	 * Elements which can't have element children (but some may have text content).
	 */
	private const NO_ELEMENT_CHILDREN_ELEMENT_TYPES = [
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
		'audio', 'canvas', 'iframe', 'object', 'video',
	];

	/**
	 * @param Node $node
	 * @return bool If true, node can't have element children. If false, it's complicated.
	 */
	public static function cantHaveElementChildren( Node $node ): bool {
		return (
			$node instanceof Comment ||
			$node instanceof Element && (
				in_array( strtolower( $node->tagName ), static::NO_ELEMENT_CHILDREN_ELEMENT_TYPES, true ) ||
				// Thumbnail wrappers generated by MediaTransformOutput::linkWrap (T301427),
				// for compatibility with TimedMediaHandler.
				// There is no better way to detect them, and we can't insert markers here,
				// because the media DOM CSS depends on specific tag names and their order :(
				// TODO See if we can remove this condition when wgParserEnableLegacyMediaDOM=false
				// is enabled everywhere.
				(
					in_array( strtolower( $node->tagName ), [ 'a', 'span' ], true ) &&
					$node->firstChild &&
					// We always step inside a child node so this can't be infinite, silly Phan
					// @phan-suppress-next-line PhanInfiniteRecursion
					static::cantHaveElementChildren( $node->firstChild )
				) ||
				// Do not insert anything inside figures when using wgParserEnableLegacyMediaDOM=false,
				// because their CSS can't handle it (T320285).
				strtolower( $node->tagName ) === 'figure'
			)
		);
	}

	/**
	 * Check whether the node is a comment separator (instead of a part of the comment).
	 */
	public static function isCommentSeparator( Node $node ): bool {
		if ( !( $node instanceof Element ) ) {
			return false;
		}

		$tagName = strtolower( $node->tagName );
		if ( $tagName === 'br' || $tagName === 'hr' ) {
			return true;
		}

		// TemplateStyles followed by any of the others
		if ( $node->nextSibling &&
			( $tagName === 'link' || $tagName === 'style' ) &&
			self::isCommentSeparator( $node->nextSibling )
		) {
			return true;
		}

		$classList = DOMCompat::getClassList( $node );
		if (
			// Anything marked as not containing comments
			$classList->contains( 'mw-notalk' ) ||
			// {{outdent}} templates
			$classList->contains( 'outdent-template' ) ||
			// {{tracked}} templates (T313097)
			$classList->contains( 'mw-trackedTemplate' )
		) {
			return true;
		}

		// Wikitext definition list term markup (`;`) when used as a fake heading (T265964)
		if ( $tagName === 'dl' &&
			count( $node->childNodes ) === 1 &&
			$node->firstChild instanceof Element &&
			strtolower( $node->firstChild->nodeName ) === 'dt'
		) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether the node is a comment content. It's a little vague what this means…
	 *
	 * @param Node $node Node, should be a leaf node (a node with no children)
	 * @return bool
	 */
	public static function isCommentContent( Node $node ): bool {
		return (
			$node instanceof Text &&
			static::htmlTrim( $node->nodeValue ?? '' ) !== ''
		) ||
		(
			static::cantHaveElementChildren( $node )
		);
	}

	/**
	 * Get the index of $child in its parent
	 */
	public static function childIndexOf( Node $child ): int {
		$i = 0;
		while ( ( $child = $child->previousSibling ) ) {
			$i++;
		}
		return $i;
	}

	/**
	 * Check whether a Node contains (is an ancestor of) another Node (or is the same node)
	 */
	public static function contains( Node $ancestor, Node $descendant ): bool {
		// TODO can we use Node->compareDocumentPosition() here maybe?
		$node = $descendant;
		while ( $node && $node !== $ancestor ) {
			$node = $node->parentNode;
		}
		return $node === $ancestor;
	}

	/**
	 * Find closest ancestor element using one of the given tag names.
	 *
	 * @param Node $node
	 * @param string[] $tagNames
	 * @return Element|null
	 */
	public static function closestElement( Node $node, array $tagNames ): ?Element {
		do {
			if (
				$node instanceof Element &&
				in_array( strtolower( $node->tagName ), $tagNames, true )
			) {
				return $node;
			}
			$node = $node->parentNode;
		} while ( $node );
		return null;
	}

	/**
	 * Find closest ancestor element that has sibling nodes
	 *
	 * @param Node $node
	 * @param string $direction Can be 'next', 'previous', or 'either'
	 * @return Element|null
	 */
	public static function closestElementWithSibling( Node $node, string $direction ): ?Element {
		do {
			if (
				$node instanceof Element && (
					( $node->nextSibling && ( $direction === 'next' || $direction === 'either' ) ) ||
					( $node->previousSibling && ( $direction === 'previous' || $direction === 'either' ) )
				)
			) {
				return $node;
			}
			$node = $node->parentNode;
		} while ( $node );
		return null;
	}

	/**
	 * Find the transclusion node which rendered the current node, if it exists.
	 *
	 * 1. Find the closest ancestor with an 'about' attribute
	 * 2. Find the main node of the about-group (first sibling with the same 'about' attribute)
	 * 3. If this is an mw:Transclusion node, return it; otherwise, go to step 1
	 *
	 * @param Node $node
	 * @return Element|null Transclusion node, null if not found
	 */
	public static function getTranscludedFromElement( Node $node ): ?Element {
		while ( $node ) {
			// 1.
			if (
				$node instanceof Element &&
				$node->getAttribute( 'about' ) &&
				preg_match( '/^#mwt\d+$/', $node->getAttribute( 'about' ) ?? '' )
			) {
				$about = $node->getAttribute( 'about' );

				// 2.
				while (
					( $previousSibling = $node->previousSibling ) &&
					$previousSibling instanceof Element &&
					$previousSibling->getAttribute( 'about' ) === $about
				) {
					$node = $previousSibling;
				}

				// 3.
				if (
					$node->getAttribute( 'typeof' ) &&
					in_array( 'mw:Transclusion', explode( ' ', $node->getAttribute( 'typeof' ) ?? '' ), true )
				) {
					break;
				}
			}

			$node = $node->parentNode;
		}
		return $node;
	}

	/**
	 * Given a heading node, return the node on which the ID attribute is set.
	 *
	 * Also returns the offset within that node where the heading text starts.
	 *
	 * @param Element $heading Heading node (`<h1>`-`<h6>`)
	 * @return array Array containing a 'node' (Element) and offset (int)
	 */
	public static function getHeadlineNodeAndOffset( Element $heading ): array {
		// This code assumes that $wgFragmentMode is [ 'html5', 'legacy' ] or [ 'html5' ]
		$headline = $heading;
		$offset = 0;

		if ( $headline->hasAttribute( 'data-mw-comment-start' ) ) {
			$headline = $headline->parentNode;
			Assert::precondition( $headline !== null, 'data-mw-comment-start was attached to a heading' );
		}

		if ( !$headline->getAttribute( 'id' ) && !$headline->getAttribute( 'data-mw-anchor' ) ) {
			// PHP HTML: Find the child with .mw-headline
			$headline = DOMCompat::querySelector( $headline, '.mw-headline' );
			if ( !$headline ) {
				$headline = $heading;
			}
		}

		return [
			'node' => $headline,
			'offset' => $offset,
		];
	}

	/**
	 * Trim ASCII whitespace, as defined in the HTML spec.
	 */
	public static function htmlTrim( string $str ): string {
		// https://infra.spec.whatwg.org/#ascii-whitespace
		return trim( $str, "\t\n\f\r " );
	}

	/**
	 * Get the indent level of $node, relative to $rootNode.
	 *
	 * The indent level is the number of lists inside of which it is nested.
	 */
	public static function getIndentLevel( Node $node, Element $rootNode ): int {
		$indent = 0;
		while ( $node ) {
			if ( $node === $rootNode ) {
				break;
			}
			$tagName = $node instanceof Element ? strtolower( $node->tagName ) : null;
			if ( $tagName === 'li' || $tagName === 'dd' ) {
				$indent++;
			}
			$node = $node->parentNode;
		}
		return $indent;
	}

	/**
	 * Get an array of sibling nodes that contain parts of the given range.
	 *
	 * @param ImmutableRange $range
	 * @return Node[]
	 */
	public static function getCoveredSiblings( ImmutableRange $range ): array {
		$ancestor = $range->commonAncestorContainer;

		// Convert to array early because apparently NodeList acts like a linked list
		// and accessing items by index is slow
		$siblings = iterator_to_array( $ancestor->childNodes );
		$start = 0;
		$end = count( $siblings ) - 1;

		// Find first of the siblings that contains the item
		if ( $ancestor === $range->startContainer ) {
			$start = $range->startOffset;
		} else {
			while ( !static::contains( $siblings[ $start ], $range->startContainer ) ) {
				$start++;
			}
		}

		// Find last of the siblings that contains the item
		if ( $ancestor === $range->endContainer ) {
			$end = $range->endOffset - 1;
		} else {
			while ( !static::contains( $siblings[ $end ], $range->endContainer ) ) {
				$end--;
			}
		}

		return array_slice( $siblings, $start, $end - $start + 1 );
	}

	/**
	 * Get the nodes (if any) that contain the given thread item, and nothing else.
	 *
	 * @param ContentThreadItem $item
	 * @param ?Node $excludedAncestorNode Node that shouldn't be included in the result, even if it
	 *     contains the item and nothing else. This is intended to avoid traversing outside of a node
	 *     which is a container for all the thread items.
	 * @return Node[]|null
	 */
	public static function getFullyCoveredSiblings(
		ContentThreadItem $item, ?Node $excludedAncestorNode = null
	): ?array {
		$siblings = static::getCoveredSiblings( $item->getRange() );

		$makeRange = static function ( $siblings ) {
			return new ImmutableRange(
				$siblings[0]->parentNode,
				CommentUtils::childIndexOf( $siblings[0] ),
				end( $siblings )->parentNode,
				CommentUtils::childIndexOf( end( $siblings ) ) + 1
			);
		};

		$matches = static::compareRanges( $makeRange( $siblings ), $item->getRange() ) === 'equal';

		if ( $matches ) {
			// If these are all of the children (or the only child), go up one more level
			while (
				( $parent = $siblings[ 0 ]->parentNode ) &&
				$parent !== $excludedAncestorNode &&
				static::compareRanges( $makeRange( [ $parent ] ), $item->getRange() ) === 'equal'
			) {
				$siblings = [ $parent ];
			}
			return $siblings;
		}
		return null;
	}

	/**
	 * Unwrap Parsoid sections
	 *
	 * @param Element $element Parent element, e.g. document body
	 */
	public static function unwrapParsoidSections( Element $element ): void {
		$sections = DOMCompat::querySelectorAll( $element, 'section[data-mw-section-id]' );
		foreach ( $sections as $section ) {
			$parent = $section->parentNode;
			while ( $section->firstChild ) {
				$parent->insertBefore( $section->firstChild, $section );
			}
			$parent->removeChild( $section );
		}
	}

	/**
	 * Get a MediaWiki page title from a URL
	 *
	 * @param string $url Relative URL (from a `href` attribute)
	 * @param Config $config Config settings needed to resolve the relative URL
	 * @return string|null
	 */
	public static function getTitleFromUrl( string $url, Config $config ): ?string {
		// Protocol-relative URLs are handled really badly by parse_url()
		if ( str_starts_with( $url, '//' ) ) {
			$url = "http:$url";
		}

		$bits = parse_url( $url );
		$query = wfCgiToArray( $bits['query'] ?? '' );
		if ( isset( $query['title'] ) ) {
			return $query['title'];
		}

		// TODO: Set the correct base in the document?
		$articlePath = $config->get( MainConfigNames::ArticlePath );
		if ( str_starts_with( $url, './' ) ) {
			// Assume this is URL in the format used by Parsoid documents
			$url = substr( $url, 2 );
			$path = str_replace( '$1', $url, $articlePath );
		} elseif ( !str_contains( $url, '://' ) ) {
			// Assume this is URL in the format used by legacy parser documents
			$path = $url;
		} else {
			// External link
			$path = $bits['path'] ?? '';
		}

		$articlePathRegexp = '/^' . str_replace(
			'\\$1',
			'([^?]*)',
			preg_quote( $articlePath, '/' )
		) . '/';
		$matches = null;
		if ( preg_match( $articlePathRegexp, $path, $matches ) ) {
			return urldecode( $matches[1] );
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
	 * @param Node $node Node to start at
	 * @param callable $callback Function accepting two arguments: $event ('enter' or 'leave') and
	 *     $node (Node)
	 * @return mixed Final return value of the callback
	 */
	public static function linearWalk( Node $node, callable $callback ) {
		$result = null;
		[ $withinNode, $beforeNode ] = [ $node->parentNode, $node ];

		while ( $beforeNode || $withinNode ) {
			if ( $beforeNode ) {
				$result = $callback( 'enter', $beforeNode );
				[ $withinNode, $beforeNode ] = [ $beforeNode, $beforeNode->firstChild ];
			} else {
				$result = $callback( 'leave', $withinNode );
				[ $withinNode, $beforeNode ] = [ $withinNode->parentNode, $withinNode->nextSibling ];
			}

			if ( $result ) {
				return $result;
			}
		}
		return $result;
	}

	/**
	 * Like #linearWalk, but it goes backwards.
	 *
	 * @inheritDoc ::linearWalk()
	 */
	public static function linearWalkBackwards( Node $node, callable $callback ) {
		$result = null;
		[ $withinNode, $beforeNode ] = [ $node->parentNode, $node ];

		while ( $beforeNode || $withinNode ) {
			if ( $beforeNode ) {
				$result = $callback( 'enter', $beforeNode );
				[ $withinNode, $beforeNode ] = [ $beforeNode, $beforeNode->lastChild ];
			} else {
				$result = $callback( 'leave', $withinNode );
				[ $withinNode, $beforeNode ] = [ $withinNode->parentNode, $withinNode->previousSibling ];
			}

			if ( $result ) {
				return $result;
			}
		}
		return $result;
	}

	/**
	 * @param ImmutableRange $range (must not be collapsed)
	 * @return Node
	 */
	public static function getRangeFirstNode( ImmutableRange $range ): Node {
		Assert::precondition( !$range->collapsed, 'Range is not collapsed' );
		// PHP bug: childNodes can be null
		return $range->startContainer->childNodes && $range->startContainer->childNodes->length ?
			$range->startContainer->childNodes[ $range->startOffset ] :
			$range->startContainer;
	}

	/**
	 * @param ImmutableRange $range (must not be collapsed)
	 * @return Node
	 */
	public static function getRangeLastNode( ImmutableRange $range ): Node {
		Assert::precondition( !$range->collapsed, 'Range is not collapsed' );
		// PHP bug: childNodes can be null
		return $range->endContainer->childNodes && $range->endContainer->childNodes->length ?
			$range->endContainer->childNodes[ $range->endOffset - 1 ] :
			$range->endContainer;
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
	 * @param ImmutableRange $a
	 * @param ImmutableRange $b
	 * @return string One of:
	 *     - 'equal': Ranges A and B are equal
	 *     - 'contains': Range A contains range B
	 *     - 'contained': Range A is contained within range B
	 *     - 'after': Range A is before range B
	 *     - 'before': Range A is after range B
	 *     - 'overlapstart': Start of range A overlaps range B
	 *     - 'overlapend': End of range A overlaps range B
	 */
	public static function compareRanges( ImmutableRange $a, ImmutableRange $b ): string {
		// Compare the positions of: start of A to start of B, start of A to end of B, and so on.
		// Watch out, the constant names are the opposite of what they should be.
		$startToStart = $a->compareBoundaryPoints( ImmutableRange::START_TO_START, $b );
		$startToEnd = $a->compareBoundaryPoints( ImmutableRange::END_TO_START, $b );
		$endToStart = $a->compareBoundaryPoints( ImmutableRange::START_TO_END, $b );
		$endToEnd = $a->compareBoundaryPoints( ImmutableRange::END_TO_END, $b );

		// Handle almost equal ranges: When start or end boundary points of the two ranges are different,
		// but only differ by "uninteresting" nodes, treat them as equal instead.
		if (
			( $startToStart < 0 && static::compareRangesAlmostEqualBoundaries( $a, $b, 'start' ) ) ||
			( $startToStart > 0 && static::compareRangesAlmostEqualBoundaries( $b, $a, 'start' ) )
		) {
			$startToStart = 0;
		}
		if (
			( $endToEnd < 0 && static::compareRangesAlmostEqualBoundaries( $a, $b, 'end' ) ) ||
			( $endToEnd > 0 && static::compareRangesAlmostEqualBoundaries( $b, $a, 'end' ) )
		) {
			$endToEnd = 0;
		}

		if ( $startToStart === 0 && $endToEnd === 0 ) {
			return 'equal';
		}
		if ( $startToStart <= 0 && $endToEnd >= 0 ) {
			return 'contains';
		}
		if ( $startToStart >= 0 && $endToEnd <= 0 ) {
			return 'contained';
		}
		if ( $startToEnd >= 0 ) {
			return 'after';
		}
		if ( $endToStart <= 0 ) {
			return 'before';
		}
		if ( $startToStart > 0 && $startToEnd < 0 && $endToEnd >= 0 ) {
			return 'overlapstart';
		}
		if ( $endToEnd < 0 && $endToStart > 0 && $startToStart <= 0 ) {
			return 'overlapend';
		}

		throw new LogicException( 'Unreachable' );
	}

	/**
	 * Check if the given boundary points of ranges A and B are almost equal (only differing by
	 * uninteresting nodes).
	 *
	 * Boundary of A must be before the boundary of B in the tree.
	 *
	 * @param ImmutableRange $a
	 * @param ImmutableRange $b
	 * @param string $boundary 'start' or 'end'
	 * @return bool
	 */
	private static function compareRangesAlmostEqualBoundaries(
		ImmutableRange $a, ImmutableRange $b, string $boundary
	): bool {
		// This code is awful, but several attempts to rewrite it made it even worse.
		// You're welcome to give it a try.

		$from = $boundary === 'end' ? static::getRangeLastNode( $a ) : static::getRangeFirstNode( $a );
		$to = $boundary === 'end' ? static::getRangeLastNode( $b ) : static::getRangeFirstNode( $b );

		$skipNode = null;
		if ( $boundary === 'end' ) {
			$skipNode = $from;
		}

		$foundContent = false;
		static::linearWalk(
			$from,
			static function ( string $event, Node $n ) use (
				$from, $to, $boundary, &$skipNode, &$foundContent
			) {
				if ( $n === $to && $event === ( $boundary === 'end' ? 'leave' : 'enter' ) ) {
					return true;
				}
				if ( $skipNode ) {
					if ( $n === $skipNode && $event === 'leave' ) {
						$skipNode = null;
					}
					return;
				}

				if ( $event === 'enter' ) {
					if (
						CommentUtils::isCommentSeparator( $n ) ||
						CommentUtils::isRenderingTransparentNode( $n ) ||
						CommentUtils::isOurGeneratedNode( $n )
					) {
						$skipNode = $n;

					} elseif (
						CommentUtils::isCommentContent( $n )
					) {
						$foundContent = true;
						return true;
					}
				}
			}
		);

		return !$foundContent;
	}

	/**
	 * Assuming that the thread item set contains exactly one comment (or multiple comments with
	 * identical signatures, plus optional heading), check whether that comment is properly signed by
	 * the expected author (that is: there is a signature, and either there's nothing following the
	 * signature, or there's some text within the same paragraph that was detected as part of the same
	 * comment).
	 */
	public static function isSingleCommentSignedBy(
		ContentThreadItemSet $itemSet,
		string $author,
		Element $rootNode
	): bool {
		$items = $itemSet->getThreadItems();

		if ( $items ) {
			$lastItem = end( $items );
			// Check that we've detected a comment first, not just headings (T304377)
			if ( !( $lastItem instanceof ContentCommentItem && $lastItem->getAuthor() === $author ) ) {
				return false;
			}

			// Range covering all of the detected items (to account for a heading, and for multiple
			// signatures resulting in multiple comments)
			$commentsRange = new ImmutableRange(
				$items[0]->getRange()->startContainer,
				$items[0]->getRange()->startOffset,
				$lastItem->getRange()->endContainer,
				$lastItem->getRange()->endOffset
			);
			$bodyRange = new ImmutableRange(
				$rootNode, 0, $rootNode, count( $rootNode->childNodes )
			);

			if ( static::compareRanges( $commentsRange, $bodyRange ) === 'equal' ) {
				// New comment includes a signature in the proper place
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the ID for a new topics subscription from a page title
	 *
	 * @param Title $title Page title
	 * @return string ID for a new topics subscription
	 */
	public static function getNewTopicsSubscriptionId( Title $title ) {
		return "p-topics-{$title->getNamespace()}:{$title->getDBkey()}";
	}
}
