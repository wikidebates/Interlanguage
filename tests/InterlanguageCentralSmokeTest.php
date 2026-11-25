<?php
/**
 * Test central language-link injection without a database connection.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguageCentralSmokeTest.php
 */
namespace MediaWiki\Title {
	class Title {
		public function getArticleID(): int {
			return 42;
		}
	}
}

namespace MediaWiki\Parser {
	class ParserOutput {
		public function __construct( private array $links ) {}
		public function getLanguageLinks(): array {
			return $this->links;
		}
	}
}

namespace MediaWiki {
	class InterlanguageCentralTestQueue {
		public array $jobs = [];
		public function push( $job ): void {
			$this->jobs[] = $job;
		}
	}

	class InterlanguageCentralTestDB {
		public array $conditions = [];
		public array $native = [];
		public int $supplementalReads = 0;

		public function makeList( array $values ): string {
			return "'" . implode( "','", $values ) . "'";
		}

		public function select( $table, $fields, $conditions, $caller ): array {
			$this->conditions = $conditions;
			if ( $table === 'langlinks' ) {
				return $this->native;
			}
			$this->supplementalReads++;
			return [
				(object)[ 'ill_lang' => 'en', 'ill_title' => 'A different target' ],
				(object)[ 'ill_lang' => 'fr', 'ill_title' => 'Projet:Essai' ],
				(object)[ 'ill_lang' => 'de', 'ill_title' => 'Projekt:Test:2' ],
			];
		}
	}

	class InterlanguageCentralTestConnections {
		public InterlanguageCentralTestDB $db;

		public function __construct() {
			$this->db = new InterlanguageCentralTestDB();
		}

		public function getPrimaryDatabase(): InterlanguageCentralTestDB {
			return $this->db;
		}

		public function getReplicaDatabase(): InterlanguageCentralTestDB {
			return $this->db;
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		private InterlanguageCentralTestConnections $connections;
		private InterlanguageCentralTestQueue $queue;

		private function __construct() {
			$this->connections = new InterlanguageCentralTestConnections();
			$this->queue = new InterlanguageCentralTestQueue();
		}

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function getJobQueueGroup(): InterlanguageCentralTestQueue {
			return $this->queue;
		}

		public function getConnectionProvider(): InterlanguageCentralTestConnections {
			return $this->connections;
		}
	}
}

namespace {
	define( 'DB_PRIMARY', 0 );
	define( 'DB_REPLICA', 1 );

	class InterlanguageCentralExtensionPurgeJob {
		public function __construct( public $title, public array $params ) {}
	}

	class InterlanguageCentralTestUpdate {
		public function __construct( private $title, private $output ) {}
		public function getTitle() {
			return $this->title;
		}
		public function getPageId(): int {
			return $this->title->getArticleID();
		}
		public function getParserOutput() {
			return $this->output;
		}
	}

	require_once dirname( __DIR__ ) . '/InterlanguageCentralExtension.php';

	function assertInterlanguageCentral( bool $ok, string $message ): void {
		if ( !$ok ) {
			throw new \RuntimeException( $message );
		}
	}

	$wgLanguageCode = 'fr';
	$links = [ 'en:Topic:Subtopic' ];
	$linkFlags = [];
	$title = new \MediaWiki\Title\Title();
	\InterlanguageCentralExtension::onLanguageLinks( $title, $links, $linkFlags );

	assertInterlanguageCentral(
		$links === [
			'en:Topic:Subtopic',
			'de:Projekt:Test:2',
		],
		'Central links must preserve colons, skip self mappings and prefer existing language links'
	);
	$db = \MediaWiki\MediaWikiServices::getInstance()
		->getConnectionProvider()->getReplicaDatabase();
	assertInterlanguageCentral(
		$db->conditions['ill_from'] === 42,
		'Central links were queried for the wrong page'
	);

	// Verify the actual MediaWiki 1.43 list format, not a language-keyed array.
	$db->native = [
		(object)[ 'll_lang' => 'en', 'll_title' => 'Old:Title' ],
		(object)[ 'll_lang' => 'es', 'll_title' => 'Removed title' ],
	];
	$queue = \MediaWiki\MediaWikiServices::getInstance()->getJobQueueGroup();
	$update = new InterlanguageCentralTestUpdate( $title,
		new \MediaWiki\Parser\ParserOutput( [
			'en:New:Title', 'en:Ignored duplicate', 'it:Added title', 'fr:Self',
		] )
	);
	\InterlanguageCentralExtension::onLinksUpdate( $update );
	assertInterlanguageCentral( $queue->jobs === [], 'Do not purge before link-table commit' );
	\InterlanguageCentralExtension::onLinksUpdateComplete( $update, null );
	assertInterlanguageCentral( count( $queue->jobs ) === 1, 'Exactly one job after commit' );
	$expected = [
		'de' => [ 'Projekt:Test:2' => true ],
		'en' => [ 'Old:Title' => true, 'New:Title' => true ],
		'es' => [ 'Removed title' => true ],
		'it' => [ 'Added title' => true ],
	];
	assertInterlanguageCentral(
		$queue->jobs[0]->params['ill'] === $expected,
		'Purge every affected peer, retaining colons and native precedence'
	);
	\InterlanguageCentralExtension::onLinksUpdateComplete( $update, null );
	assertInterlanguageCentral( count( $queue->jobs ) === 1, 'Completion is idempotent' );

	$reads = $db->supplementalReads;
	$unchanged = new InterlanguageCentralTestUpdate( $title,
		new \MediaWiki\Parser\ParserOutput( [ 'es:Removed title', 'en:Old:Title' ] )
	);
	\InterlanguageCentralExtension::onLinksUpdate( $unchanged );
	\InterlanguageCentralExtension::onLinksUpdateComplete( $unchanged, null );
	assertInterlanguageCentral( count( $queue->jobs ) === 1 && $db->supplementalReads === $reads,
		'Unchanged native mappings need neither a purge nor a supplemental query' );

	echo "Interlanguage central smoke tests: OK\n";
}
