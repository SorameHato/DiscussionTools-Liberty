<?php

namespace MediaWiki\Extension\DiscussionTools\Maintenance;

use IDatabase;
use Maintenance;
use MediaWiki\Extension\DiscussionTools\Hooks\HookUtils;
use MediaWiki\Extension\DiscussionTools\ThreadItemStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Shell\Shell;
use MWExceptionRenderer;
use stdClass;
use Throwable;
use Title;
use Wikimedia\Rdbms\SelectQueryBuilder;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class PersistRevisionThreadItems extends Maintenance {

	/** @var IDatabase */
	private $dbr;

	/** @var ThreadItemStore */
	private $itemStore;

	/** @var RevisionStore */
	private $revStore;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'DiscussionTools' );
		$this->addDescription( 'Persist thread item information for the given pages/revisions' );
		$this->addOption( 'rev', 'Revision ID to process', false, true, false, true );
		$this->addOption( 'page', 'Page title to process', false, true, false, true );
		$this->addOption( 'all', 'Process the whole wiki' );
		$this->addOption( 'current', 'Process current revisions only' );
		$this->addOption( 'start', 'Restart from this position (as printed by the script)', false, true );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();

		$this->dbr = $this->getDB( DB_REPLICA );
		$this->itemStore = $services->getService( 'DiscussionTools.ThreadItemStore' );
		$this->revStore = $services->getRevisionStore();

		$qb = $this->dbr->newSelectQueryBuilder();

		$qb->queryInfo( $this->revStore->getQueryInfo( [ 'page' ] ) );

		if ( $this->getOption( 'all' ) ) {
			// Do nothing

		} elseif ( $this->getOption( 'page' ) ) {
			$linkBatch = $services->getLinkBatchFactory()->newLinkBatch();
			foreach ( $this->getOption( 'page' ) as $page ) {
				$linkBatch->addObj( Title::newFromText( $page ) );
			}
			$pageIds = array_map( static function ( $page ) {
				return $page->getId();
			}, $linkBatch->getPageIdentities() );

			$qb->where( [ 'rev_page' => $pageIds ] );

		} elseif ( $this->getOption( 'rev' ) ) {
			$qb->where( [ 'rev_id' => $this->getOption( 'rev' ) ] );
		} else {
			$this->error( "One of 'all', 'page', or 'rev' required" );
			$this->maybeHelp( true );
			return;
		}

		if ( $this->getOption( 'current' ) ) {
			$qb->where( 'rev_id = page_latest' );
			$index = [ 'page_id' ];
		} else {
			// Process in order by page and time to avoid confusing results while the script is running
			$index = [ 'rev_page', 'rev_timestamp', 'rev_id' ];
		}

		$this->process( $qb, $index );
	}

	/**
	 * @param SelectQueryBuilder $qb
	 * @param array $index
	 */
	private function process( SelectQueryBuilder $qb, array $index ): void {
		$qb->caller( __METHOD__ );

		// estimateRowCount() refuses to work when fields are set, so we can't just call it on $qb
		$countQueryInfo = $qb->getQueryInfo();
		$count = $qb->newSubquery()
			->rawTables( $countQueryInfo['tables'] )
			->where( $countQueryInfo['conds'] )
			->options( $countQueryInfo['options'] )
			->joinConds( $countQueryInfo['join_conds'] )
			->caller( __METHOD__ )
			->estimateRowCount();
		$this->output( "Processing... (estimated $count rows)\n" );

		$processed = 0;
		$updated = 0;

		$qb->orderBy( $index );
		$batchSize = $this->getBatchSize();
		$qb->limit( $batchSize );

		$batchStart = null;
		if ( $this->getOption( 'start' ) ) {
			$batchStart = json_decode( $this->getOption( 'start' ) );
			if ( !$batchStart ) {
				$this->error( "Invalid 'start'" );
			}
		}

		while ( true ) {
			$qbForBatch = clone $qb;
			if ( $batchStart ) {
				$batchStartCond = $this->dbr->buildComparison( '>', array_combine( $index, $batchStart ) );
				$qbForBatch->where( $batchStartCond );

				$batchStartOutput = Shell::escape( json_encode( $batchStart ) );
				$this->output( "--start $batchStartOutput\n" );
			}

			$res = $qbForBatch->fetchResultSet();
			foreach ( $res as $row ) {
				$updated += (int)$this->processRow( $row );
			}
			$processed += $res->numRows();

			$this->output( "Processed $processed (updated $updated) of $count rows\n" );

			$this->waitForReplication();

			if ( $res->numRows() < $batchSize || !isset( $row ) ) {
				// Done
				break;
			}

			// Update the conditions to select the next batch.
			$batchStart = [];
			foreach ( $index as $field ) {
				$batchStart[] = $row->$field;
			}
		}

		$this->output( "Finished!\n" );
	}

	/**
	 * @param stdClass $row Database table row
	 * @return bool
	 */
	private function processRow( stdClass $row ): bool {
		$changed = false;
		try {
			$rev = $this->revStore->newRevisionFromRow( $row );
			$title = Title::newFromLinkTarget(
				$rev->getPageAsLinkTarget()
			);
			if ( HookUtils::isAvailableForTitle( $title ) ) {
				$threadItemSet = HookUtils::parseRevisionParsoidHtml( $rev );

				// Store permalink data
				$changed = $this->itemStore->insertThreadItems( $rev, $threadItemSet );
			}
		} catch ( Throwable $e ) {
			$this->output( "Error while processing revid=$row->rev_id, pageid=$row->rev_page\n" );
			MWExceptionRenderer::output( $e, MWExceptionRenderer::AS_RAW );
		}
		return $changed;
	}
}

$maintClass = PersistRevisionThreadItems::class;
require_once RUN_MAINTENANCE_IF_MAIN;
