<?php

/**
 * Populate or verify the central title-spelling dictionary without rendering
 * pages, editing mapping tables, or accessing another edition's database.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
require_once dirname( __DIR__, 3 ) . '/maintenance/Maintenance.php';

class InterlanguageBuildTitleKeys extends Maintenance {
	private const BATCH = 500;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Verify the central title dictionary; add missing keys only with --apply.' );
		$this->addOption( 'apply', 'Insert missing auxiliary keys. Does not change native or supplemental mappings.' );
	}

	public function execute() {
		if ( !class_exists( 'InterlanguageCentralExtension' ) ||
			!class_exists( 'InterlanguageTitleKeys' ) ||
			( $GLOBALS['wgInterlanguageExtensionDB'] ?? false )
		) {
			throw new RuntimeException( 'Run on the central wiki only.' );
		}
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		if ( !$db->tableExists( 'interlanguage_title_keys', __METHOD__ ) ) {
			throw new RuntimeException( 'Create the central interlanguage_title_keys schema first.' );
		}
		$apply = $this->hasOption( 'apply' );
		if ( $apply && !( $GLOBALS['wgInterlanguageMaintainTitleKeys'] ?? false ) ) {
			throw new RuntimeException( 'Enable dictionary maintenance on the central and all dependent writers before backfilling.' );
		}
		$checked = 0;
		$unindexable = 0;
		$missing = 0;
		$batch = [];
		// Offline inventory: the only operation allowed to enumerate historical
		// mapping rows. Rendering always uses the indexed reverse query.
		foreach ( [ 'langlinks' => 'll', 'interlanguage_links' => 'ill' ] as $table => $prefix ) {
			foreach ( $db->select(
				$table,
				[ 'language' => $prefix . '_lang', 'target' => $prefix . '_title' ],
				[], __METHOD__, [ 'DISTINCT' ]
			) as $mapping ) {
				$checked++;
				$rows = InterlanguageTitleKeys::rows( $mapping->language, $mapping->target );
				if ( !$rows ) {
					$unindexable++;
					continue;
				}
				foreach ( $rows as $row ) {
					$batch[serialize( $row )] = $row;
				}
				if ( count( $batch ) >= self::BATCH ) {
					$missing += $this->checkBatch( $db, array_values( $batch ), $apply );
					$batch = [];
				}
			}
		}
		if ( $batch ) {
			$missing += $this->checkBatch( $db, array_values( $batch ), $apply );
		}
		$this->output( "Raw mapping spellings checked: $checked\nUnindexable spellings: $unindexable\nMissing auxiliary keys: $missing\n" );
		if ( $apply ) {
			$this->output( "Backfill completed. Run again without --apply to verify coverage before activating readers.\n" );
		} elseif ( $missing ) {
			throw new RuntimeException( 'Dictionary incomplete. Keep normalized reverse readers disabled.' );
		} else {
			$this->output( "Dictionary coverage: OK\n" );
		}
	}

	private function checkBatch( $db, array $rows, bool $apply ): int {
		$found = [];
		foreach ( $db->select(
			'interlanguage_title_keys',
			[ 'itk_lang', 'itk_title', 'itk_key' ],
			[
				'itk_lang' => array_values( array_unique( array_column( $rows, 'itk_lang' ) ) ),
				'itk_title' => array_values( array_unique( array_column( $rows, 'itk_title' ) ) ),
			],
			__METHOD__
		) as $row ) {
			$found[serialize( [ 'itk_lang' => $row->itk_lang, 'itk_title' => $row->itk_title, 'itk_key' => $row->itk_key ] )] = true;
		}
		$missing = array_values( array_filter( $rows, static fn ( $row ) => !isset( $found[serialize( $row )] ) ) );
		if ( $apply && $missing ) {
			$db->insert( 'interlanguage_title_keys', $missing, __METHOD__, [ 'IGNORE' ] );
		}
		return count( $missing );
	}
}

$maintClass = InterlanguageBuildTitleKeys::class;
require_once RUN_MAINTENANCE_IF_MAIN;
