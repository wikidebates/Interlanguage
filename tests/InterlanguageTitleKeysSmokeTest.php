<?php

/** Isolated tests for coarse candidate discovery and authoritative verification. */
namespace MediaWiki\Parser {
	class Sanitizer {
		public static function decodeCharReferencesAndNormalize( string $text ): string {
			return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}
}
namespace Wikimedia {
	class IPUtils {
		public static function isIPAddress( string $text ): bool {
			return filter_var( $text, FILTER_VALIDATE_IP ) !== false;
		}
		public static function sanitizeIP( string $text ): string {
			return bin2hex( inet_pton( $text ) );
		}
	}
}
namespace MediaWiki {
	class MediaWikiServices {
		public static function getInstance(): self {
			return new self();
		}
		public function getMainConfig(): self {
			return $this;
		}
		public function get( string $name ): array {
			return [];
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
	}

	require_once dirname( __DIR__ ) . '/InterlanguageTitleKeys.php';

	function verifyTitleKey( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function overlaps( string $raw, string $title, array $overrides = [] ): bool {
		return (bool)array_intersect(
			InterlanguageTitleKeys::storedKeys( $raw ),
			InterlanguageTitleKeys::lookupKeys( $title, $overrides )
		);
	}

	foreach ( [
		'Template:Argument with spaces',
		' Template: Argument__with_spaces ',
		"template :__argument\u{00A0}with spaces",
		'CustomAlias:Argument_with_spaces',
		'Template:Argument with spaces#',
	] as $raw ) {
		verifyTitleKey( overlaps( $raw, 'Template:Argument with spaces' ), 'Equivalent spelling must be discoverable: ' . $raw );
	}
	verifyTitleKey( overlaps( 'Template:ﬃx', 'Template:Ffix' ), 'Unicode first-letter expansion' );
	verifyTitleKey( overlaps( 'Template:école', 'Template:ABCDEcole', [ 'é' => 'ABCDE' ] ), 'Custom first-letter expansion' );
	verifyTitleKey( overlaps( 'User:2001:db8::1', 'User:2001:DB8:0:0:0:0:0:1' ), 'IP normalization' );
	verifyTitleKey( overlaps( 'Topic: Argument', 'Template:Argument' ), 'Ordinary colon is deliberately only a coarse candidate' );

	class TitleKeysTestDatabase {
		public array $rows = [];
		public array $writes = [];
		public int $queries = 0;

		public function insert( $table, $rows, $caller, $options ): void {
			verifyTitleKey( $table === 'interlanguage_title_keys' && $options === [ 'IGNORE' ], 'Only auxiliary keys can be inserted' );
			$this->writes = $rows;
		}

		public function select( $tables, $fields, $conditions, $caller, $options, $joins ): array {
			$this->queries++;
			verifyTitleKey( $tables['titlekey'] === 'interlanguage_title_keys' &&
				$conditions['titlekey.itk_lang'] === 'en' && is_array( $conditions['titlekey.itk_key'] ) &&
				$options === [ 'DISTINCT', 'LIMIT' => 257 ], 'Expected bounded indexed candidate query' );
			verifyTitleKey( str_contains( $joins['mapping'][1], 'titlekey.itk_title' ) &&
				str_contains( $joins['mapping'][1], 'titlekey.itk_lang' ), 'Join actual live raw mappings' );
			if ( $tables['mapping'] === 'interlanguage_links' ) {
				verifyTitleKey( $joins['native'][0] === 'LEFT JOIN' &&
					array_key_exists( 'native.ll_from', $conditions ) && $conditions['native.ll_from'] === null,
					'Supplemental candidates must exclude native-shadowed mappings' );
			}
			return array_slice( $this->rows, 0, $options['LIMIT'] );
		}
	}

	function candidateRow( int $id, string $target ): object {
		return (object)[ 'page_id' => $id, 'page_namespace' => 0, 'page_title' => 'Central_' . $id,
			'page_is_redirect' => 0, 'target' => $target ];
	}

	$db = new TitleKeysTestDatabase();
	$db->rows = [
		candidateRow( 1, 'Topic: Argument' ),
		candidateRow( 2, 'Project:Argument' ),
		candidateRow( 3, 'Template:Argument#Section' ),
		candidateRow( 4, 'fr:Template:Argument' ),
		candidateRow( 5, 'CustomAlias: Argument' ),
	];
	$result = InterlanguageTitleKeys::findCandidates( $db, [ 'Template:Argument' ], 'en', true );
	verifyTitleKey( array_keys( $result ) === [ 5 ], 'Only the receiving wiki parser decides equivalence' );
	$db->rows[] = candidateRow( 6, 'Template: Argument' );
	$result = InterlanguageTitleKeys::findCandidates( $db, [ 'Template:Argument' ], 'en', false );
	verifyTitleKey( count( $result ) === 2, 'Detect equivalent claims from different central sources' );
	$db->rows = array_fill( 0, 257, candidateRow( 7, 'Unrelated:Argument' ) );
	verifyTitleKey( InterlanguageTitleKeys::findCandidates( $db, [ 'Template:Argument' ], 'en', true ) === null,
		'An incomplete candidate scan must not authorize a fallback' );
	InterlanguageTitleKeys::remember( $db, 'en', 'CustomAlias: Argument' );
	verifyTitleKey( $db->writes === InterlanguageTitleKeys::rows( 'en', 'CustomAlias: Argument' ), 'Persist raw spellings without rewriting them' );

	echo "Interlanguage title keys smoke tests: OK\n";
}
