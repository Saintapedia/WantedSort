<?php
/**
 * Shared CSV helpers for Special:WantedSort export and DumpWantedSort.
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WantedSort;

class WantedSortCsv {

	/** Column header order used by web export and CLI dump. */
	public const COLUMNS = [ 'title', 'namespace', 'namespace_id', 'links' ];

	/**
	 * Neutralize leading formula-trigger characters before writing CSV cells.
	 * Page titles can start with =, +, -, @ (legal in MediaWiki); spreadsheets
	 * may treat those as formulas when the file is opened.
	 */
	public static function formulaSafe( string $value ): string {
		if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * RFC-style CSV field escape (quotes when needed).
	 */
	public static function escape( string $value ): string {
		if ( strpbrk( $value, ',"' . "\n" ) !== false ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/**
	 * Human-readable namespace label.
	 *
	 * @param array<int,string> $nsNames As returned by Language::getNamespaces().
	 * @param string $mainLabel Label for NS_MAIN (e.g. blanknamespace text, or '').
	 */
	public static function namespaceLabel( int $nsId, array $nsNames, string $mainLabel ): string {
		return $nsId === NS_MAIN
			? $mainLabel
			: str_replace( '_', ' ', $nsNames[$nsId] ?? (string)$nsId );
	}

	/**
	 * One data line for title/namespace/namespace_id/links CSV (no trailing newline).
	 */
	public static function formatRow(
		string $prefixedTitle,
		string $nsLabel,
		int $nsId,
		int $links
	): string {
		return self::escape( self::formulaSafe( $prefixedTitle ) ) . ','
			. self::escape( self::formulaSafe( $nsLabel ) ) . ','
			. $nsId . ','
			. $links;
	}

	public static function headerLine(): string {
		return implode( ',', self::COLUMNS );
	}
}
