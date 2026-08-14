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

	private const VALID_SORTS = [ 'links', 'title', 'namespace' ];
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
		if ( $limit > self::MAX_LIMIT ) {
			$this->fatalError( '--limit must be <= ' . self::MAX_LIMIT . '.' );
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
			$nsId = (int)$row->namespace;
			$dbKey = (string)$row->title;
			$value = (int)$row->value;
			$title = Title::makeTitleSafe( $nsId, $dbKey );
			if ( !$title ) {
				continue;
			}
			$nsName = $nsId === NS_MAIN
				? ''
				: str_replace( '_', ' ', $nsNames[$nsId] ?? (string)$nsId );
			$prefixed = $title->getPrefixedText();

			$this->writeRow( $format, $nsId, $nsName, $prefixed, $value );
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
				// Match Special:WantedSort web export column order.
				$this->output( "title,namespace,namespace_id,links\n" );
		}
	}

	private function writeRow(
		string $format,
		int $nsId,
		string $nsName,
		string $prefixed,
		int $value
	): void {
		switch ( $format ) {
			case 'wiki':
				$this->output( "|-\n| $nsName || [[$prefixed]] || $value\n" );
				break;
			case 'tsv':
				$this->output( "$prefixed\t$nsName\t$nsId\t$value\n" );
				break;
			default: // csv
				$this->output(
					$this->csvEscape( $this->csvFormulaSafe( $prefixed ) ) . ','
					. $this->csvEscape( $this->csvFormulaSafe( $nsName ) ) . ','
					. $nsId . ','
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
		if ( strpbrk( $value, ',"' . "\n" ) !== false ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/** Same spreadsheet formula neutralization as Special:WantedSort CSV export. */
	private function csvFormulaSafe( string $value ): string {
		if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
			return "'" . $value;
		}
		return $value;
	}
}

// @codeCoverageIgnoreStart
$maintClass = DumpWantedSort::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
