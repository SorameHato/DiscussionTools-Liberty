var
	api = new mw.Api( { parameters: { formatversion: 2 } } ),
	controller = require( './controller.js' ),
	modifier = require( './modifier.js' ),
	logger = require( './logger.js' ),
	storage = mw.storage.session,
	scrollPadding = { top: 10, bottom: 10 },
	defaultEditMode = mw.user.options.get( 'discussiontools-editmode' ) || mw.config.get( 'wgDiscussionToolsFallbackEditMode' ),
	defaultVisual = defaultEditMode === 'visual',
	conf = mw.config.get( 'wgVisualEditorConfig' ),
	visualModules = [ 'ext.discussionTools.ReplyWidgetVisual' ]
		.concat( conf.pluginModules.filter( mw.loader.getState ) ),
	plainModules = [ 'ext.discussionTools.ReplyWidgetPlain' ];

// Start loading reply widget code
if ( defaultVisual ) {
	mw.loader.using( visualModules );
} else {
	mw.loader.using( plainModules );
}

function CommentController( $pageContainer, comment ) {
	var mode;

	this.$pageContainer = $pageContainer;
	this.comment = comment;
	this.newListItem = null;
	this.replyWidgetPromise = null;

	this.$replyLinkButtons = $( '<span>' )
		.addClass( 'dt-init-replylink-buttons' );

	// Reply
	this.$replyLink = $( '<a>' )
		.addClass( 'dt-init-replylink-reply' )
		.text( mw.msg( 'discussiontools-replylink' ) )
		.attr( {
			role: 'button',
			tabindex: '0'
		} )
		.on( 'click keypress', this.onReplyLinkClick.bind( this ) );

	this.$replyLinkButtons.append(
		$( '<span>' ).addClass( 'dt-init-replylink-bracket' ).text( '[' ),
		this.$replyLink,
		$( '<span>' ).addClass( 'dt-init-replylink-bracket' ).text( ']' )
	);
	modifier.addReplyLink( comment, this.$replyLinkButtons[ 0 ] );

	if ( storage.get( 'reply/' + comment.id + '/saveable' ) ) {
		mode = storage.get( 'reply/' + comment.id + '/mode' );
		this.setup( mode );
	}
}

OO.initClass( CommentController );

/* CommentController private utilities */

/**
 * Get the latest revision ID of the page.
 *
 * @param {string} pageName
 * @return {jQuery.Promise}
 */
function getLatestRevId( pageName ) {
	return api.get( {
		action: 'query',
		prop: 'revisions',
		rvprop: 'ids',
		rvlimit: 1,
		titles: pageName
	} ).then( function ( resp ) {
		return resp.query.pages[ 0 ].revisions[ 0 ].revid;
	} );
}

/**
 * Like #getParsoidCommentData, but assumes the comment was found on the current page,
 * and then follows transclusions to determine the source page where it is written.
 *
 * @param {string} commentId Comment ID, from a comment parsed in the local document
 * @return {jQuery.Promise}
 */
function getParsoidTranscludedCommentData( commentId ) {
	var promise,
		pageName = mw.config.get( 'wgRelevantPageName' ),
		oldId = mw.config.get( 'wgCurRevisionId' );

	function followTransclusion( recursionLimit, code, data ) {
		var errorData;
		if ( recursionLimit > 0 && code === 'comment-is-transcluded' ) {
			errorData = data.errors[ 0 ].data;
			if ( errorData.follow && typeof errorData.transcludedFrom === 'string' ) {
				return getLatestRevId( errorData.transcludedFrom ).then( function ( latestRevId ) {
					// Fetch the transcluded page, until we cross the recursion limit
					return controller.getParsoidCommentData( errorData.transcludedFrom, latestRevId, commentId )
						.catch( followTransclusion.bind( null, recursionLimit - 1 ) );
				} );
			}
		}
		return $.Deferred().reject( code, data );
	}

	// Arbitrary limit of 10 steps, which should be more than anyone could ever need
	// (there are reasonable use cases for at least 2)
	promise = controller.getParsoidCommentData( pageName, oldId, commentId )
		.catch( followTransclusion.bind( null, 10 ) );

	return promise;
}

/* Methods */

CommentController.prototype.onReplyLinkClick = function ( e ) {
	if ( e.type === 'keypress' && e.which !== OO.ui.Keys.ENTER && e.which !== OO.ui.Keys.SPACE ) {
		// Only handle keypresses on the "Enter" or "Space" keys
		return;
	}
	e.preventDefault();
	this.setup();
};

/**
 * Create and setup the reply widget
 *
 * @param {string} [mode] Optionally force a mode, 'visual' or 'source'
 */
CommentController.prototype.setup = function ( mode ) {
	var parsoidPromise,
		commentController = this;

	if ( mode === undefined ) {
		mode = mw.user.options.get( 'discussiontools-editmode' ) ||
			( defaultVisual ? 'visual' : 'source' );
	}

	// TODO: Allow users to use multiple reply widgets simultaneously.
	// Currently submitting a reply from one widget would also destroy the other ones.
	// eslint-disable-next-line no-jquery/no-class-state
	if ( this.$pageContainer.hasClass( 'dt-init-replylink-open' ) ) {
		// Support: IE 11
		// On other browsers, the link is made unclickable using 'pointer-events' in CSS
		return;
	}
	this.$pageContainer.addClass( 'dt-init-replylink-open' );
	// eslint-disable-next-line no-jquery/no-global-selector
	$( '.dt-init-replylink-reply' ).attr( {
		tabindex: '-1'
	} );
	// Suppress page takeover behavior for VE editing so that our unload
	// handler can warn of data loss.
	// eslint-disable-next-line no-jquery/no-global-selector
	$( '#ca-edit, #ca-ve-edit, .mw-editsection a, #ca-addsection' ).off( '.ve-target' );

	logger( {
		action: 'init',
		type: 'page',
		mechanism: 'click',
		// TODO: Use 'wikitext-2017' when config.enable2017Wikitext is set
		// eslint-disable-next-line camelcase
		editor_interface: mode === 'visual' ? 'visualeditor' : 'wikitext'
	} );

	this.$replyLinkButtons.addClass( 'dt-init-replylink-active' );

	if ( !this.replyWidgetPromise ) {
		parsoidPromise = getParsoidTranscludedCommentData( this.comment.id );

		this.replyWidgetPromise = parsoidPromise.then( function ( parsoidData ) {
			return commentController.createReplyWidget( parsoidData, mode === 'visual' );
		}, function ( code, data ) {
			commentController.teardown();

			OO.ui.alert(
				code instanceof Error ? code.toString() : api.getErrorMessage( data ),
				{ size: 'medium' }
			);

			logger( {
				action: 'abort',
				type: 'preinit'
			} );

			commentController.replyWidgetPromise = null;

			return $.Deferred().reject();
		} );

		// On first load, add a placeholder list item
		commentController.newListItem = modifier.addListItem( commentController.comment );
		$( commentController.newListItem ).text( mw.msg( 'discussiontools-replywidget-loading' ) );
	}

	commentController.replyWidgetPromise.then( function ( replyWidget ) {
		if ( !commentController.newListItem ) {
			// On subsequent loads, there's no list item yet, so create one now
			commentController.newListItem = modifier.addListItem( commentController.comment );
		}
		$( commentController.newListItem ).empty().append( replyWidget.$element );

		commentController.setupReplyWidget( replyWidget, null, true );

		logger( { action: 'ready' } );
		logger( { action: 'loaded' } );
	} );
};

CommentController.prototype.getReplyWidgetClass = function ( visual ) {
	if ( visual === undefined ) {
		visual = defaultVisual;
	}

	return mw.loader.using( visual ? visualModules : plainModules ).then( function () {
		return require( visual ? 'ext.discussionTools.ReplyWidgetVisual' : 'ext.discussionTools.ReplyWidgetPlain' );
	} );
};

CommentController.prototype.createReplyWidget = function ( parsoidData, visual ) {
	var commentController = this;

	return this.getReplyWidgetClass( visual ).then( function ( ReplyWidget ) {
		return new ReplyWidget( commentController, parsoidData );
	} );
};

CommentController.prototype.setupReplyWidget = function ( replyWidget, initialValue, scrollIntoView ) {
	replyWidget.connect( this, { teardown: 'teardown' } );

	replyWidget.setup( initialValue );
	if ( scrollIntoView ) {
		replyWidget.scrollElementIntoView( { padding: scrollPadding } );
	}
	replyWidget.focus();

	this.replyWidget = replyWidget;
};

CommentController.prototype.teardown = function ( abandoned ) {
	this.$replyLinkButtons.removeClass( 'dt-init-replylink-active' );
	this.$pageContainer.removeClass( 'dt-init-replylink-open' );
	// eslint-disable-next-line no-jquery/no-global-selector
	$( '.dt-init-replylink-reply' ).attr( {
		tabindex: '0'
	} );
	// We deliberately mangled edit links earlier so VE can't steal our page;
	// have it redo setup to fix those.
	if ( mw.libs.ve && mw.libs.ve.setupEditLinks ) {
		mw.libs.ve.setupEditLinks();
	}
	modifier.removeAddedListItem( this.newListItem );
	this.newListItem = null;
	if ( abandoned ) {
		this.$replyLink.trigger( 'focus' );
	}
};

CommentController.prototype.postReply = function ( comment ) {
	if ( this.replyWidget.getMode() === 'source' ) {
		modifier.addWikitextReply( comment, this.replyWidget.getValue() );
	} else {
		modifier.addHtmlReply( comment, this.replyWidget.getValue() );
	}
};

CommentController.prototype.save = function ( parsoidData ) {
	var heading, summaryPrefix, summary, savePromise,
		mode = this.replyWidget.getMode(),
		comment = parsoidData.comment,
		pageData = parsoidData.pageData,
		commentController = this;

	// Update the Parsoid DOM
	this.postReply( parsoidData.comment );

	heading = comment.getHeading();
	if ( heading.placeholderHeading ) {
		// This comment is in 0th section, there's no section title for the edit summary
		summaryPrefix = '';
	} else {
		summaryPrefix = '/* ' + heading.range.startContainer.innerText + ' */ ';
	}

	summary = summaryPrefix + mw.msg( 'discussiontools-defaultsummary-reply' );

	return this.replyWidget.checkboxesPromise.then( function ( checkboxes ) {
		var captchaInput = commentController.replyWidget.captchaInput,
			data = {
				page: pageData.pageName,
				oldid: pageData.oldId,
				summary: summary,
				baserevid: pageData.oldId,
				starttimestamp: pageData.startTimeStamp,
				etag: pageData.etag,
				assert: mw.user.isAnon() ? 'anon' : 'user',
				assertuser: mw.user.getName() || undefined,
				dttags: [
					'discussiontools',
					'discussiontools-reply',
					'discussiontools-' + mode
				].join( ',' )
			};

		if ( captchaInput ) {
			data.captchaid = captchaInput.getCaptchaId();
			data.captchaword = captchaInput.getCaptchaWord();
		}

		if ( checkboxes.checkboxesByName.wpWatchthis ) {
			data.watchlist = checkboxes.checkboxesByName.wpWatchthis.isSelected() ?
				'watch' :
				'unwatch';
		}

		savePromise = mw.libs.ve.targetSaver.saveDoc(
			parsoidData.doc,
			data,
			{
				// No timeout. Huge talk pages take a long time to save, and falsely reporting an error can
				// result in duplicate messages when the user retries. (T249071)
				api: new mw.Api( { ajax: { timeout: 0 }, parameters: { formatversion: 2 } } )
			}
		).catch( function ( code, data ) {
			// Handle edit conflicts. Load the latest revision of the page, then try again. If the parent
			// comment has been deleted from the page, or if retry also fails for some other reason, the
			// error is handled as normal below.
			if ( code === 'editconflict' ) {
				return getLatestRevId( pageData.pageName ).then( function ( latestRevId ) {
					return controller.getParsoidCommentData( pageData.pageName, latestRevId, comment.id ).then( function ( parsoidData ) {
						return commentController.save( parsoidData );
					} );
				} );
			}
			return $.Deferred().reject( code, data ).promise();
		} );
		savePromise.then( function () {
			var watch;
			// Update watch link to match 'watch checkbox' in save dialog.
			// User logged in if module loaded.
			if ( mw.loader.getState( 'mediawiki.page.watch.ajax' ) === 'ready' ) {
				watch = require( 'mediawiki.page.watch.ajax' );
				watch.updateWatchLink(
					// eslint-disable-next-line no-jquery/no-global-selector
					$( '#ca-watch a, #ca-unwatch a' ),
					data.watchlist === 'watch' ? 'unwatch' : 'watch'
				);
			}
		} );
		return savePromise;
	} );
};

CommentController.prototype.switchToWikitext = function () {
	var wikitextPromise,
		oldWidget = this.replyWidget,
		target = oldWidget.replyBodyWidget.target,
		previewDeferred = $.Deferred(),
		commentController = this;

	// TODO: We may need to pass oldid/etag when editing is supported
	wikitextPromise = target.getWikitextFragment( target.getSurface().getModel().getDocument() );
	this.replyWidgetPromise = this.createReplyWidget( oldWidget.parsoidData, false );

	return $.when( wikitextPromise, this.replyWidgetPromise ).then( function ( wikitext, replyWidget ) {
		wikitext = modifier.sanitizeWikitextLinebreaks( wikitext );

		// To prevent the "Reply" / "Cancel" buttons from shifting when the preview loads,
		// wait for the preview (but no longer than 500 ms) before swithing the editors.
		replyWidget.preparePreview( wikitext ).then( previewDeferred.resolve );
		setTimeout( previewDeferred.resolve, 500 );

		return previewDeferred.then( function () {
			// Swap out the DOM nodes
			oldWidget.$element.replaceWith( replyWidget.$element );

			// Teardown the old widget
			oldWidget.disconnect( commentController );
			oldWidget.teardown();

			commentController.setupReplyWidget( replyWidget, wikitext );
		} );
	} );
};

CommentController.prototype.switchToVisual = function () {
	var parsePromise,
		oldWidget = this.replyWidget,
		wikitext = oldWidget.getValue(),
		pageData = oldWidget.parsoidData.pageData,
		commentController = this;

	wikitext = modifier.sanitizeWikitextLinebreaks( wikitext ).trim();
	if ( wikitext ) {
		wikitext = wikitext.split( '\n' ).map( function ( line ) {
			return ':' + line;
		} ).join( '\n' );

		// Based on ve.init.mw.Target#parseWikitextFragment
		parsePromise = api.post( {
			action: 'visualeditor',
			paction: 'parsefragment',
			page: pageData.pageName,
			wikitext: wikitext,
			pst: true
		} ).then( function ( response ) {
			return response && response.visualeditor.content;
		} );
	} else {
		parsePromise = $.Deferred().resolve( '' ).promise();
	}
	this.replyWidgetPromise = this.createReplyWidget( oldWidget.parsoidData, true );

	return $.when( parsePromise, this.replyWidgetPromise ).then( function ( html, replyWidget ) {
		var doc, bodyChildren;

		// Swap out the DOM nodes
		oldWidget.$element.replaceWith( replyWidget.$element );

		// Teardown the old widget
		oldWidget.disconnect( commentController );
		oldWidget.teardown();

		if ( html ) {
			doc = replyWidget.replyBodyWidget.target.parseDocument( html );
			// Remove RESTBase IDs (T253584)
			mw.libs.ve.stripRestbaseIds( doc );
			bodyChildren = Array.prototype.slice.call( doc.body.childNodes );
			// There may be multiple lists when some lines are template generated
			bodyChildren.forEach( function ( child ) {
				if ( child.nodeType === Node.ELEMENT_NODE ) {
					// Unwrap list
					modifier.unwrapList( child );
				}
			} );
		}

		commentController.setupReplyWidget( replyWidget, doc );
	} );
};

module.exports = CommentController;
