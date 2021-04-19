<?php
/**
 * DiscussionTools page hooks
 *
 * @file
 * @ingroup Extensions
 * @license MIT
 */

namespace MediaWiki\Extension\DiscussionTools\Hooks;

use DOMDocument;
use MediaWiki\Extension\DiscussionTools\CommentFormatter;
use MediaWiki\Extension\DiscussionTools\SubscriptionStore;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\OutputPageBeforeHTMLHook;
use MediaWiki\MediaWikiServices;
use OutputPage;
use Skin;
use VisualEditorHooks;
use Wikimedia\Parsoid\Utils\DOMCompat;

class PageHooks implements
	BeforePageDisplayHook,
	OutputPageBeforeHTMLHook
{
	/** @var SubscriptionStore */
	protected $subscriptionStore;

	/**
	 * @param SubscriptionStore $subscriptionStore
	 */
	public function __construct( SubscriptionStore $subscriptionStore ) {
		$this->subscriptionStore = $subscriptionStore;
	}

	/**
	 * Adds DiscussionTools JS to the output.
	 *
	 * This is attached to the MediaWiki 'BeforePageDisplay' hook.
	 *
	 * @param OutputPage $output
	 * @param Skin $skin
	 * @return void This hook must not abort, it must return no value
	 */
	public function onBeforePageDisplay( $output, $skin ) : void {
		$user = $output->getUser();
		// Load style modules if the tools can be available for the title
		// as this means the DOM may have been modified in the parser cache.
		if ( HookUtils::isAvailableForTitle( $output->getTitle() ) ) {
			$output->addModuleStyles( [
				'ext.discussionTools.init.styles',
				// Topic subscription star
				'oojs-ui.styles.icons-moderation',
			] );
		}
		// Load modules if any DT feature is enabled for this user
		if ( HookUtils::isFeatureEnabledForOutput( $output ) ) {
			$output->addModules( [
				'ext.discussionTools.init'
			] );

			$enabledVars = [];
			foreach ( HookUtils::FEATURES as $feature ) {
				$enabledVars[$feature] = HookUtils::isFeatureEnabledForOutput( $output, $feature );
			}
			$output->addJsConfigVars( 'wgDiscussionToolsFeaturesEnabled', $enabledVars );

			$services = MediaWikiServices::getInstance();
			$optionsLookup = $services->getUserOptionsLookup();
			$req = $output->getRequest();
			$editor = $optionsLookup->getOption( $user, 'discussiontools-editmode' );
			// User has no preferred editor yet
			// If the user has a preferred editor, this will be evaluated in the client
			if ( !$editor ) {
				// Check which editor we would use for articles
				// VE pref is 'visualeditor'/'wikitext'. Here we describe the mode,
				// not the editor, so 'visual'/'source'
				$editor = VisualEditorHooks::getPreferredEditor( $user, $req ) === 'visualeditor' ?
					'visual' : 'source';
				$output->addJsConfigVars(
					'wgDiscussionToolsFallbackEditMode',
					$editor
				);
			}
			$dtConfig = $services->getConfigFactory()->makeConfig( 'discussiontools' );
			$abstate = $dtConfig->get( 'DiscussionToolsABTest' ) ?
				$optionsLookup->getOption( $user, 'discussiontools-abtest' ) :
				false;
			if ( $abstate ) {
				$output->addJsConfigVars(
					'wgDiscussionToolsABTestBucket',
					$abstate
				);
			}
		}
	}

	/**
	 * OutputPageBeforeHTML hook handler
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/OutputPageBeforeHTML
	 *
	 * @param OutputPage $output OutputPage object that corresponds to the page
	 * @param string &$text Text that will be displayed, in HTML
	 * @return bool|void This hook must not abort, it must return true or null.
	 */
	public function onOutputPageBeforeHTML( $output, &$text ) {
		$lang = $output->getLanguage();
		// Check after the parser cache if tools need to be added for
		// non-cacheable reasons i.e. query string or cookie
		// The addDiscussionTools method is responsible for ensuring that
		// tools aren't added twice.
		foreach ( CommentFormatter::USE_WITH_FEATURES as $feature ) {
			if ( HookUtils::isFeatureEnabledForOutput( $output, $feature ) ) {
				CommentFormatter::addDiscussionTools( $text, $lang );
				break;
			}
		}

		foreach ( HookUtils::FEATURES as $feature ) {
			// Add a CSS class for each enabled feature
			if ( HookUtils::isFeatureEnabledForOutput( $output, $feature ) ) {
				$output->addBodyClasses( "ext-discussiontools-$feature-enabled" );
			}
		}

		if ( HookUtils::isFeatureEnabledForOutput( $output, HookUtils::TOPICSUBSCRIPTION ) ) {
			$subscriptionStore = $this->subscriptionStore;
			$doc = new DOMDocument();
			$user = $output->getUser();
			$text = preg_replace_callback(
				'/<!--__DTSUBSCRIBE__(.*)-->/',
				function ( $matches ) use ( $doc, $subscriptionStore, $user ) {
					$itemName = $matches[1];
					$items = $subscriptionStore->getSubscriptionItemsForUser(
						$user,
						$itemName
					);
					$isSubscribed = count( $items ) && !$items[0]->isMuted();
					$subscribe = $doc->createElement( 'span' );
					$subscribe->setAttribute(
						'class',
						'ext-discussiontools-section-subscribe ' .
							( $isSubscribed ? 'oo-ui-icon-unStar oo-ui-image-progressive' : 'oo-ui-icon-star' )
					);
					$subscribe->setAttribute( 'data-mw-comment-name', $itemName );
					return DOMCompat::getOuterHTML( $subscribe );
				},
				$text
			);
		}

		return true;
	}
}
