<?php

namespace MediaWiki\Extension\DiscussionTools;

use MediaWiki\Extension\DiscussionTools\ThreadItem\CommentItem;
use MediaWiki\Extension\DiscussionTools\ThreadItem\ContentCommentItem;
use MediaWiki\Extension\DiscussionTools\ThreadItem\ContentHeadingItem;
use MediaWiki\Extension\DiscussionTools\ThreadItem\ContentThreadItem;
use MediaWiki\Extension\DiscussionTools\ThreadItem\HeadingItem;
use MediaWiki\Extension\DiscussionTools\ThreadItem\ThreadItem;
use Wikimedia\Assert\Assert;

/**
 * Groups thread items (headings and comments) generated by parsing a discussion page.
 */
class ContentThreadItemSet implements ThreadItemSet {

	/** @var ContentThreadItem[] */
	private $threadItems = [];
	/** @var ContentCommentItem[] */
	private $commentItems = [];
	/** @var ContentThreadItem[][] */
	private $threadItemsByName = [];
	/** @var ContentThreadItem[] */
	private $threadItemsById = [];
	/** @var ContentHeadingItem[] */
	private $threads = [];

	/**
	 * @inheritDoc
	 * @param ThreadItem $item
	 */
	public function addThreadItem( ThreadItem $item ) {
		Assert::precondition( $item instanceof ContentThreadItem, 'Must be ContentThreadItem' );

		$this->threadItems[] = $item;
		if ( $item instanceof CommentItem ) {
			$this->commentItems[] = $item;
		}
		if ( $item instanceof HeadingItem ) {
			$this->threads[] = $item;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function isEmpty(): bool {
		return !$this->threadItems;
	}

	/**
	 * @inheritDoc
	 * @param ThreadItem $item
	 */
	public function updateIdAndNameMaps( ThreadItem $item ) {
		Assert::precondition( $item instanceof ContentThreadItem, 'Must be ContentThreadItem' );

		$this->threadItemsByName[ $item->getName() ][] = $item;

		$this->threadItemsById[ $item->getId() ] = $item;
	}

	/**
	 * @inheritDoc
	 * @return ContentThreadItem[] Thread items
	 */
	public function getThreadItems(): array {
		return $this->threadItems;
	}

	/**
	 * @inheritDoc
	 * @return ContentCommentItem[] Comment items
	 */
	public function getCommentItems(): array {
		return $this->commentItems;
	}

	/**
	 * @inheritDoc
	 * @return ContentThreadItem[] Thread items, empty array if not found
	 */
	public function findCommentsByName( string $name ): array {
		return $this->threadItemsByName[$name] ?? [];
	}

	/**
	 * @inheritDoc
	 * @return ContentThreadItem|null Thread item, null if not found
	 */
	public function findCommentById( string $id ): ?ThreadItem {
		return $this->threadItemsById[$id] ?? null;
	}

	/**
	 * @inheritDoc
	 * @return ContentHeadingItem[] Tree structure of comments, top-level items are the headings.
	 */
	public function getThreads(): array {
		return $this->threads;
	}
}
