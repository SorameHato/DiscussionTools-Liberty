<?php

namespace MediaWiki\Extension\DiscussionTools\ThreadItem;

use MediaWiki\Extension\DiscussionTools\ImmutableRange;
use Wikimedia\Assert\Assert;
use Wikimedia\Parsoid\DOM\Element;

class ContentHeadingItem extends ContentThreadItem implements HeadingItem {
	use HeadingItemTrait {
		jsonSerialize as traitJsonSerialize;
	}

	private bool $placeholderHeading;
	private int $headingLevel;
	private bool $uneditableSection = false;

	// Placeholder headings must have a level higher than real headings (1-6)
	private const PLACEHOLDER_HEADING_LEVEL = 99;

	/**
	 * @param ImmutableRange $range
	 * @param ?int $headingLevel Heading level (1-6). Use null for a placeholder heading.
	 */
	public function __construct(
		ImmutableRange $range, ?int $headingLevel
	) {
		parent::__construct( 'heading', 0, $range );
		$this->placeholderHeading = $headingLevel === null;
		$this->headingLevel = $this->placeholderHeading ? static::PLACEHOLDER_HEADING_LEVEL : $headingLevel;
	}

	/**
	 * Get a title based on the hash ID, such that it can be linked to
	 *
	 * @return string Title
	 */
	public function getLinkableTitle(): string {
		$title = '';
		// If this comment is in 0th section, there's no section title for the edit summary
		if ( !$this->isPlaceholderHeading() ) {
			// <span class="mw-headline" …>, or <hN …> in Parsoid HTML
			$headline = $this->getRange()->startContainer;
			Assert::precondition( $headline instanceof Element, 'HeadingItem refers to an element node' );
			$id = $headline->getAttribute( 'id' );
			if ( $id ) {
				// Replace underscores with spaces to undo Sanitizer::escapeIdInternal().
				// This assumes that $wgFragmentMode is [ 'html5', 'legacy' ] or [ 'html5' ],
				// otherwise the escaped IDs are super garbled and can't be unescaped reliably.
				$title = str_replace( '_', ' ', $id );
			}
			// else: Not a real section, probably just HTML markup in wikitext
		}
		return $title;
	}

	/**
	 * @return bool
	 */
	public function isUneditableSection(): bool {
		return $this->uneditableSection;
	}

	/**
	 * @param bool $uneditableSection The heading represents a section that can't be
	 *  edited on its own.
	 */
	public function setUneditableSection( bool $uneditableSection ): void {
		$this->uneditableSection = $uneditableSection;
	}

	/**
	 * @return int Heading level (1-6)
	 */
	public function getHeadingLevel(): int {
		return $this->headingLevel;
	}

	/**
	 * @param int $headingLevel Heading level (1-6)
	 */
	public function setHeadingLevel( int $headingLevel ): void {
		$this->headingLevel = $headingLevel;
	}

	/**
	 * @return bool
	 */
	public function isPlaceholderHeading(): bool {
		return $this->placeholderHeading;
	}

	/**
	 * @param bool $placeholderHeading
	 */
	public function setPlaceholderHeading( bool $placeholderHeading ): void {
		$this->placeholderHeading = $placeholderHeading;
	}

	/**
	 * @inheritDoc
	 */
	public function getTranscludedFrom() {
		// Placeholder headings break the usual logic, because their ranges are collapsed
		if ( $this->isPlaceholderHeading() ) {
			return false;
		}
		// Collapsed ranges should otherwise be impossible, but they're not (T299583)
		// TODO: See if we can fix the root cause, and remove this?
		if ( $this->getRange()->collapsed ) {
			return false;
		}
		return parent::getTranscludedFrom();
	}

	/**
	 * @inheritDoc
	 */
	public function jsonSerialize( bool $deep = false, ?callable $callback = null ): array {
		$data = $this->traitJsonSerialize( $deep, $callback );

		// When this is false (which is most of the time), omit the key for efficiency
		if ( $this->isUneditableSection() ) {
			$data[ 'uneditableSection' ] = true;
		}
		return $data;
	}
}
