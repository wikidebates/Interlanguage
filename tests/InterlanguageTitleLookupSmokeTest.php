<?php

/** Isolated tests for live discovery, conservative rejection and local identities. */
namespace MediaWiki\Title {
	class Title {
		public static bool $capitalize = true;
		public static bool $aliasEnabled = true;
		public static int $calls = 0;

		private function __construct(
			private int $ns, private string $key, private string $fragment, private bool $external
		) {}

		public static function newFromText( string $raw ): ?self {
			self::$calls++;
			$raw = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$raw = preg_replace( '/[\x{200E}\x{200F}\x{202A}-\x{202E}]+/u', '', $raw );
			$raw = trim( preg_replace( '/[ _\x{00A0}\x{3000}]+/u', '_', $raw ), '_' );
			$raw = ltrim( ltrim( $raw, ':' ), '_' );
			$parts = explode( '#', $raw, 2 );
			$raw = rtrim( $parts[0], '_' );
			$ns = 0;
			$external = false;
			$aliases = self::$aliasEnabled ? 'Template|CustomAlias|Project|User' : 'Template|Project|User';
			if ( preg_match( '/^(' . $aliases . ')_*:_*(.*)$/i', $raw, $match ) ) {
				$ns = match ( strtolower( $match[1] ) ) {
					'project' => 4,
					'user' => 2,
					default => 10,
				};
				$raw = $match[2];
			} elseif ( str_starts_with( $raw, 'fr:' ) ) {
				$external = true;
			}
			if ( $ns === 2 && filter_var( $raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				$raw = strtoupper( bin2hex( inet_pton( $raw ) ) );
			} elseif ( self::$capitalize ) {
				$raw = mb_convert_case( mb_substr( $raw, 0, 1, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' ) .
					mb_substr( $raw, 1, null, 'UTF-8' );
			}
			return $raw !== '' ? new self( $ns, $raw, $parts[1] ?? '', $external ) : null;
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
	}
}

namespace {
	use MediaWiki\Title\Title;

	define( 'NS_USER', 2 );
	define( 'NS_USER_TALK', 3 );
	require_once dirname( __DIR__ ) . '/InterlanguageTitleLookup.php';

	function verifyLookup( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new \RuntimeException( $message );
		}
	}

	class TitleLookupTestDatabase {
		public array $native = [];
		public array $supplemental = [];
		public int $rangeReads = 0;
		public int $pointReads = 0;

		public function select( $tables, $fields, $conditions, $caller, $options, $joins = [] ): array {
			$native = ( is_array( $tables ) ? $tables['mapping'] : $tables ) === 'langlinks';
			$prefix = $native ? 'll' : 'ill';
			$rows = $native ? $this->native : $this->supplemental;
			if ( is_string( $tables ) ) {
				$this->rangeReads++;
				verifyLookup( $conditions === [ $prefix . '_lang' => 'en' ] &&
					$options === [ 'DISTINCT', 'ORDER BY' => $prefix . '_title', 'LIMIT' => 20001 ],
					'Read only the indexed language range, ordered and bounded' );
				$spellings = array_unique( array_column( $rows, 'target' ) );
				sort( $spellings, SORT_STRING );
				return array_map( static fn ( $raw ) => (object)[ 'target' => $raw ],
					array_slice( $spellings, 0, $options['LIMIT'] ) );
			}
			$this->pointReads++;
			verifyLookup( $conditions["mapping.{$prefix}_lang"] === 'en' &&
				is_array( $conditions["mapping.{$prefix}_title"] ) &&
				$options === [ 'LIMIT' => 257 ] &&
				$joins['sourcepage'][1] === "sourcepage.page_id = mapping.{$prefix}_from",
				'Join only actual indexed verified spelling candidates' );
			if ( !$native && isset( $joins['native'] ) ) {
				verifyLookup( $joins['native'][0] === 'LEFT JOIN' &&
					array_key_exists( 'native.ll_from', $conditions ) && $conditions['native.ll_from'] === null,
					'Exclude native-shadowed supplemental mappings' );
				$ids = array_column( $this->native, 'page_id' );
				$rows = array_filter( $rows, static fn ( $row ) => !in_array( $row->page_id, $ids, true ) );
			}
			$matches = array_filter( $rows, static fn ( $row ) =>
				in_array( $row->target, $conditions["mapping.{$prefix}_title"], true )
			);
			return array_slice( $matches, 0, $options['LIMIT'] );
		}
	}

	function row( int $id, string $raw ): object {
		return (object)[ 'page_id' => $id, 'page_namespace' => 0, 'page_title' => 'Central_' . $id,
			'page_is_redirect' => 0, 'target' => $raw ];
	}

	$db = new TitleLookupTestDatabase();
	$db->native = [
		row( 1, 'Topic: Argument' ),
		row( 2, 'Project:Argument' ),
		row( 3, 'Template:Argument#Section' ),
		row( 4, 'fr:Template:Argument' ),
		row( 5, ' CustomAlias : argument ' ),
		row( 10, '  Main__page  ' ),
	];
	$db->supplemental = [ row( 1, 'Template:Argument' ), row( 9, 'Template:Argument' ) ];

	$result = InterlanguageTitleLookup::findCandidates( $db, [ 'Template:Argument' ], 'en', true );
	verifyLookup( array_keys( $result ) === [ 5 ], 'Actual local parser separates aliases, ordinary colons, sections and interwikis' );
	$result = InterlanguageTitleLookup::findCandidates( $db, [ 'Template:Argument' ], 'en', false );
	verifyLookup( array_keys( $result ) === [ 9 ], 'Shadowed supplemental row is excluded' );
	$result = InterlanguageTitleLookup::findCandidates( $db, [ 'Template:Argument' ], 'en', false, true, true );
	verifyLookup( array_keys( $result ) === [ 1, 9 ], 'Lifecycle cleanup can collect shadowed rows explicitly' );

	$db->native[] = row( 11, 'Another main page' );
	$result = InterlanguageTitleLookup::findCandidates( $db, [ 'Main page' ], 'en', true );
	verifyLookup( array_keys( $result ) === [ 10 ], 'Live new spelling set is read without memoization' );
	$db->native[5] = row( 12, '  Main__page  ' );
	$result = InterlanguageTitleLookup::findCandidates( $db, [ 'Main page' ], 'en', true );
	verifyLookup( array_keys( $result ) === [ 12 ], 'Same spelling moved to another source uses live IDs' );

	$db->native[] = row( 13, 'Main_page' );
	$result = InterlanguageTitleLookup::findCandidates( $db, [ 'Main page' ], 'en', true );
	verifyLookup( count( $result ) === 2, 'New equivalent raw spelling creates an immediate ambiguity in the same process' );
	unset( $db->native[5] );
	$result = InterlanguageTitleLookup::findCandidates( $db, [ 'Main page' ], 'en', true );
	verifyLookup( array_keys( $result ) === [ 13 ], 'Deleted spelling cannot resurrect an old source' );

	Title::$aliasEnabled = false;
	verifyLookup( InterlanguageTitleLookup::findCandidates( $db, [ 'Template:Argument' ], 'en', true ) === [],
		'Changed namespace rules are interpreted by the parser' );
	Title::$aliasEnabled = true;
	$db->native[] = row( 14, 'Template: Argument' );
	$result = InterlanguageTitleLookup::findCandidates( $db, [ 'Template:Argument', 'Main page' ], 'en', true, true );
	verifyLookup( array_keys( $result ) === [ 5, 13, 14 ], 'Collect direct and redirect alias claims for lifecycle' );

	$db->native = [ row( 15, 'lowercase' ) ];
	verifyLookup( count( InterlanguageTitleLookup::findCandidates( $db, [ 'Lowercase' ], 'en', true ) ) === 1,
		'First-letter capitalization follows local rules' );
	Title::$capitalize = false;
	verifyLookup( InterlanguageTitleLookup::findCandidates( $db, [ 'Lowercase' ], 'en', true ) === [],
		'Case rules are determined by the parser, not the suffix' );
	Title::$capitalize = true;
	// Equivalence is still decided by the parser, including Unicode,
	// entities, empty fragments, ordinary colons and user/IP normalization.
	$variants = [
		[ "Template:\u{200E}argument\u{3000}", 'Template:Argument' ],
		[ "CustomAlias:\u{00A0}école", 'Template:École' ],
		[ 'Template:ﬃx', 'Template:Ffix' ],
		[ 'Template&#58;Argument', 'Template:Argument' ],
		[ 'Template:Argument#', 'Template:Argument' ],
		[ 'Topic: A__colon', 'Topic: A colon' ],
		[ 'User:2001:db8::1', 'User:2001:DB8:0:0:0:0:0:1' ],
	];
	foreach ( $variants as [ $raw, $target ] ) {
		$db->native = [ row( 16, $raw ) ];
		verifyLookup( array_keys( InterlanguageTitleLookup::findCandidates( $db, [ $target ], 'en', true ) ?? [] ) === [ 16 ],
			'Conservative rejection must retain equivalent spelling: ' . $raw );
	}

	$db->native = array_map( static fn ( $id ) => row( $id, 'Different raw title ' . $id ), range( 1, 5000 ) );
	$db->native[] = row( 5001, 'CustomAlias: Target unique suffix' );
	$calls = Title::$calls;
	verifyLookup( array_keys( InterlanguageTitleLookup::findCandidates( $db, [ 'Template:Target unique suffix' ], 'en', true ) ?? [] ) === [ 5001 ],
		'Find the exact candidate among 5000 distractors' );
	verifyLookup( Title::$calls - $calls < 10, 'Do not parse unrelated titles or build a cached full dictionary' );

	$db->native = array_map( static fn ( $id ) => row( $id, 'Too many claims' ), range( 1, 257 ) );
	verifyLookup( InterlanguageTitleLookup::findCandidates( $db, [ 'Too many claims' ], 'en', true, true ) === null,
		'An incomplete lifecycle candidate set is never accepted' );
	$db->native = array_map( static fn ( $id ) => row( $id, 'Distinct ' . $id ), range( 1, 20001 ) );
	verifyLookup( InterlanguageTitleLookup::findCandidates( $db, [ 'Distinct 1' ], 'en', true ) === null,
		'An overlarge language range cannot masquerade as complete' );

	echo "Interlanguage schema-free title lookup smoke tests: OK\n";
}
