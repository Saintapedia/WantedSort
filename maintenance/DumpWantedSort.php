<?php
/**
 * Dump WantedSort results to stdout.
 *
 * Usage:
 *   php maintenance/run.php extensions/WantedSort/maintenance/DumpWantedSort.php [options]
 *
 * Options:
 *   --namespace <id>    Filter to a single namespace (integer ID)
 *   --sort <col>        Sort column: links, title, namespace  (default: links)
 *   --dir <dir>         Sort direction: asc, desc             (default: desc)
 *   --limit <n>         Maximum rows to output               (default: 1000, max: 50000)
 *   --format <fmt>      Output format: csv, tsv, wiki        (default: csv)
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WantedSort\Maintenance;

use MediaWiki\Extension\WantedSort\WantedSortCsv;
use MediaWiki\Extension\WantedSort\WantedSortQuery;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class DumpWantedSort extends Maintenance {

	private const DEFAULT_LIMIT = 1000;
	/** Hard ceiling so CLI cannot request unbounded GROUP BY scans. */
	private const MAX_LIMIT = 50000;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Dump WantedSort results to stdout.' );
		$this->addOption( 'namespace', 'Namespace ID to filter by (omit for all namespaces)',
			false, true, 'n' );
		$this->addOption( 'sort', 'Sort column: links, title, namespace (default: links)',
			false, true );
		$this->addOption( 'dir', 'Sort direction: asc or desc (default: desc)',
			false, true );
		$this->addOption( 'limit', 'Maximum rows to output (default: ' . self::DEFAULT_LIMIT
			. ', max: ' . self::MAX_LIMIT . ')',
			false, true, 'l' );
		$this->addOption( 'format', 'Output format: csv, tsv, or wiki (default: csv)',
			false, true );
		$this->requireExtension( 'WantedSort' );
	}

	public function execute(): void {
		$services = $this->getServiceContainer();
		$config = $services->getMainConfig();
		$dbProvider = $services->getConnectionProvider();
		$linksMig = $services->getLinksMigration();
		$nsInfo = $services->getNamespaceInfo();
		$lang = $services->getContentLanguage();

		// --namespace
		$namespace = null;
		if ( $this->hasOption( 'namespace' ) ) {
			$nsRaw = (int)$this->getOption( 'namespace' );
			if ( $nsRaw < NS_MAIN || !$nsInfo->exists( $nsRaw ) ) {
				$this->fatalError( "Namespace $nsRaw does not exist." );
			}
			$namespace = $nsRaw;
		}

		// --sort
		$sort = $this->getOption( 'sort', 'links' );
		if ( !in_array( $sort, WantedSortQuery::VALID_SORTS, true ) ) {
			$this->fatalError(
				'Invalid --sort. Choose: ' . implode( ', ', WantedSortQuery::VALID_SORTS ) . '.'
			);
		}

		// --dir
		$dir = $this->getOption( 'dir', 'desc' );
		$dir = ( $dir === 'asc' ) ? 'asc' : 'desc';

		// --limit
		$limit = (int)$this->getOption( 'limit', self::DEFAULT_LIMIT );
		if ( $limit < 1 ) {
			$this->fatalError( '--limit must be >= 1.' );
		}
		if ( $limit > self::MAX_LIMIT ) {
			$this->fatalError( '--limit must be <= ' . self::MAX_LIMIT . '.' );
		}

		// --format
		$format = $this->getOption( 'format', 'csv' );
		if ( !in_array( $format, [ 'csv', 'tsv', 'wiki' ], true ) ) {
			$this->fatalError( 'Invalid --format. Choose: csv, tsv, wiki.' );
		}

		$threshold = (int)$config->get( MainConfigNames::WantedPagesThreshold );
		$query = new WantedSortQuery( $dbProvider, $linksMig );
		$page = $query->fetch(
			$namespace,
			$sort,
			$dir,
			$limit,
			0,
			$threshold,
			false,
			10000,
			false
		);

		$nsNames = $lang->getNamespaces();
		// Align with Special:WantedSort web CSV (blanknamespace for NS_MAIN).
		$mainLabel = wfMessage( 'blanknamespace' )->inLanguage( $lang )->text();

		$this->writeHeader( $format );

		foreach ( $page['rows'] as $row ) {
			$nsId = $row['namespace'];
			$title = Title::makeTitleSafe( $nsId, $row['title'] );
			if ( !$title ) {
				continue;
			}
			$nsLabel = WantedSortCsv::namespaceLabel( $nsId, $nsNames, $mainLabel );
			// Wiki/TSV use empty prefix label for main (wikitext link style).
			$nsNameForWiki = $nsId === NS_MAIN
				? ''
				: str_replace( '_', ' ', $nsNames[$nsId] ?? (string)$nsId );

			$this->writeRow(
				$format,
				$nsId,
				$nsLabel,
				$nsNameForWiki,
				$title->getPrefixedText(),
				$row['value']
			);
		}

		$this->writeFooter( $format );
	}

	private function writeHeader( string $format ): void {
		switch ( $format ) {
			case 'wiki':
				$this->output( '{| class="wikitable"' . "\n" );
				$this->output( '! Namespace !! Title !! Links' . "\n" );
				break;
			case 'tsv':
				$this->output( "title\tnamespace\tnamespace_id\tlinks\n" );
				break;
			default: // csv
				$this->output( WantedSortCsv::headerLine() . "\n" );
		}
	}

	private function writeRow(
		string $format,
		int $nsId,
		string $nsLabel,
		string $nsNameForWiki,
		string $prefixed,
		int $value
	): void {
		switch ( $format ) {
			case 'wiki':
				$this->output( "|-\n| $nsNameForWiki || [[$prefixed]] || $value\n" );
				break;
			case 'tsv':
				$this->output( "$prefixed\t$nsLabel\t$nsId\t$value\n" );
				break;
			default: // csv
				$this->output(
					WantedSortCsv::formatRow( $prefixed, $nsLabel, $nsId, $value ) . "\n"
				);
		}
	}

	private function writeFooter( string $format ): void {
		if ( $format === 'wiki' ) {
			$this->output( '|}' . "\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = DumpWantedSort::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
