<?php
require_once dirname( __DIR__ ) . '/InterlanguageExtension.php';

class EffectiveMappingsSelect {
	public array $fields;
	public string $table;
	public array $conditions;
	public function select( array $fields ): self {
		$this->fields = $fields;
		return $this;
	}
	public function from( string $table ): self {
		$this->table = $table;
		return $this;
	}
	public function where( array $conditions ): self {
		$this->conditions = $conditions;
		return $this;
	}
}

class EffectiveMappingsUnion {
	public array $branches = [];
	private bool $all = false;
	public function __construct( private EffectiveMappingsTestDatabase $db ) {}
	public function add( EffectiveMappingsSelect $query ): self {
		$this->branches[] = $query;
		return $this;
	}
	public function all(): self {
		$this->all = true;
		return $this;
	}
	public function caller( string $caller ): self {
		return $this;
	}
	public function fetchResultSet(): array {
		if ( !$this->all || count( $this->branches ) !== 2 ) {
			throw new RuntimeException( 'Expected two UNION ALL branches' );
		}
		[ $native, $fallback ] = $this->branches;
		if ( $native->table !== 'langlinks' || $native->conditions !== [ 'll_from' => 42 ] ||
			$native->fields !== [ 'language' => 'll_lang', 'target' => 'll_title', 'is_native' => '1' ] ||
			$fallback->table !== 'interlanguage_links' || $fallback->conditions !== [ 'ill_from' => 42 ] ||
			$fallback->fields !== [ 'language' => 'ill_lang', 'target' => 'ill_title', 'is_native' => '0' ]
		) {
			throw new RuntimeException( 'Wrong indexed mapping query' );
		}
		$this->db->queries[] = 'union';
		// Deliberately return fallback first: precedence cannot depend on SQL order.
		return [
			(object)[ 'language' => 'en', 'target' => 'Obsolete fallback', 'is_native' => '0' ],
			(object)[ 'language' => 'de', 'target' => 'Supplemental:Title', 'is_native' => '0' ],
			(object)[ 'language' => 'fr', 'target' => 'Obsolete French title', 'is_native' => '0' ],
			(object)[ 'language' => 'en', 'target' => 'Explicit:Title', 'is_native' => '1' ],
			(object)[ 'language' => 'fr', 'target' => 'Wrong French title', 'is_native' => '1' ],
		];
	}
}

class EffectiveMappingsTestDatabase {
	public array $queries = [];
	public function newSelectQueryBuilder(): EffectiveMappingsSelect {
		return new EffectiveMappingsSelect();
	}
	public function newUnionQueryBuilder(): EffectiveMappingsUnion {
		return new EffectiveMappingsUnion( $this );
	}
	public function select( $table, $fields, $conditions, $caller ): array {
		$this->queries[] = $table;
		if ( $table !== [ 'langlinks' ] || $conditions !== [ 'll_from' => 42 ] ) {
			throw new RuntimeException( 'Unexpected local query' );
		}
		return [ (object)[ 'll_lang' => 'en', 'll_title' => 'Local title' ] ];
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
if ( $db->queries !== [ 'union' ] ) {
	throw new RuntimeException( 'Expected one SQL round trip' );
}
$db->queries = [];
$extension->readLinksFromDB( $db, 42 );
if ( $db->queries !== [ [ 'langlinks' ] ] ) {
	throw new RuntimeException( 'Local fallback must not read the central supplemental table' );
}

echo "Interlanguage effective mappings smoke tests: OK\n";
