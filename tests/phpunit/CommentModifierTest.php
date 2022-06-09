<?php

namespace MediaWiki\Extension\DiscussionTools\Tests;

use MediaWiki\Extension\DiscussionTools\CommentModifier;
use MediaWiki\MediaWikiServices;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMUtils;
use Wikimedia\Parsoid\Wt2Html\XMLSerializer;

/**
 * @coversDefaultClass \MediaWiki\Extension\DiscussionTools\CommentModifier
 *
 * @group DiscussionTools
 */
class CommentModifierTest extends IntegrationTestCase {

	/**
	 * @dataProvider provideAddListItem
	 * @covers ::addListItem
	 */
	public function testAddListItem(
		string $name, string $title, string $dom, string $expected, string $config, string $data,
		string $replyIndentation = 'invisible'
	): void {
		$origPath = $dom;
		$dom = static::getHtml( $dom );
		$expectedPath = $expected;
		$expected = static::getHtml( $expected );
		$config = static::getJson( $config );
		$data = static::getJson( $data );

		$this->setupEnv( $config, $data );
		$title = MediaWikiServices::getInstance()->getTitleParser()->parseTitle( $title );

		$doc = static::createDocument( $dom );
		$container = static::getThreadContainer( $doc );

		$threadItemSet = static::createParser( $data )->parse( $container, $title );
		$comments = $threadItemSet->getCommentItems();

		foreach ( $comments as $comment ) {
			$node = CommentModifier::addListItem( $comment, $replyIndentation );
			$node->textContent = 'Reply to ' . $comment->getId();
		}

		$expectedDoc = static::createDocument( $expected );

		// Optionally write updated content to the "modified HTML" files
		if ( getenv( 'DISCUSSIONTOOLS_OVERWRITE_TESTS' ) ) {
			static::overwriteHtmlFile( $expectedPath, $container, $origPath );
		}

		// saveHtml is not dirty-diff safe, but for testing it is probably faster than DOMCompat::getOuterHTML
		static::assertEquals( $expectedDoc->saveHtml(), $doc->saveHtml(), $name );

		// removeAddedListItem is not implemented on the server
	}

	public function provideAddListItem(): array {
		return static::getJson( '../cases/modified.json' );
	}

	/**
	 * @dataProvider provideAddReplyLink
	 * @covers ::addReplyLink
	 */
	public function testAddReplyLink(
		string $name, string $title, string $dom, string $expected, string $config, string $data
	): void {
		$origPath = $dom;
		$dom = static::getHtml( $dom );
		$expectedPath = $expected;
		$expected = static::getHtml( $expected );
		$config = static::getJson( $config );
		$data = static::getJson( $data );

		$this->setupEnv( $config, $data );
		$title = MediaWikiServices::getInstance()->getTitleParser()->parseTitle( $title );

		$doc = static::createDocument( $dom );
		$container = static::getThreadContainer( $doc );

		$threadItemSet = static::createParser( $data )->parse( $container, $title );
		$comments = $threadItemSet->getCommentItems();

		foreach ( $comments as $comment ) {
			$linkNode = $doc->createElement( 'a' );
			$linkNode->nodeValue = 'Reply';
			$linkNode->setAttribute( 'href', '#' );
			CommentModifier::addReplyLink( $comment, $linkNode );
		}

		$expectedDoc = static::createDocument( $expected );

		// Optionally write updated content to the "reply HTML" files
		if ( getenv( 'DISCUSSIONTOOLS_OVERWRITE_TESTS' ) ) {
			static::overwriteHtmlFile( $expectedPath, $container, $origPath );
		}

		// saveHtml is not dirty-diff safe, but for testing it is probably faster than DOMCompat::getOuterHTML
		static::assertEquals( $expectedDoc->saveHtml(), $doc->saveHtml(), $name );
	}

	public function provideAddReplyLink(): array {
		return static::getJson( '../cases/reply.json' );
	}

	/**
	 * @dataProvider provideUnwrapList
	 * @covers ::unwrapList
	 */
	public function testUnwrapList( string $name, string $html, int $index, string $expected ): void {
		$doc = static::createDocument( '' );
		$container = $doc->createElement( 'div' );

		DOMCompat::setInnerHTML( $container, $html );
		CommentModifier::unwrapList( $container->childNodes[$index] );

		static::assertEquals( $expected, DOMCompat::getInnerHTML( $container ) );
	}

	public function provideUnwrapList(): array {
		return static::getJson( '../cases/unwrap.json' );
	}

	/**
	 * @dataProvider provideAppendSignature
	 * @covers ::appendSignature
	 */
	public function testAppendSignature(
		string $msg, string $html, string $expected
	): void {
		$doc = static::createDocument( '' );
		$container = DOMUtils::parseHTMLToFragment( $doc, $html );

		CommentModifier::appendSignature( $container, ' ~~~~' );

		static::assertEquals(
			$expected,
			XMLSerializer::serialize( $container, [ 'innerXML' => true, 'smartQuote' => false ] )['html'],
			$msg
		);
	}

	public function provideAppendSignature(): array {
		return static::getJson( '../cases/appendSignature.json' );
	}

	/**
	 * @dataProvider provideSanitizeWikitextLinebreaks
	 * @covers ::sanitizeWikitextLinebreaks
	 */
	public function testSanitizeWikitextLinebreaks( string $msg, string $wikitext, string $expected ): void {
		static::assertEquals(
			$expected,
			CommentModifier::sanitizeWikitextLinebreaks( $wikitext ),
			$msg
		);
	}

	public function provideSanitizeWikitextLinebreaks(): array {
		return static::getJson( '../cases/sanitize-wikitext-linebreaks.json' );
	}
}
