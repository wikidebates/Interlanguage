<?php

/** Isolated normalized reverse lookup and WAN invalidation contract tests. */
namespace Wikimedia\Rdbms {
	class Database {
		public static function getCacheSetOptions( ...$dbs ): array {
			return [ 'lag' => 0 ];
		}
	}
}

namespace MediaWiki {
	class ReverseTestCache {
		public array $values = [];
		public array $versions = [];
		public int $builds = 0;

		public function makeKey( ...$parts ): string {
			return 'receiver:' . implode( ':', $parts );
		}

		public function makeGlobalKey( ...$parts ): string {
			return 'global:' . implode( ':', $parts );
		}

		public function getWithSetCallback( $key, $ttl, $callback, $opts ) {
			$check = $opts['checkKeys'][0];
			$version = $this->versions[$check] ?? 0;
			if ( isset( $this->values[$key] ) && $this->values[$key][0] === $version ) {
				return $this->values[$key][1];
			}
			$this->builds++;
			$setOpts = [];
			$value = $callback( null, $ttl, $setOpts );
			if ( $ttl > 120 || $opts['graceTTL'] !== 0 || isset( $opts['pcTTL'] ) || !isset( $setOpts['lag'] ) ) {
				throw new \RuntimeException( 'Expected bounded, transaction-aware WAN caching' );
			}
			$this->values[$key] = [ $version, $value ];
			return $value;
		}

		public function touchCheckKey( $key ): bool {
			$this->versions[$key] = ( $this->versions[$key] ?? 0 ) + 1;
			return true;
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		public ReverseTestCache $cache;

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function __construct() {
			$this->cache = new ReverseTestCache();
		}

		public function getMainWANObjectCache(): ReverseTestCache {
			return $this->cache;
		}

		public function getNamespaceInfo(): self {
			return $this;
		}

		public function getCanonicalNamespaces(): array {
			return [ 0 => '', 4 => 'Project', 10 => 'Template' ];
		}
	}
}

namespace {
	class Title {
		private function __construct( private int $ns, private string $key, private string $fragment, private bool $external ) {}

		public static function newFromText( string $raw ): ?self {
			$raw = trim( preg_replace( '/[ _]+/', '_', $raw ), '_' );
			$parts = explode( '#', $raw, 2 );
			$raw = rtrim( $parts[0], '_' );
			$ns = 0;
			$external = false;
			if ( preg_match( '/^(Template|CustomAlias|Project)_*:_*(.*)$/i', $raw, $match ) ) {
				$ns = strcasecmp( $match[1], 'Project' ) === 0 ? 4 : 10;
				$raw = $match[2];
			} elseif ( str_starts_with( $raw, 'fr:' ) ) {
				$external = true;
			}
			return $raw !== '' ? new self( $ns, ucfirst( $raw ), $parts[1] ?? '', $external ) : null;
		}

		public function canExist(): bool {
			return true;
		}

		public function isExternal(): bool {
			return $this->external;
		}

		public function getFragment(): string {
			return $this->fragment;
		}

		public function getNamespace(): int {
			return $this->ns;
		}

		public function getDBkey(): string {
			return $this->key;
		}

		public function getText(): string {
			return str_replace( '_', ' ', $this->key );
		}
	}

	require_once dirname( __DIR__ ) . '/InterlanguageExtension.php';
	require_once dirname( __DIR__ ) . '/InterlanguageReverseLookup.php';

	function checkReverse( bool $ok, string $message ): void {
		if ( !$ok ) {
			throw new \RuntimeException( $message );
		}
	}

	class ReverseTestDatabase {
		public array $native = [];
		public array $supplemental = [];
		public int $cold = 0;
		public int $live = 0;
		public array $afterCommit = [];

		public function getDomainID(): string {
			return 'central';
		}

		public function onTransactionCommitOrIdle( $callback, $caller ): void {
			$this->afterCommit[] = $callback;
		}

		public function select( $tables, $fields, $conditions, $caller, $options, $joins = [] ): array {
			if ( is_string( $tables ) ) {
				$this->cold++;
				$prefix = $tables === 'langlinks' ? 'll' : 'ill';
				checkReverse( $conditions === [ $prefix . '_lang' => 'en' ] &&
					$fields === [ 'target' => $prefix . '_title' ] &&
					$options === [ 'DISTINCT', 'LIMIT' => 20001 ], 'Cold scan must be bounded by language' );
				$rows = $prefix === 'll' ? $this->native : $this->supplemental;
				return array_map( static fn ( $raw ) => (object)[ 'target' => $raw ],
					array_values( array_unique( $rows ) ) );
			}

			$this->live++;
			$isNative = $tables['mapping'] === 'langlinks';
			$prefix = $isNative ? 'll' : 'ill';
			checkReverse( $conditions["mapping.{$prefix}_lang"] === 'en' &&
				is_array( $conditions["mapping.{$prefix}_title"] ) &&
				$options === [ 'LIMIT' => 257 ] && isset( $joins['sourcepage'] ),
				'Every live query must use exact language/title predicates' );
			$rows = $isNative ? $this->native : $this->supplemental;
			$result = [];
			foreach ( $rows as $id => $raw ) {
				if ( !in_array( $raw, $conditions["mapping.{$prefix}_title"], true ) ) {
					continue;
				}
				if ( isset( $tables['native'] ) && isset( $this->native[$id] ) ) {
					continue;
				}
				$result[] = (object)[ 'page_id' => $id, 'page_namespace' => 0,
					'page_title' => 'Central_' . $id, 'page_is_redirect' => 0, 'target' => $raw ];
			}
			return array_slice( $result, 0, 257 );
		}
	}

	$db = new ReverseTestDatabase();
	$db->native = [
		1 => 'Template:Argument',
		2 => '  CustomAlias : argument__ ',
		3 => 'Topic: Argument',
		4 => 'Template:Argument#Section',
		5 => 'Project:Argument',
		6 => 'fr:Template:Argument',
		7 => 'Exact title',
	];
	$db->supplemental = [
		2 => 'Template:Shadowed',
		8 => 'CustomAlias: Supplemental',
		9 => 'Template:Other alias',
	];
	$cache = \MediaWiki\MediaWikiServices::getInstance()->cache;
	$lookup = new InterlanguageReverseLookup( $db, 'en' );
	checkReverse( array_keys( $lookup->findCandidates( [ 'Template:Argument' ], true, true ) ) === [ 1, 2 ],
		'Recognize legitimate local aliases and ambiguity, not sections, foreign or main-namespace titles' );
	checkReverse( $db->cold === 2 && $cache->builds === 1, 'One cold scan per mapping table' );
	$cached = array_values( $cache->values )[0][1]['index'];
	checkReverse( !isset( $cached['0:Exact_title'] ) && isset( $cached['10:Argument'] ),
		'Only alternate spellings are cached; canonical titles are queried directly' );
	$lookup = new InterlanguageReverseLookup( $db, 'en' );
	checkReverse( array_keys( $lookup->findCandidates( [ 'Exact title' ], true ) ) === [ 7 ],
		'Canonical exact lookups remain indexed' );
	checkReverse( $db->cold === 2, 'Warm lookup has no language-wide SQL reads' );
	checkReverse( $lookup->findCandidates( [ 'Template:Shadowed' ], false ) === [],
		'Native mapping shadows supplemental row on same source' );
	checkReverse( array_keys( $lookup->findCandidates( [ 'Template:Supplemental' ], false ) ) === [ 8 ],
		'Supplemental variants are discoverable' );
	checkReverse( array_keys( $lookup->findCandidates( [ 'Template:Shadowed' ], false, true, true ) ) === [ 2 ],
		'Lifecycle can explicitly inspect a shadowed supplemental mapping' );

	unset( $db->native[2] );
	$lookup = new InterlanguageReverseLookup( $db, 'en' );
	checkReverse( array_keys( $lookup->findCandidates( [ 'Template:Argument' ], true ) ) === [ 1 ],
		'Cached old spelling cannot resurrect a deleted SQL row' );
	$db->native[10] = 'CustomAlias: Argument';
	$lookup = new InterlanguageReverseLookup( $db, 'en', true );
	checkReverse( count( $lookup->findCandidates( [ 'Template:Argument' ], true, true ) ) === 2,
		'Fresh lookup for data mutations sees new alternate spellings despite stale cache' );
	checkReverse( $db->cold === 4, 'Fresh lookup bypasses cache without replacing it' );
	InterlanguageReverseLookup::invalidateAfterCommit( $db, 'en' );
	checkReverse( $cache->versions === [], 'No invalidation before commit' );
	( $db->afterCommit[0] )();
	$lookup = new InterlanguageReverseLookup( $db, 'en' );
	checkReverse( count( $lookup->findCandidates( [ 'Template:Argument' ], true, true ) ) === 2,
		'Commit invalidation exposes a new alternate spelling to a subsequent lookup' );
	checkReverse( $db->cold === 6 && $cache->builds === 2, 'Invalidate only the affected dictionary' );
	checkReverse( array_keys( $lookup->findCandidates( [ 'Template:Argument', 'Template:Other alias' ], false, true ) ) === [ 9 ],
		'Redirect aliases can be queried as one candidate set' );
	$db->native = array_fill( 0, 257, 'Template:Argument' );
	$lookup = new InterlanguageReverseLookup( $db, 'en', true );
	checkReverse( $lookup->findCandidates( [ 'Template:Argument' ], true, true ) === null,
		'Overwide candidate discovery cannot claim completeness' );
	$db->native = [];
	for ( $i = 0; $i <= 20000; $i++ ) {
		$db->native[$i] = 'Page ' . $i;
	}
	$lookup = new InterlanguageReverseLookup( $db, 'en', true );
	checkReverse( $lookup->findCandidates( [ 'Template:Argument' ], true ) === null,
		'An over-limit cold inventory is not treated as a complete dictionary' );

	echo "Interlanguage reverse lookup smoke tests: OK\n";
}
