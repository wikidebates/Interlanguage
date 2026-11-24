<?php
require_once dirname( __DIR__ ) . '/InterlanguageExtension.php';

class EffectiveMappingsTestDatabase {
	public array $queries = [];

	public function select( $table, $fields, $conditions, $caller ): array {
		$this->queries[] = $table;
		if ( $conditions !== [ ( $table === 'interlanguage_links' ? 'ill_from' : 'll_from' ) => 42 ] ) {
			throw new RuntimeException( 'Wrong source ID' );
		}
		if ( $table === [ 'langlinks' ] ) {
			return [
				(object)[ 'll_lang' => 'en', 'll_title' => 'Explicit:Title' ],
				(object)[ 'll_lang' => 'fr', 'll_title' => 'Wrong French title' ],
			];
		}
		if ( $table === 'interlanguage_links' ) {
			return [
				(object)[ 'ill_lang' => 'en', 'ill_title' => 'Obsolete fallback' ],
				(object)[ 'ill_lang' => 'de', 'ill_title' => 'Supplemental:Title' ],
				(object)[ 'ill_lang' => 'fr', 'ill_title' => 'Obsolete French title' ],
			];
		}
		throw new RuntimeException( 'Unexpected table' );
	}
}

$wgInterlanguageExtensionInterwiki = 'fr:';
$db = new EffectiveMappingsTestDatabase();
$extension = new InterlanguageExtension();

$links = $extension->readLinksFromCentralDB( $db, 42 );
if ( $links !== [
	[ 'lang' => 'en', '*' => 'Explicit:Title' ],
	[ 'lang' => 'de', '*' => 'Supplemental:Title' ],
] ) {
	throw new RuntimeException( 'Expected native precedence, supplemental links and no central self mapping' );
}
if ( $db->queries !== [ [ 'langlinks' ], 'interlanguage_links' ] ) {
	throw new RuntimeException( 'Unexpected central lookup count' );
}

$db->queries = [];
$extension->readLinksFromDB( $db, 42 );
if ( $db->queries !== [ [ 'langlinks' ] ] ) {
	throw new RuntimeException( 'Local fallback must not read the central supplemental table' );
}

echo "Interlanguage effective mappings smoke tests: OK\n";
