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
 *   --limit <n>         Maximum rows to output               (default: 1000)
 *   --format <fmt>      Output format: csv, tsv, wiki        (default: csv)
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WantedSort\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnore
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class DumpWantedSort extends Maintenance {

	private const VALID_SORTS = [ 'links', 'title', 'namespace' ];
	private const DEFAULT_LIMIT = 1000;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Dump WantedSort results to stdout.' );
		$this->addOption( 'namespace', 'Namespace ID to filter by (omit for all namespaces)',
			false, true, 'n' );
		$this->addOption( 'sort', 'Sort column: links, title, namespace (default: links)',
			false, true );
		$this->addOption( 'dir', 'Sort direction: asc or desc (default: desc)',
			false, true );
		$this->addOption( 'limit', 'Maximum rows to output (default: ' . self::DEFAULT_LIMIT . ')',
			false, true, 'l' );
		$this->addOption( 'format', 'Output format: csv, tsv, or wiki (default: csv)',
			false, true );
		$this->requireExtension( 'WantedSort' );
	}

	public function execute(): void {
		$services   = $this->getServiceContainer();
		$config     = $services->getMainConfig();
		$dbProvider = $services->getConnectionProvider();
		$linksMig   = $services->getLinksMigration();
		$nsInfo     = $services->getNamespaceInfo();
		$lang       = $services->getContentLanguage();

		// --namespace
		$namespace = null;
		if ( $this->hasOption( 'namespace' ) ) {
			$nsRawStr = $this->getOption( 'namespace' );
			if ( !ctype_digit( $nsRawStr ) ) {
				$this->fatalError( 'Namespace must be an integer ID (e.g. --namespace 14).' );
			}
			$nsRaw = (int)$nsRawStr;
			if ( $nsRaw < NS_MAIN || !$nsInfo->exists( $nsRaw ) ) {
				$this->fatalError( "Namespace $nsRaw does not exist." );
			}
			$namespace = $nsRaw;
		}

		// --sort
		$sort = $this->getOption( 'sort', 'links' );
		if ( !in_array( $sort, self::VALID_SORTS, true ) ) {
			$this->fatalError( 'Invalid --sort. Choose: ' . implode( ', ', self::VALID_SORTS ) . '.' );
		}

		// --dir
		$dir = $this->getOption( 'dir', 'desc' );
		$dir = ( $dir === 'asc' ) ? 'asc' : 'desc';

		// --limit
		$limit = (int)$this->getOption( 'limit', self::DEFAULT_LIMIT );
		if ( $limit < 1 ) {
			$this->fatalError( '--limit must be >= 1.' );
		}

		// --format
		$format = $this->getOption( 'format', 'csv' );
		if ( !in_array( $format, [ 'csv', 'tsv', 'wiki' ], true ) ) {
			$this->fatalError( 'Invalid --format. Choose: csv, tsv, wiki.' );
		}

		$threshold = (int)$config->get( MainConfigNames::WantedPagesThreshold );
		$dbr = $dbProvider->getReplicaDatabase();

		[ $blNamespace, $blTitle ] = $linksMig->getTitleFields( 'pagelinks' );
		$queryInfo = $linksMig->getQueryInfo( 'pagelinks', 'pagelinks' );

		$conds = [
			'pg1.page_namespace' => null,
			$dbr->expr( $blNamespace, '!=', [ NS_USER, NS_USER_TALK ] ),
			$dbr->expr( 'pg2.page_namespace', '!=', NS_MEDIAWIKI ),
		];
		if ( $namespace !== null ) {
			$conds[$blNamespace] = $namespace;
		}

		$tables = array_merge( $queryInfo['tables'], [ 'pg1' => 'page', 'pg2' => 'page' ] );
		$joinConds = array_merge( [
			'pg1' => [ 'LEFT JOIN', [
				'pg1.page_namespace = ' . $blNamespace,
				'pg1.page_title = ' . $blTitle,
			] ],
			'pg2' => [ 'LEFT JOIN', 'pg2.page_id = pl_from' ],
		], $queryInfo['joins'] );

		$having = [
			'COUNT(*) > ' . $dbr->addQuotes( $threshold - 1 ),
			'COUNT(*) > SUM(pg2.page_is_redirect)',
		];

		$orderBy = $this->buildOrderBy( $sort, $dir, $blNamespace, $blTitle );

		$res = $dbr->newSelectQueryBuilder()
			->rawTables( $tables )
			->select( [
				'namespace' => $blNamespace,
				'title'     => $blTitle,
				'value'     => 'COUNT(*)',
			] )
			->where( $conds )
			->having( $having )
			->groupBy( [ $blNamespace, $blTitle ] )
			->orderBy( $orderBy )
			->limit( $limit )
			->joinConds( $joinConds )
			->caller( __METHOD__ )
			->fetchResultSet();

		$nsNames = $lang->getNamespaces();

		$this->writeHeader( $format );

		foreach ( $res as $row ) {
			$nsId   = (int)$row->namespace;
			$title  = (string)$row->title;
			$value  = (int)$row->value;
			$nsName = $nsId === NS_MAIN
				? ''
				: str_replace( '_', ' ', $nsNames[$nsId] ?? (string)$nsId );

			$this->writeRow( $format, $nsId, $nsName, $title, $value );
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
				$this->output( "namespace_id\tnamespace\ttitle\tlinks\n" );
				break;
			default: // csv
				$this->output( "namespace_id,namespace,title,links\n" );
		}
	}

	private function writeRow(
		string $format,
		int $nsId,
		string $nsName,
		string $title,
		int $value
	): void {
		switch ( $format ) {
			case 'wiki':
				$prefixed = $nsName !== '' ? "$nsName:$title" : $title;
				$this->output( "|-\n| $nsName || [[$prefixed]] || $value\n" );
				break;
			case 'tsv':
				$this->output( "$nsId\t$nsName\t$title\t$value\n" );
				break;
			default: // csv
				$this->output(
					$nsId . ','
					. $this->csvEscape( $nsName ) . ','
					. $this->csvEscape( $title ) . ','
					. $value . "\n"
				);
		}
	}

	private function writeFooter( string $format ): void {
		if ( $format === 'wiki' ) {
			$this->output( '|}' . "\n" );
		}
	}

	/** @return string|array */
	private function buildOrderBy( string $sort, string $dir, string $blNamespace, string $blTitle ) {
		$dirUpper = strtoupper( $dir );
		switch ( $sort ) {
			case 'title':
				return $dirUpper === 'DESC'
					? [ "$blTitle DESC", "$blNamespace DESC" ]
					: [ $blTitle, $blNamespace ];
			case 'namespace':
				return $dirUpper === 'DESC'
					? [ "$blNamespace DESC", $blTitle ]
					: [ $blNamespace, $blTitle ];
			case 'links':
			default:
				return $dirUpper === 'DESC'
					? [ 'COUNT(*) DESC', $blNamespace, $blTitle ]
					: [ 'COUNT(*)', $blNamespace, $blTitle ];
		}
	}

	private function csvEscape( string $value ): string {
		// Neutralize spreadsheet formula triggers (same fix as SpecialWantedSort::csvFormulaSafe).
		if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
			$value = "'" . $value;
		}
		if ( strpbrk( $value, ',"' . "\n" ) !== false ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}
}

$maintClass = DumpWantedSort::class;
require_once RUN_MAINTENANCE_IF_MAIN;
