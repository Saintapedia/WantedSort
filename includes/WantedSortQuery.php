<?php
/**
 * Shared WantedSort pagelinks GROUP BY query (special page + maintenance dump).
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WantedSort;

use MediaWiki\Linker\LinksMigration;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

class WantedSortQuery {

	public const VALID_SORTS = [ 'links', 'title', 'namespace' ];

	private IConnectionProvider $dbProvider;
	private LinksMigration $linksMigration;

	public function __construct(
		IConnectionProvider $dbProvider,
		LinksMigration $linksMigration
	) {
		$this->dbProvider = $dbProvider;
		$this->linksMigration = $linksMigration;
	}

	/**
	 * Run the live grouped pagelinks query for one result page (or a full dump).
	 *
	 * @param bool $detectHasMore When true, fetches one extra row and sets hasMore
	 *   (used by the special page). When false, returns at most $limit rows.
	 * @return array{rows:list<array{namespace:int,title:string,value:int}>,hasMore:bool}
	 */
	public function fetch(
		?int $namespace,
		string $sort,
		string $dir,
		int $limit,
		int $offset,
		int $threshold,
		bool $miserMode = false,
		int $miserMaxResults = 10000,
		bool $detectHasMore = true,
		?IReadableDatabase $dbr = null
	): array {
		$dbr ??= $this->dbProvider->getReplicaDatabase();

		if ( $miserMode ) {
			// Cap deep OFFSET scans (GROUP BY + OFFSET is expensive).
			$dbLimit = max( 0, min(
				$detectHasMore ? $limit + 1 : $limit,
				$miserMaxResults - $offset
			) );
			if ( $dbLimit === 0 ) {
				return [ 'rows' => [], 'hasMore' => false ];
			}
		} else {
			$dbLimit = $detectHasMore ? $limit + 1 : $limit;
		}

		[ $blNamespace, $blTitle ] = $this->linksMigration->getTitleFields( 'pagelinks' );
		$queryInfo = $this->linksMigration->getQueryInfo( 'pagelinks', 'pagelinks' );

		$conds = [
			'pg1.page_namespace' => null,
			$dbr->expr( $blNamespace, '!=', [ NS_USER, NS_USER_TALK ] ),
			$dbr->expr( 'pg2.page_namespace', '!=', NS_MEDIAWIKI ),
		];

		if ( $namespace !== null ) {
			$conds[$blNamespace] = $namespace;
		}

		$tables = array_merge( $queryInfo['tables'], [
			'pg1' => 'page',
			'pg2' => 'page',
		] );

		$joinConds = array_merge( [
			'pg1' => [
				'LEFT JOIN', [
					'pg1.page_namespace = ' . $blNamespace,
					'pg1.page_title = ' . $blTitle,
				],
			],
			'pg2' => [ 'LEFT JOIN', 'pg2.page_id = pl_from' ],
		], $queryInfo['joins'] );

		$having = [
			'COUNT(*) > ' . $dbr->addQuotes( $threshold - 1 ),
			'COUNT(*) > SUM(pg2.page_is_redirect)',
		];

		$orderBy = self::buildOrderBy( $sort, $dir, $blNamespace, $blTitle );

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
			->limit( $dbLimit )
			->offset( $offset )
			->joinConds( $joinConds )
			->caller( __METHOD__ )
			->fetchResultSet();

		$rows = [];
		$hasMore = false;
		foreach ( $res as $row ) {
			if ( $detectHasMore && count( $rows ) >= $limit ) {
				$hasMore = true;
				break;
			}
			$rows[] = [
				'namespace' => (int)$row->namespace,
				'title'     => (string)$row->title,
				'value'     => (int)$row->value,
			];
		}

		return [ 'rows' => $rows, 'hasMore' => $hasMore ];
	}

	/**
	 * @return string|array ORDER BY clause for SelectQueryBuilder::orderBy()
	 */
	public static function buildOrderBy(
		string $sort,
		string $dir,
		string $blNamespace,
		string $blTitle
	) {
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
}
