<?php

declare( strict_types=1 );

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Sanitizer;
use Wikimedia\IPUtils;

/**
 * An auxiliary dictionary of raw target spellings, not a mapping table.
 *
 * The coarse keys deliberately do not interpret remote namespace names.
 * The receiving wiki must verify every candidate with its own title parser.
 * Joining the live mapping tables means obsolete dictionary rows are harmless.
 */
final class InterlanguageTitleKeys {
	private const MAX_CANDIDATES = 256;
	private const VERSION = "v1\0";

	/**
	 * The same whitespace and bidi cleanup as MediaWiki 1.43's title codec.
	 * This is only a candidate key, never an authoritative title parser.
	 */
	private static function normalizedText( string $raw ): ?string {
		if ( !mb_check_encoding( $raw, 'UTF-8' ) ) {
			return null;
		}
		$text = Sanitizer::decodeCharReferencesAndNormalize( $raw );
		$text = preg_replace( '/[\x{200E}\x{200F}\x{202A}-\x{202E}]+/u', '', $text );
		if ( $text === null ) {
			return null;
		}
		$text = preg_replace( '/[ _\xA0\x{1680}\x{180E}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', '_', $text );
		if ( $text === null ) {
			return null;
		}
		$text = trim( explode( '#', $text, 2 )[0], '_' );
		return $text !== '' ? $text : null;
	}

	private static function digest( string $kind, string $value ): string {
		return hash( 'sha256', self::VERSION . $kind . "\0" . $value );
	}

	/**
	 * Use the final colon-separated segment for candidate discovery only.
	 * Discarding its first codepoint avoids any dependency on remote namespace
	 * aliases, case-sensitive namespaces or first-letter capitalization rules.
	 */
	private static function tail( string $text ): string {
		$pos = strrpos( $text, ':' );
		return trim( $pos === false ? $text : substr( $text, $pos + 1 ), '_' );
	}

	/**
	 * User and user-talk titles also undergo MediaWiki's IP normalization.
	 * Include canonical IP keys for any possible suffix, without assuming a
	 * namespace or accepting an IP-looking title as a verified user page.
	 */
	private static function ipKeys( string $text ): array {
		$keys = [];
		$offset = 0;
		do {
			$part = trim( substr( $text, $offset ), '_' );
			if ( strlen( $part ) <= 64 && strpbrk( $part, '.:' ) !== false &&
				IPUtils::isIPAddress( $part )
			) {
				$keys[] = self::digest( 'ip', IPUtils::sanitizeIP( $part ) );
			}
			$pos = strpos( $text, ':', $offset );
			$offset = $pos === false ? 0 : $pos + 1;
		} while ( $offset > 0 );
		return $keys;
	}

	/** @return string[] Deterministic keys for a stored raw target, independent of the sending wiki's language. */
	public static function storedKeys( string $raw ): array {
		$text = self::normalizedText( $raw );
		if ( $text === null ) {
			return [];
		}
		return array_values( array_unique( [
			self::digest( 'tail', mb_substr( self::tail( $text ), 1, null, 'UTF-8' ) ),
			...self::ipKeys( $text ),
		] ) );
	}

	/**
	 * Unicode full titlecase can expand a single character into up to three
	 * codepoints. Local custom ucfirst overrides can expand further (or remove
	 * it). Look up those additional suffixes; the local parser decides identity.
	 *
	 * @param array<string,string> $overrides Local OverrideUcfirstCharacters
	 * @return string[]
	 */
	public static function lookupKeys( string $title, array $overrides = [] ): array {
		$text = self::normalizedText( $title );
		if ( $text === null ) {
			return [];
		}
		$tail = self::tail( $text );
		$offsets = [ 1, 2, 3 ];
		foreach ( $overrides as $replacement ) {
			if ( is_string( $replacement ) && str_starts_with( $tail, $replacement ) ) {
				$offsets[] = mb_strlen( $replacement, 'UTF-8' );
			}
		}
		$keys = self::ipKeys( $text );
		foreach ( array_unique( $offsets ) as $offset ) {
			$keys[] = self::digest( 'tail', mb_substr( $tail, $offset, null, 'UTF-8' ) );
		}
		return array_values( array_unique( $keys ) );
	}

	/** Build dictionary rows for both native and supplemental mappings. */
	public static function rows( string $language, string $raw ): array {
		if ( $language === '' || strlen( $language ) > 35 || strlen( $raw ) > 255 ) {
			return [];
		}
		$rows = [];
		foreach ( self::storedKeys( $raw ) as $key ) {
			$rows[] = [ 'itk_lang' => $language, 'itk_title' => $raw, 'itk_key' => $key ];
		}
		return $rows;
	}

	public static function remember( $db, string $language, string $raw ): void {
		$rows = self::rows( $language, $raw );
		if ( $rows ) {
			$db->insert( 'interlanguage_title_keys', $rows, __METHOD__, [ 'IGNORE' ] );
		}
	}

	private static function identity( string $raw ): ?string {
		$title = Title::newFromText( $raw );
		if ( !$title || !$title->canExist() || $title->isExternal() || $title->getFragment() !== '' ) {
			return null;
		}
		return $title->getNamespace() . "\0" . $title->getDBkey();
	}

	/**
	 * One indexed query, followed by authoritative local verification.
	 *
	 * Supplemental rows shadowed by a native link on their own source/language
	 * are never candidates. Null means incomplete lookup (too many candidates
	 * or an invalid local identity), so callers must not infer any mapping.
	 *
	 * @param string[] $targets Local title and verified local redirect aliases
	 * @return array<int,object>|null
	 */
	public static function findCandidates( $db, array $targets, string $language, bool $native ): ?array {
		$overrides = MediaWikiServices::getInstance()->getMainConfig()->get( 'OverrideUcfirstCharacters' );
		$identities = [];
		$keys = [];
		foreach ( $targets as $target ) {
			$identity = self::identity( $target );
			if ( $identity === null ) {
				return null;
			}
			$identities[$identity] = true;
			foreach ( self::lookupKeys( $target, $overrides ) as $key ) {
				$keys[$key] = true;
			}
		}
		if ( !$keys || !$identities ) {
			return null;
		}

		$prefix = $native ? 'll' : 'ill';
		$tables = [
			'titlekey' => 'interlanguage_title_keys',
			'mapping' => $native ? 'langlinks' : 'interlanguage_links',
			'sourcepage' => 'page',
		];
		$joins = [
			'mapping' => [ 'JOIN', "mapping.{$prefix}_lang = titlekey.itk_lang AND mapping.{$prefix}_title = titlekey.itk_title" ],
			'sourcepage' => [ 'JOIN', "sourcepage.page_id = mapping.{$prefix}_from" ],
		];
		$conditions = [
			'titlekey.itk_lang' => $language,
			'titlekey.itk_key' => array_keys( $keys ),
		];
		if ( !$native ) {
			$tables['native'] = 'langlinks';
			$joins['native'] = [ 'LEFT JOIN', 'native.ll_from = mapping.ill_from AND native.ll_lang = mapping.ill_lang' ];
			$conditions['native.ll_from'] = null;
		}
		$rows = $db->select(
			$tables,
			[
				'page_id' => 'sourcepage.page_id',
				'page_namespace' => 'sourcepage.page_namespace',
				'page_title' => 'sourcepage.page_title',
				'page_is_redirect' => 'sourcepage.page_is_redirect',
				'target' => "mapping.{$prefix}_title",
			],
			$conditions,
			__METHOD__,
			[ 'DISTINCT', 'LIMIT' => self::MAX_CANDIDATES + 1 ],
			$joins
		);
		$candidates = [];
		$scanned = 0;
		foreach ( $rows as $row ) {
			if ( ++$scanned > self::MAX_CANDIDATES ) {
				return null;
			}
			$identity = self::identity( $row->target );
			if ( $identity !== null && isset( $identities[$identity] ) ) {
				$candidates[(int)$row->page_id] = $row;
				if ( count( $candidates ) > 1 ) {
					return $candidates;
				}
			}
		}
		return $candidates;
	}
}
