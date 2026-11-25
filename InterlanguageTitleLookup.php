<?php

declare( strict_types=1 );

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Reverse discovery using the original mapping indexes and receiver-local parsing.
 * The cache is only a memo of normalization, not an authority on live mappings.
 */
final class InterlanguageTitleLookup {
	private const MAX_SPELLINGS = 20000;
	private const MAX_CANDIDATES = 256;
	private const CACHE_TTL = 600;

	private static function identity( string $raw ): ?string {
		$title = Title::newFromText( $raw );
		if ( !$title || !$title->canExist() || $title->isExternal() || $title->getFragment() !== '' ) {
			return null;
		}
		return $title->getNamespace() . "\0" . $title->getDBkey();
	}

	/**
	 * Cache only spellings without colons or entity syntax. Their interpretation
	 * does not depend on dynamically configured namespace/interwiki prefixes.
	 * Keyed by the actual live raw spelling set and main namespace title rules;
	 * safe even with completely separate per-wiki cache backends.
	 *
	 * @return array<string,string[]> Local identities => original raw spellings
	 */
	private static function simpleIndex( array $spellings ): array {
		if ( !$spellings ) {
			return [];
		}
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$cache = $services->getObjectCacheFactory()->getLocalServerInstance( $config->get( 'MainCacheType' ) );
		$signature = hash( 'sha256', serialize( [
			$spellings,
			$services->getContentLanguage()->getCode(),
			$services->getNamespaceInfo()->isCapitalized( NS_MAIN ),
			$config->get( 'OverrideUcfirstCharacters' ),
			Title::legalChars(),
			MW_VERSION,
			PHP_VERSION,
		] ) );
		$key = $cache->makeKey( 'interlanguage-simple-title-v1', $signature );
		return $cache->getWithSetCallback( $key, self::CACHE_TTL, static function () use ( $spellings ) {
			$index = [];
			foreach ( $spellings as $raw ) {
				$identity = self::identity( $raw );
				if ( $identity !== null ) {
					$index[$identity][] = $raw;
				}
			}
			return $index;
		} );
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
		$simple = [];
		$matches = [];
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ++$count > self::MAX_SPELLINGS ) {
				return null;
			}
			$raw = $row->target;
			if ( strpbrk( $raw, ':&' ) === false ) {
				$simple[] = $raw;
			} else {
				// Prefixes can change without a mapping write. Interpret them
				// live, including initial colons, aliases, and local interwikis.
				$identity = self::identity( $raw );
				if ( $identity !== null && isset( $identities[$identity] ) ) {
					$matches[] = $raw;
				}
			}
		}
		// The SQL ORDER BY gives a stable content-addressed cache key.
		$index = self::simpleIndex( $simple );
		foreach ( $identities as $identity => $unused ) {
			foreach ( $index[$identity] ?? [] as $raw ) {
				// Re-verify hits with the actual current title parser.
				if ( self::identity( $raw ) === $identity ) {
					$matches[] = $raw;
				}
			}
		}
		return count( $matches ) > self::MAX_CANDIDATES ? null : $matches;
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
