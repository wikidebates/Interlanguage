<?php
/** Exercise indexed redirect lookups independently of a MediaWiki installation. */
namespace MediaWiki {
	class MediaWikiServices {
		public static function getInstance(): self {
			return new self();
		}

		public function getNamespaceInfo(): self {
			return $this;
		}

		public function getCanonicalNamespaces(): array {
			return [ 0 => '', 10 => 'Template' ];
		}
	}
}

namespace {
	class Title {
		public function __construct( private int $ns, private string $key ) {}

		public static function newFromText( string $text ): self {
			if ( str_starts_with( $text, 'Template:' ) ) {
				return new self( 10, str_replace( ' ', '_', substr( $text, 9 ) ) );
			}
			return new self( 0, str_replace( ' ', '_', $text ) );
		}

		public static function newFromRow( object $row ): self {
			return new self( (int)$row->page_namespace, $row->page_title );
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

	class RedirectTestDatabase {
		public array $pages = [];
		public array $redirects = [];
		public int $calls = 0;

		public function page( int $id, int $ns, string $key, bool $redirect = false ): void {
			$this->pages[$ns . ':' . $key] = (object)[
				'page_id' => $id, 'page_namespace' => $ns, 'page_title' => $key,
				'page_is_redirect' => (int)$redirect,
			];
		}

		public function redirect( int $id, int $ns, string $key, ?string $iw = null, ?string $fragment = null ): void {
			$this->redirects[$id] = (object)[
				'rd_namespace' => $ns, 'rd_title' => $key, 'rd_interwiki' => $iw,
				'rd_fragment' => $fragment,
			];
		}

		public function selectRow( $table, $fields, $conditions, $caller ) {
			$this->calls++;
			if ( $table === 'page' && array_keys( $conditions ) === [ 'page_namespace', 'page_title' ] ) {
				return $this->pages[$conditions['page_namespace'] . ':' . $conditions['page_title']] ?? false;
			}
			if ( $table === 'redirect' && array_keys( $conditions ) === [ 'rd_from' ] ) {
				return $this->redirects[$conditions['rd_from']] ?? false;
			}
			throw new \RuntimeException( 'Non-indexed or unexpected forward query' );
		}

		public function select( $tables, $fields, $conditions, $caller, $options ): array {
			$this->calls++;
			if ( $tables !== [ 'redirect', 'page' ] || !isset( $conditions['rd_namespace'], $conditions['rd_title'] ) ||
				!in_array( 'rd_from = page_id', $conditions, true ) || $conditions['page_is_redirect'] !== 1
			) {
				throw new \RuntimeException( 'Non-indexed or unexpected reverse query' );
			}
			$matches = [];
			foreach ( $this->pages as $page ) {
				$redirect = $this->redirects[$page->page_id] ?? null;
				if ( $redirect && $page->page_is_redirect &&
					$redirect->rd_namespace === $conditions['rd_namespace'] &&
					$redirect->rd_title === $conditions['rd_title']
				) {
					$matches[] = (object)( (array)$page + (array)$redirect );
				}
			}
			return array_slice( $matches, 0, $options['LIMIT'] );
		}
	}

	class RedirectTestExtension extends \InterlanguageExtension {
		public array $readIds = [];

		public function readLinksFromCentralDB( $db, $articleid ): array {
			$this->readIds[] = $articleid;
			return [ [ 'lang' => 'en', '*' => 'Resolved ' . $articleid ] ];
		}
	}

	function verifyRedirect( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$db = new RedirectTestDatabase();
	$db->page( 1, 0, 'Origin', true );
	$db->page( 2, 10, 'Middle', true );
	$db->page( 3, 10, 'Destination' );
	$db->page( 4, 0, 'Destination' );
	$db->redirect( 1, 10, 'Middle' );
	$db->redirect( 2, 10, 'Destination' );

	$extension = new RedirectTestExtension();
	$extension->foreignDbr = $db;
	$links = $extension->getLinksFromDB( 'Origin' );
	verifyRedirect( $extension->readIds === [ 3 ], 'Follow a chain in its real namespace, never the same-name main page' );
	verifyRedirect( $links['query']['pages'][0]['langlinks'][0]['*'] === 'Resolved 3', 'Return the effective destination links' );

	$db->page( 5, 0, 'Broken', true );
	$db->redirect( 5, 10, 'Missing' );
	$db->page( 6, 0, 'External', true );
	$db->redirect( 6, 10, 'Destination', 'en' );
	$db->page( 7, 0, 'Loop', true );
	$db->redirect( 7, 0, 'Loop' );
	$db->page( 8, 0, 'No_row', true );
	$db->page( 9, 0, 'Section', true );
	$db->redirect( 9, 10, 'Destination', null, 'Heading' );
	$db->page( 10, 0, 'Cycle_a', true );
	$db->page( 11, 0, 'Cycle_b', true );
	$db->redirect( 10, 0, 'Cycle_b' );
	$db->redirect( 11, 0, 'Cycle_a' );
	for ( $i = 0; $i <= 17; $i++ ) {
		$db->page( 100 + $i, 0, 'Long_' . $i, $i < 17 );
		if ( $i < 17 ) {
			$db->redirect( 100 + $i, 0, 'Long_' . ( $i + 1 ) );
		}
	}

	foreach ( [ 'Broken', 'External', 'Loop', 'No row', 'Cycle a', 'Long 0', 'Absent' ] as $bad ) {
		$result = $extension->getLinksFromDB( $bad );
		verifyRedirect( isset( $result['query']['pages'][-1]['missing'] ), 'Missing result for ' . $bad );
	}
	verifyRedirect( $extension->readIds === [ 3 ], 'Invalid redirects must not read any translation group' );
	$extension->getLinksFromDB( 'Section' );
	verifyRedirect( $extension->readIds === [ 3, 3 ], 'An explicit section lookup reads the containing page' );

	$incoming = new \ReflectionMethod( \InterlanguageExtension::class, 'getLocalRedirectTitles' );
	$targets = $incoming->invoke( $extension, $db, new Title( 10, 'Destination' ) );
	verifyRedirect( $targets === [ 'Template:Destination', 'Template:Middle', 'Origin' ],
		'Reverse lookup includes indirect aliases but excludes interwiki and section redirects' );
	verifyRedirect( $incoming->invoke( $extension, $db, new Title( 0, 'Destination' ) ) === [ 'Destination' ],
		'Reverse aliases must not cross namespaces by title text alone' );
	verifyRedirect( $incoming->invoke( $extension, $db, new Title( 0, 'Long_17' ) ) === null,
		'Overlong reverse chains must be reported as incomplete' );

	$wide = new RedirectTestDatabase();
	$wide->page( 1, 0, 'Target' );
	for ( $i = 2; $i <= 66; $i++ ) {
		$wide->page( $i, 0, 'Alias_' . $i, true );
		$wide->redirect( $i, 0, 'Target' );
	}
	verifyRedirect( $incoming->invoke( $extension, $wide, new Title( 0, 'Target' ) ) === null,
		'Overwide alias searches cannot silently claim completeness' );

	echo "Interlanguage redirect smoke tests: OK\n";
}
