<?php

declare( strict_types=1 );

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\Database;

/**
 * Cache equivalent raw spellings using the receiving wiki's title parser.
 * Actual mappings are always selected from the original SQL tables.
 */
final class InterlanguageReverseLookup {
	private const TTL = 120;
	private const MAX_SPELLINGS = 20000;
	private const MAX_CANDIDATES = 256;

	private bool $loaded = false;
	private ?array $spellings = null;

	public function __construct(
		private $db,
		private string $language,
		private bool $fresh = false
	) {
	}

	public static function checkKey( $cache, string $domain, string $language ): string {
		return $cache->makeGlobalKey( 'interlanguage-reverse-change-v1', $domain, $language );
	}

	public static function invalidate( $db, string $language ): void {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		if ( !$cache->touchCheckKey( self::checkKey( $cache, $db->getDomainID(), $language ) ) ) {
			LoggerFactory::getInstance( 'Interlanguage' )->warning(
				'Unable to invalidate the reverse spelling cache for {language}',
				[ 'language' => $language ]
			);
		}
	}

	public static function invalidateAfterCommit( $db, string $language ): void {
		$db->onTransactionCommitOrIdle( static function () use ( $db, $language ) {
			self::invalidate( $db, $language );
		}, __METHOD__ );
	}

	private static function identity( string $raw ): ?string {
		$title = Title::newFromText( $raw );
		if ( !$title || !$title->canExist() || $title->isExternal() || $title->getFragment() !== '' ) {
			return null;
		}
		return $title->getNamespace() . ':' . $title->getDBkey();
	}

	/** Cold reads are bounded, language-scoped, and use covering indexes. */
	private function buildSpellings(): ?array {
		$index = [];
		$scanned = 0;
		foreach ( [ 'langlinks' => 'll', 'interlanguage_links' => 'ill' ] as $table => $prefix ) {
			$rows = $this->db->select(
				$table, [ 'target' => $prefix . '_title' ], [ $prefix . '_lang' => $this->language ],
				__METHOD__, [ 'DISTINCT', 'LIMIT' => self::MAX_SPELLINGS + 1 ]
			);
			foreach ( $rows as $row ) {
				if ( ++$scanned > self::MAX_SPELLINGS ) {
					return null;
				}
				$identity = self::identity( $row->target );
				if ( $identity !== null ) {
					$index[$identity][$row->target] = true;
				}
			}
		}
		return $index;
	}

	private function getSpellings(): ?array {
		if ( !$this->loaded ) {
			$this->loaded = true;
			if ( $this->fresh ) {
				$this->spellings = $this->buildSpellings();
			} else {
				$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
				$domain = $this->db->getDomainID();
				// Local title semantics, global invalidation. Do not enable
				// a process cache: permanent workers must see changes too.
				$value = $cache->getWithSetCallback(
					$cache->makeKey( 'interlanguage-reverse-v1', $domain, $this->language ),
					self::TTL,
					function ( $old, &$ttl, &$setOpts ) {
						$index = $this->buildSpellings();
						$setOpts += Database::getCacheSetOptions( $this->db );
						if ( $index === null ) {
							$ttl = 15;
						}
						return [ 'index' => $index ];
					},
					[
						'checkKeys' => [ self::checkKey( $cache, $domain, $this->language ) ],
						'segmentable' => true,
						'graceTTL' => 0,
					]
				);
				$this->spellings = is_array( $value['index'] ?? null ) ? $value['index'] : null;
			}
		}
		return $this->spellings;
	}

	/**
	 * Find live candidates with exact indexed predicates; verify the local
	 * identities again. Null means incomplete lookup, not absence.
	 * @return array<int,object>|null
	 */
	public function findCandidates(
		array $targets, bool $native, bool $collectAll = false, bool $includeShadowed = false
	): ?array {
		$index = $this->getSpellings();
		if ( $index === null ) {
			return null;
		}
		$identities = [];
		$raws = [];
		foreach ( $targets as $target ) {
			$identity = self::identity( $target );
			if ( $identity === null ) {
				return null;
			}
			$identities[$identity] = true;
			$raws[$target] = true;
			foreach ( $index[$identity] ?? [] as $raw => $unused ) {
				$raws[$raw] = true;
			}
		}
		if ( !$raws || count( $raws ) > self::MAX_CANDIDATES ) {
			return null;
		}

		$prefix = $native ? 'll' : 'ill';
		$tables = [ 'mapping' => $native ? 'langlinks' : 'interlanguage_links', 'sourcepage' => 'page' ];
		$joins = [ 'sourcepage' => [ 'JOIN', "sourcepage.page_id = mapping.{$prefix}_from" ] ];
		$conditions = [
			"mapping.{$prefix}_lang" => $this->language,
			"mapping.{$prefix}_title" => array_map( 'strval', array_keys( $raws ) ),
		];
		if ( !$native && !$includeShadowed ) {
			$tables['native'] = 'langlinks';
			$joins['native'] = [ 'LEFT JOIN', 'native.ll_from = mapping.ill_from AND native.ll_lang = mapping.ill_lang' ];
			$conditions['native.ll_from'] = null;
		}
		$rows = $this->db->select( $tables, [
			'page_id' => 'sourcepage.page_id', 'page_namespace' => 'sourcepage.page_namespace',
			'page_title' => 'sourcepage.page_title', 'page_is_redirect' => 'sourcepage.page_is_redirect',
			'target' => "mapping.{$prefix}_title",
		], $conditions, __METHOD__, [ 'LIMIT' => self::MAX_CANDIDATES + 1 ], $joins );
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
