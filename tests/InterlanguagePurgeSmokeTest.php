<?php
/**
 * Test remote purge response diagnostics without sending HTTP requests.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguagePurgeSmokeTest.php
 */
namespace MediaWiki\Logger {
	class InterlanguagePurgeTestLogger {
		public array $warnings = [];

		public function warning( string $message, array $context = [] ): void {
			$this->warnings[] = [ $message, $context ];
		}
	}

	class LoggerFactory {
		private static ?InterlanguagePurgeTestLogger $logger = null;

		public static function getInstance( string $channel ): InterlanguagePurgeTestLogger {
			if ( $channel !== 'Interlanguage' ) {
				throw new \RuntimeException( 'Unexpected log channel' );
			}
			return self::$logger ??= new InterlanguagePurgeTestLogger();
		}
	}
}

namespace MediaWiki {
	class InterlanguagePurgeTestInterwiki {
		public function getAPI(): string {
			return 'https://example.invalid/w/api.php';
		}
	}

	class InterlanguagePurgeTestInterwikiLookup {
		public function fetch( string $lang ) {
			return in_array( $lang, [ 'en', 'fr', 'de' ], true ) ? new InterlanguagePurgeTestInterwiki() : false;
		}
	}

	class InterlanguagePurgeTestHttp {
		public $response = null;
		public array $urls = [];

		public function post( string $url, array $options = [], string $caller = '' ) {
			$this->urls[] = $url;
			return $this->response;
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		public InterlanguagePurgeTestHttp $http;
		public InterlanguagePurgeTestInterwikiLookup $lookup;

		private function __construct() {
			$this->http = new InterlanguagePurgeTestHttp();
			$this->lookup = new InterlanguagePurgeTestInterwikiLookup();
		}

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function getInterwikiLookup(): InterlanguagePurgeTestInterwikiLookup {
			return $this->lookup;
		}

		public function getHttpRequestFactory(): InterlanguagePurgeTestHttp {
			return $this->http;
		}
	}
}

namespace {
	class Title {}

	class Job {
		protected array $params;

		public function __construct( string $type, Title $title, array $params ) {
			$this->params = $params;
		}
	}

	function wfArrayToCGI( array $params ): string {
		return http_build_query( $params );
	}

	require_once dirname( __DIR__ ) . '/InterlanguageCentralExtensionPurgeJob.php';

	function assertInterlanguagePurge( bool $ok, string $message ): void {
		if ( !$ok ) {
			throw new \RuntimeException( $message );
		}
	}

	$wgLanguageCode = 'fr';
	$services = \MediaWiki\MediaWikiServices::getInstance();
	$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'Interlanguage' );
	$job = new \InterlanguageCentralExtensionPurgeJob(
		new Title(),
		[ 'ill' => [ 'en' => [ 'Topic:Subtopic' => true ], 'xx' => [ 'Missing' => true ] ] ]
	);

	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":true,"linkupdate":true}]}';
	assertInterlanguagePurge( $job->run() === true, 'Successful purge must complete' );
	assertInterlanguagePurge(
		count( $logger->warnings ) === 0,
		'Successful purge should not generate a warning'
	);
	assertInterlanguagePurge(
		count( $services->http->urls ) === 1 &&
			str_contains( $services->http->urls[0], 'action=purge' ) &&
			str_contains( $services->http->urls[0], 'Topic%3ASubtopic' ),
		'Purge must target the existing remote wiki and retain the full title'
	);

	$services->http->response = '{"error":{"code":"permissiondenied"}}';
	assertInterlanguagePurge( $job->run() === true, 'Purge diagnostics must preserve the current job semantics' );
	assertInterlanguagePurge( count( $logger->warnings ) === 1, 'API errors must be logged' );

	$services->http->response = null;
	assertInterlanguagePurge( $job->run() === true, 'Network failure must preserve the current job semantics' );
	assertInterlanguagePurge( count( $logger->warnings ) === 2, 'Missing HTTP responses must be logged' );

	// A dependent automatically receives a non-recursive link update.
	assertInterlanguagePurge( str_contains( $services->http->urls[0], 'forcelinkupdate=1' ), 'Dependent targets always request a targeted link update' );
	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":"","linkupdate":""}]}';
	$job->run();
	$url = end( $services->http->urls );
	assertInterlanguagePurge(
		str_contains( $url, 'forcelinkupdate=1' ) && str_contains( $url, 'redirects=1' ) &&
			!str_contains( $url, 'forcerecursivelinkupdate' ),
		'Dependent purge must request only a targeted link update and resolve redirects'
	);
	assertInterlanguagePurge( count( $logger->warnings ) === 2, 'API v1 empty-string flags are valid acknowledgements' );

	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":""}],"warnings":{"purge":{"*":"Permission denied"}}}';
	$job->run();
	assertInterlanguagePurge( count( $logger->warnings ) === 3, 'Plain purge must not be mistaken for a link update' );

	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","missing":""}]}';
	$job->run();
	assertInterlanguagePurge( count( $logger->warnings ) === 3, 'Deleted peers need no link update' );

	$services->http->response = '{"purge":[{"title":"Topic:Subtopic","purged":"","linkupdate":""}],"continue":{"continue":"next"}}';
	$job->run();
	assertInterlanguagePurge( count( $logger->warnings ) === 4, 'Incomplete API results must be reported' );

	// Jobs sent from a dependent wiki still recognize the central target.
	$wgLanguageCode = 'de';
	$wgInterlanguageExtensionInterwiki = 'fr:';
	$centralJob = new \InterlanguageCentralExtensionPurgeJob( new Title(), [ 'ill' => [ 'fr' => [ 'Central' => true ] ] ] );
	$services->http->response = '{"purge":[{"title":"Central","purged":""}]}';
	$centralJob->run();
	$url = end( $services->http->urls );
	assertInterlanguagePurge( !str_contains( $url, 'forcelinkupdate' ) && !str_contains( $url, 'redirects=1' ),
		'Central wiki needs only ordinary purge, including jobs from dependents' );
	assertInterlanguagePurge( count( $logger->warnings ) === 4, 'Successful central purge needs no linkupdate acknowledgement' );
	$services->http->response = '{"purge":[{"title":"Central"}]}';
	$centralJob->run();
	assertInterlanguagePurge( count( $logger->warnings ) === 5, 'Unacknowledged plain purges must also be reported' );

	$many = [];
	for ( $i = 1; $i <= 51; $i++ ) {
		$many['Page ' . $i] = true;
	}
	$services->http->urls = [];
	$services->http->response = '{"purge":[{"title":"Page","purged":"","linkupdate":""}]}';
	$batchJob = new \InterlanguageCentralExtensionPurgeJob( new Title(), [ 'ill' => [ 'en' => $many ] ] );
	$batchJob->run();
	assertInterlanguagePurge( count( $services->http->urls ) === 2, 'Limit a purge API batch to 50 titles' );
	$counts = [];
	foreach ( $services->http->urls as $url ) {
		parse_str( parse_url( $url, PHP_URL_QUERY ), $params );
		$counts[] = count( explode( '|', $params['titles'] ) );
	}
	assertInterlanguagePurge( $counts === [ 50, 1 ], 'No titles may be lost while batching' );

	echo "Interlanguage purge smoke tests: OK\n";
}
