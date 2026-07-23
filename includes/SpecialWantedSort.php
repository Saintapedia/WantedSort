<?php
/**
 * Special:WantedSort — filterable, sortable wanted-pages report.
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WantedSort;

use MediaWiki\Html\Html;
use MediaWiki\Linker\LinksMigration;
use MediaWiki\MainConfigNames;
use MediaWiki\Namespace\NamespaceInfo;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialWantedSort extends SpecialPage {

	private const VALID_SORTS = [ 'links', 'title', 'namespace' ];
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 500;

	private IConnectionProvider $dbProvider;
	private LinksMigration $linksMigration;
	private NamespaceInfo $namespaceInfo;

	public function __construct(
		IConnectionProvider $dbProvider,
		LinksMigration $linksMigration,
		NamespaceInfo $namespaceInfo
	) {
		parent::__construct( 'WantedSort' );
		$this->dbProvider = $dbProvider;
		$this->linksMigration = $linksMigration;
		$this->namespaceInfo = $namespaceInfo;
	}

	/** @inheritDoc */
	public function execute( $par ) {
		$this->setHeaders();
		$this->addHelpLink( 'Extension:WantedSort' );

		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.wantedSort' );

		$request = $this->getRequest();

		$namespace = $request->getVal( 'namespace', '' );
		$namespace = ( $namespace !== '' && ctype_digit( $namespace ) )
			? (int)$namespace
			: null;

		$sort = $request->getVal( 'sort', 'links' );
		if ( !in_array( $sort, self::VALID_SORTS, true ) ) {
			$sort = 'links';
		}

		$dir = $request->getVal( 'dir', 'desc' );
		$dir = ( $dir === 'asc' ) ? 'asc' : 'desc';

		$limit = $request->getInt( 'limit', self::DEFAULT_LIMIT );
		$limit = max( 1, min( $limit, self::MAX_LIMIT ) );

		$offset = max( 0, $request->getInt( 'offset', 0 ) );

		$this->showFilterForm( $namespace, $sort, $dir, $limit );
		$this->showResults( $namespace, $sort, $dir, $limit, $offset );
	}

	private function showFilterForm( ?int $namespace, string $sort, string $dir, int $limit ): void {
		$out = $this->getOutput();
		$lang = $this->getContentLanguage();
		$title = $this->getPageTitle();

		// Build namespace options: "All namespaces" + each registered namespace
		$nsOptions = [ $this->msg( 'wantedsort-ns-all' )->text() => '' ];
		$namespaces = $lang->getNamespaces();
		foreach ( $namespaces as $nsId => $nsName ) {
			if ( $nsId < NS_MAIN ) {
				// Skip virtual/special namespaces (negative IDs)
				continue;
			}
			$label = $nsId === NS_MAIN
				? $this->msg( 'blanknamespace' )->text()
				: str_replace( '_', ' ', $nsName );
			$nsOptions[$label] = (string)$nsId;
		}
		ksort( $nsOptions );

		$formFields = [
			'namespace' => [
				'type' => 'select',
				'label-message' => 'wantedsort-field-namespace',
				'options' => $nsOptions,
				'default' => $namespace !== null ? (string)$namespace : '',
			],
			'sort' => [
				'type' => 'select',
				'label-message' => 'wantedsort-field-sort',
				'options' => [
					$this->msg( 'wantedsort-sort-links' )->text() => 'links',
					$this->msg( 'wantedsort-sort-title' )->text() => 'title',
					$this->msg( 'wantedsort-sort-namespace' )->text() => 'namespace',
				],
				'default' => $sort,
			],
			'dir' => [
				'type' => 'select',
				'label-message' => 'wantedsort-field-dir',
				'options' => [
					$this->msg( 'wantedsort-dir-desc' )->text() => 'desc',
					$this->msg( 'wantedsort-dir-asc' )->text() => 'asc',
				],
				'default' => $dir,
			],
			'limit' => [
				'type' => 'select',
				'label-message' => 'wantedsort-field-limit',
				'options' => [
					'20' => 20,
					'50' => 50,
					'100' => 100,
					'250' => 250,
					'500' => 500,
				],
				'default' => $limit,
			],
		];

		$form = \HTMLForm::factory( 'ooui', $formFields, $this->getContext() );
		$form->setMethod( 'get' )
			->setAction( $title->getLocalURL() )
			->setSubmitTextMsg( 'wantedsort-submit' )
			->setWrapperLegendMsg( 'wantedsort-legend' )
			->setId( 'mw-wantedsort-form' )
			->prepareForm()
			->displayForm( false );
	}

	private function showResults( ?int $namespace, string $sort, string $dir, int $limit, int $offset ): void {
		$out = $this->getOutput();
		$dbr = $this->dbProvider->getReplicaDatabase();

		$threshold = (int)$this->getConfig()->get( MainConfigNames::WantedPagesThreshold ) - 1;

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
			'COUNT(*) > ' . $dbr->addQuotes( $threshold ),
			'COUNT(*) > SUM(pg2.page_is_redirect)',
		];

		$orderBy = $this->buildOrderBy( $sort, $dir, $blNamespace, $blTitle );

		// Count total matching rows for pagination
		$countRes = $dbr->newSelectQueryBuilder()
			->rawTables( $tables )
			->select( [ 'ns' => $blNamespace, 'ti' => $blTitle ] )
			->where( $conds )
			->having( $having )
			->groupBy( [ $blNamespace, $blTitle ] )
			->joinConds( $joinConds )
			->caller( __METHOD__ )
			->fetchResultSet();
		$totalCount = $countRes->numRows();

		// Fetch the result page
		$res = $dbr->newSelectQueryBuilder()
			->rawTables( $tables )
			->select( [
				'namespace' => $blNamespace,
				'title' => $blTitle,
				'value' => 'COUNT(*)',
			] )
			->where( $conds )
			->having( $having )
			->groupBy( [ $blNamespace, $blTitle ] )
			->orderBy( $orderBy )
			->limit( $limit )
			->offset( $offset )
			->joinConds( $joinConds )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( $totalCount === 0 ) {
			$out->addWikiMsg( 'wantedsort-noresults' );
			return;
		}

		$out->addHTML( $this->buildNavigationBar( $totalCount, $offset, $limit, $namespace, $sort, $dir ) );
		$out->addHTML( $this->buildTable( $res, $sort, $dir, $namespace, $limit, $offset ) );
		$out->addHTML( $this->buildNavigationBar( $totalCount, $offset, $limit, $namespace, $sort, $dir ) );
	}

	/**
	 * @return string|array ORDER BY value suitable for SelectQueryBuilder::orderBy()
	 */
	private function buildOrderBy( string $sort, string $dir, string $blNamespace, string $blTitle ) {
		$dirUpper = strtoupper( $dir );
		switch ( $sort ) {
			case 'title':
				return $dirUpper === 'DESC'
					? [ "$blTitle DESC", "$blNamespace DESC" ]
					: [ $blTitle, $blNamespace ];
			case 'namespace':
				return $dirUpper === 'DESC'
					? [ "$blNamespace DESC", "$blTitle" ]
					: [ $blNamespace, $blTitle ];
			case 'links':
			default:
				return $dirUpper === 'DESC'
					? [ 'COUNT(*) DESC', $blNamespace, $blTitle ]
					: [ 'COUNT(*)', $blNamespace, $blTitle ];
		}
	}

	private function buildTable( $res, string $sort, string $dir, ?int $namespace, int $limit, int $offset ): string {
		$linkRenderer = $this->getLinkRenderer();
		$lang = $this->getLanguage();

		$html = Html::openElement( 'table', [
			'class' => 'wikitable sortable mw-wantedsort-table',
		] );
		$html .= Html::openElement( 'thead' ) . Html::openElement( 'tr' );

		$columns = [
			'title'     => 'wantedsort-col-title',
			'namespace' => 'wantedsort-col-namespace',
			'links'     => 'wantedsort-col-links',
		];

		foreach ( $columns as $col => $msgKey ) {
			$html .= Html::rawElement( 'th', [],
				$this->makeSortLink( $col, $msgKey, $sort, $dir, $namespace, $limit, $offset )
			);
		}
		$html .= Html::element( 'th', [], $this->msg( 'wantedsort-col-wlh' )->text() );
		$html .= Html::closeElement( 'tr' ) . Html::closeElement( 'thead' );

		$html .= Html::openElement( 'tbody' );

		foreach ( $res as $row ) {
			$title = Title::makeTitleSafe( (int)$row->namespace, $row->title );
			if ( !$title ) {
				continue;
			}

			$pageLink = $linkRenderer->makeBrokenLink( $title );
			$wlhTitle = SpecialPage::getTitleFor( 'Whatlinkshere', $title->getPrefixedText() );
			$wlhLink = $linkRenderer->makeLink(
				$wlhTitle,
				$this->msg( 'nlinks' )->numParams( (int)$row->value )->text()
			);

			$nsText = $row->namespace == NS_MAIN
				? $this->msg( 'blanknamespace' )->escaped()
				: htmlspecialchars( str_replace( '_', ' ',
					$lang->getNamespaces()[(int)$row->namespace] ?? (string)$row->namespace ) );

			$html .= Html::openElement( 'tr' );
			$html .= Html::rawElement( 'td', [], $pageLink );
			$html .= Html::rawElement( 'td', [], $nsText );
			$html .= Html::element( 'td', [ 'class' => 'mw-wantedsort-count' ], (string)(int)$row->value );
			$html .= Html::rawElement( 'td', [], $wlhLink );
			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'tbody' );
		$html .= Html::closeElement( 'table' );

		return $html;
	}

	private function makeSortLink(
		string $col,
		string $msgKey,
		string $currentSort,
		string $currentDir,
		?int $namespace,
		int $limit,
		int $offset
	): string {
		$label = $this->msg( $msgKey )->escaped();

		// Toggle direction if clicking the already-active column
		if ( $col === $currentSort ) {
			$newDir = $currentDir === 'asc' ? 'desc' : 'asc';
			$indicator = $currentDir === 'asc' ? ' ↑' : ' ↓';
			$label .= Html::element( 'span', [ 'class' => 'mw-wantedsort-sort-indicator' ], $indicator );
		} else {
			$newDir = 'desc';
		}

		$params = [
			'sort'   => $col,
			'dir'    => $newDir,
			'limit'  => $limit,
			'offset' => $offset,
		];
		if ( $namespace !== null ) {
			$params['namespace'] = $namespace;
		}

		$url = $this->getPageTitle()->getLocalURL( $params );
		return Html::rawElement( 'a', [ 'href' => $url ], $label );
	}

	private function buildNavigationBar(
		int $total,
		int $offset,
		int $limit,
		?int $namespace,
		string $sort,
		string $dir
	): string {
		$baseParams = [ 'sort' => $sort, 'dir' => $dir, 'limit' => $limit ];
		if ( $namespace !== null ) {
			$baseParams['namespace'] = $namespace;
		}

		$prevLink = '';
		$nextLink = '';

		if ( $offset > 0 ) {
			$prevOffset = max( 0, $offset - $limit );
			$prevUrl = $this->getPageTitle()->getLocalURL( $baseParams + [ 'offset' => $prevOffset ] );
			$prevLink = Html::rawElement( 'a', [ 'href' => $prevUrl ],
				$this->msg( 'prevn' )->numParams( $limit )->escaped()
			);
		}

		if ( $offset + $limit < $total ) {
			$nextUrl = $this->getPageTitle()->getLocalURL( $baseParams + [ 'offset' => $offset + $limit ] );
			$nextLink = Html::rawElement( 'a', [ 'href' => $nextUrl ],
				$this->msg( 'nextn' )->numParams( $limit )->escaped()
			);
		}

		$showing = $this->msg( 'wantedsort-showing' )
			->numParams( $offset + 1, min( $offset + $limit, $total ), $total )
			->escaped();

		$parts = array_filter( [ $prevLink, $showing, $nextLink ] );

		return Html::rawElement( 'div', [ 'class' => 'mw-wantedsort-nav' ],
			implode( ' | ', $parts )
		);
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'maintenance';
	}
}
