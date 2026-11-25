<?php

declare( strict_types=1 );

use MediaWiki\Title\Title;

/**
 * Reverse discovery using the original mapping indexes and receiver-local parsing.
 * Only the receiving wiki's title parser can establish a match.
 */
final class InterlanguageTitleLookup {
	// Exposed for read-only operational diagnostics, not configuration.
	public const MAX_SPELLINGS = 20000;
	public const MAX_CANDIDATES = 256;

	private static function identity( string $raw ): ?string {
		$title = Title::newFromText( $raw );
		if ( !$title || !$title->canExist() || $title->isExternal() || $title->getFragment() !== '' ) {
			return null;
		}
		return $title->getNamespace() . "\0" . $title->getDBkey();
	}

	/**
	 * Conservative rejection, never a positive match. MediaWiki 1.43
	 * removes bidi controls, normalizes title whitespace, strips recognized
	 * prefixes and may capitalize the first character. The last colon's
	 * remaining text, minus its first character and edge whitespace, must
	 * therefore be a suffix of the receiving DB key.
	 *
	 * Entity decoding, fragments and user/IP normalization take the full
	 * parser path instead. Empty suffixes and malformed UTF-8 do too.
	 * No namespace interpretation, case conversion or persistent memo here.
	 */
	private static function couldMatch( string $raw, array $dbkeys ): bool {
		if ( !$dbkeys || strpbrk( $raw, '&#' ) !== false ) {
			return true;
		}
		$colon = strrpos( $raw, ':' );
		$tail = $colon === false ? $raw : substr( $raw, $colon + 1 );
		// Same whitespace and bidi cleanup as MediaWikiTitleCodec 1.43.
		$tail = preg_replace( '/[\x{200E}\x{200F}\x{202A}-\x{202E}]+/u', '', $tail );
		if ( $tail === null ) {
			return true;
		}
		$tail = preg_replace( '/[ _\xA0\x{1680}\x{180E}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', '_', $tail );
		if ( $tail === null ) {
			return true;
		}
		$suffix = mb_substr( trim( $tail, '_' ), 1, null, 'UTF-8' );
		foreach ( $dbkeys as $key ) {
			if ( str_ends_with( $key, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read the current set of raw spellings for this language, not an
	 * eventually consistent cache of mapping rows. A covering index on
	 * (language,title) is sufficient. No unfiltered table scan.
	 */
	private static function matchingSpellings( $db, array $identities, string $language, bool $native ): ?array {
		$prefix = $native ? 'll' : 'ill';
		$rows = $db->select(
			$native ? 'langlinks' : 'interlanguage_links',
			[ 'target' => $prefix . '_title' ],
			[ $prefix . '_lang' => $language ],
			__METHOD__,
			[ 'DISTINCT', 'ORDER BY' => $prefix . '_title', 'LIMIT' => self::MAX_SPELLINGS + 1 ]
		);
		$dbkeys = [];
		foreach ( array_keys( $identities ) as $identity ) {
			[ $ns, $key ] = explode( "\0", $identity, 2 );
			// Core can canonicalize an entire IPv6 user name, not just its
			// first character. Do not reject by suffix in either user namespace.
			if ( in_array( (int)$ns, [ NS_USER, NS_USER_TALK ], true ) ) {
				$dbkeys = [];
				break;
			}
			$dbkeys[] = $key;
		}
		$matches = [];
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ++$count > self::MAX_SPELLINGS ) {
				return null;
			}
			$raw = $row->target;
			if ( !self::couldMatch( $raw, $dbkeys ) ) {
				continue;
			}
			$identity = self::identity( $raw );
			if ( $identity !== null && isset( $identities[$identity] ) ) {
				$matches[] = $raw;
				if ( count( $matches ) > self::MAX_CANDIDATES ) {
					return null;
				}
			}
		}
		return $matches;
	}

	/**
	 * Only exact, verified raw spellings enter the live indexed join.
	 * Native-shadowed supplemental rows are excluded unless requested for
	 * targeted lifecycle cleanup. Null denotes an incomplete lookup.
	 *
	 * @param string[] $targets Local whole-page title and redirect aliases
	 * @return array<int,object>|null
	 */
	public static function findCandidates(
		$db, array $targets, string $language, bool $native, bool $collectAll = false, bool $includeShadowed = false
	): ?array {
		$identities = [];
		foreach ( $targets as $target ) {
			$identity = self::identity( $target );
			if ( $identity === null ) {
				return null;
			}
			$identities[$identity] = true;
		}
		if ( !$identities ) {
			return null;
		}
		$matches = self::matchingSpellings( $db, $identities, $language, $native );
		if ( $matches === null || !$matches ) {
			return $matches;
		}

		$prefix = $native ? 'll' : 'ill';
		$tables = [
			'mapping' => $native ? 'langlinks' : 'interlanguage_links',
			'sourcepage' => 'page',
		];
		$joins = [
			'sourcepage' => [ 'JOIN', "sourcepage.page_id = mapping.{$prefix}_from" ],
		];
		$conditions = [
			"mapping.{$prefix}_lang" => $language,
			"mapping.{$prefix}_title" => $matches,
		];
		if ( !$native && !$includeShadowed ) {
			$tables['native'] = 'langlinks';
			$joins['native'] = [ 'LEFT JOIN', 'native.ll_from = mapping.ill_from AND native.ll_lang = mapping.ill_lang' ];
			$conditions['native.ll_from'] = null;
		}
		$rows = $db->select(
			$tables,
			[
				'page_id' => 'sourcepage.page_id',
				'page_namespace' => 'sourcepage.page_namespace',
				'page_title' => 'sourcepage.page_title',
				'page_is_redirect' => 'sourcepage.page_is_redirect',
				'target' => "mapping.{$prefix}_title",
			],
			$conditions,
			__METHOD__,
			[ 'LIMIT' => self::MAX_CANDIDATES + 1 ],
			$joins
		);
		$candidates = [];
		$scanned = 0;
		foreach ( $rows as $row ) {
			if ( ++$scanned > self::MAX_CANDIDATES ) {
				return null;
			}
			$identity = self::identity( $row->target );
			if ( $identity !== null && isset( $identities[$identity] ) ) {
				$candidates[(int)$row->page_id] = $row;
				if ( !$collectAll && count( $candidates ) > 1 ) {
					return $candidates;
				}
			}
		}
		return $candidates;
	}
}
