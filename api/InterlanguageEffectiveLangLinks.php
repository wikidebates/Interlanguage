<?php

declare( strict_types=1 );

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryLangLinks;
use MediaWiki\Api\ApiResult;
use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;

/**
 * Opt-in, read-only langlinks API for the centralized farm.
 *
 * Central: native links and supplemental mappings, native precedence.
 * Dependent: native local links and the persisted parser-produced mappings
 * already used by the frontend. No parse, central write or remote request.
 * Deliberately separate from the historical global ApiQueryLangLinks class.
 */
class InterlanguageEffectiveLangLinks extends ApiQueryLangLinks {
	private LanguageNameUtils $names;
	private UrlUtils $urls;

	public function __construct(
		ApiQuery $query, string $moduleName, LanguageNameUtils $names,
		Language $contentLanguage, UrlUtils $urls
	) {
		parent::__construct( $query, $moduleName, $names, $contentLanguage, $urls );
		$this->names = $names;
		$this->urls = $urls;
	}

	public function execute() {
		global $wgInterlanguageExtensionInterwiki;

		$pages = $this->getPageSet()->getGoodPages();
		if ( $pages === [] ) {
			return;
		}
		$params = $this->extractRequestParams();
		$prop = array_fill_keys( (array)$params['prop'], true );
		if ( isset( $params['title'] ) && !isset( $params['lang'] ) ) {
			$this->dieWithError(
				[
					'apierror-invalidparammix-mustusewith',
					$this->encodeParamName( 'title' ),
					$this->encodeParamName( 'lang' ),
				],
				'invalidparammix'
			);
		}
		$this->requireMaxOneParameter( $params, 'url', 'prop' );
		if ( $params['url'] ) {
			$prop = [ 'url' => true ];
		}

		$local = $this->getConfig()->get( MainConfigNames::LanguageCode );
		$dependent = (string)$wgInterlanguageExtensionInterwiki !== '';
		$db = $this->getDB();
		$ids = array_keys( $pages );
		$links = [];
		// Keep each entry attached to its requested local page ID.
		foreach ( $db->newSelectQueryBuilder()
			->select( [ 'll_from', 'll_lang', 'll_title' ] )
			->from( 'langlinks' )
			->where( [ 'll_from' => $ids ] )
			->caller( __METHOD__ )
			->fetchResultSet() as $row
		) {
			if ( $row->ll_lang !== $local ) {
				$links[(int)$row->ll_from][$row->ll_lang] = $row->ll_title;
			}
		}
		if ( $dependent ) {
			foreach ( $db->newSelectQueryBuilder()
				->select( [ 'pp_page', 'pp_value' ] )
				->from( 'page_props' )
				->where( [ 'pp_page' => $ids, 'pp_propname' => 'wikirouge-langlinks' ] )
				->caller( __METHOD__ )
				->fetchResultSet() as $row
			) {
				$stored = @unserialize( $row->pp_value, [ 'allowed_classes' => false ] );
				if ( !is_array( $stored ) ) {
					continue;
				}
				$extra = [];
				$conflicts = [];
				foreach ( $stored as $link ) {
					if ( !is_array( $link ) || !is_string( $link['lang'] ?? null ) ||
						!is_string( $link['*'] ?? null ) ||
						$link['lang'] === '' || $link['*'] === '' || $link['lang'] === $local
					) {
						continue;
					}
					$code = $link['lang'];
					// Parser properties may contain DB keys (notably the central
					// page's title). Expose the equivalent API title in text form.
					$target = str_replace( '_', ' ', $link['*'] );
					if ( isset( $extra[$code] ) && $extra[$code] !== $target ) {
						$conflicts[$code] = true;
					}
					$extra[$code] = $target;
				}
				$id = (int)$row->pp_page;
				$links[$id] = ( $links[$id] ?? [] ) + array_diff_key( $extra, $conflicts );
			}
		} else {
			foreach ( $db->newSelectQueryBuilder()
				->select( [ 'ill_from', 'ill_lang', 'ill_title' ] )
				->from( 'interlanguage_links' )
				->where( [ 'ill_from' => $ids ] )
				->caller( __METHOD__ )
				->fetchResultSet() as $row
			) {
				$id = (int)$row->ill_from;
				if ( $row->ill_lang !== $local && !isset( $links[$id][$row->ill_lang] ) ) {
					$links[$id][$row->ill_lang] = $row->ill_title;
				}
			}
		}

		$descending = $params['dir'] === 'descending';
		$continuation = $params['continue'] !== null
			? $this->parseContinueParamOrDie( $params['continue'], [ 'int', 'string' ] )
			: null;
		$languageMap = $this->getConfig()->get( MainConfigNames::InterlanguageLinkCodeMap );
		ksort( $links, SORT_NUMERIC );
		if ( $descending ) {
			$links = array_reverse( $links, true );
		}
		$count = 0;
		foreach ( $links as $id => $byLanguage ) {
			ksort( $byLanguage, SORT_STRING );
			if ( $descending ) {
				$byLanguage = array_reverse( $byLanguage, true );
			}
			foreach ( $byLanguage as $code => $target ) {
				if ( ( isset( $params['lang'] ) && $params['lang'] !== $code ) ||
					( isset( $params['title'] ) && $params['title'] !== $target )
				) {
					continue;
				}
				if ( $continuation !== null ) {
					$order = ( $id <=> $continuation[0] ) ?: strcmp( $code, $continuation[1] );
					if ( $descending ? $order > 0 : $order < 0 ) {
						continue;
					}
				}
				if ( ++$count > $params['limit'] ) {
					$this->setContinueEnumParameter( 'continue', "$id|$code" );
					return;
				}
				$displayCode = $languageMap[$code] ?? $code;
				$entry = [ 'lang' => $displayCode ];
				if ( isset( $prop['url'] ) ) {
					$title = Title::newFromText( "$code:$target" );
					if ( $title ) {
						$entry['url'] = (string)$this->urls->expand( $title->getFullURL(), PROTO_CURRENT );
					}
				}
				if ( isset( $prop['langname'] ) ) {
					$entry['langname'] = $this->names->getLanguageName( $displayCode, $params['inlanguagecode'] );
				}
				if ( isset( $prop['autonym'] ) ) {
					$entry['autonym'] = $this->names->getLanguageName( $displayCode );
				}
				ApiResult::setContentValue( $entry, 'title', $target );
				if ( !$this->addPageSubItem( $id, $entry ) ) {
					$this->setContinueEnumParameter( 'continue', "$id|$code" );
					return;
				}
			}
		}
	}
}
