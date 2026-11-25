<?php

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\LinksUpdate\LinksDeletionUpdate;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;

class InterlanguageCentralExtension {

	/**
	 * Register parser function
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook(
			'languagelink',
			[ self::class, 'languagelink' ],
			Parser::SFH_NO_HASH
		);
		return true;
	}

	/**
	 * Parser function {{#languagelink:lang|title}}
	 */
	public static function languagelink( Parser $parser, $lang, $title = "" ) {
		if ( strlen( $lang ) && strlen( $title ) ) {
			return "[[$lang:$title]][[:$lang:$title]]";
		}
		return "";
	}

	/** @var WeakMap<object,InterlanguageCentralExtensionPurgeJob>|null */
	private static ?WeakMap $pendingPurges = null;

	/**
	 * Capture the effective group before the core updates its langlinks.
	 * MediaWiki 1.43 ParserOutput returns a list of "language:title" strings.
	 * Keep the first link for a language, like core's LangLinksTable.
	 */
	public static function onLinksUpdate( $linksUpdate ) {
		global $wgLanguageCode;

		$title = $linksUpdate->getTitle();
		$parserOutput = $linksUpdate->getParserOutput();
		if ( !$title instanceof Title || !$parserOutput instanceof ParserOutput ) {
			return true;
		}

		self::$pendingPurges ??= new WeakMap();
		unset( self::$pendingPurges[$linksUpdate] );

		$db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$pageId = (int)$linksUpdate->getPageId();
		if ( !$pageId ) {
			return true;
		}
		$oldNative = self::readNative( $db, $pageId );
		unset( $oldNative[$wgLanguageCode] );
		if ( $linksUpdate instanceof LinksDeletionUpdate ) {
			// Core supplies the deleted page ID even if its title has been
			// reused. Do not remove supplemental mappings after an undelete
			// that has already restored the same ID.
			if ( $db->selectRow( 'page', [ 'page_id' ], [ 'page_id' => $pageId ], __METHOD__ ) ) {
				return true;
			}
			$supplemental = self::readSupplemental( $db, $pageId );
			$db->delete( 'interlanguage_links', [ 'ill_from' => $pageId ], __METHOD__ );
			$affected = $oldNative + $supplemental;
			if ( $affected ) {
				self::$pendingPurges[$linksUpdate] = new InterlanguageCentralExtensionPurgeJob(
					$title, [ 'ill' => $affected ]
				);
			}
			return true;
		}
		$newNative = [];
		foreach ( $parserOutput->getLanguageLinks() as $link ) {
			if ( !is_string( $link ) || !str_contains( $link, ':' ) ) {
				continue;
			}
			[ $lang, $target ] = explode( ':', $link, 2 );
			if ( $lang !== '' && $target !== '' && $lang !== $wgLanguageCode ) {
				$newNative[$lang] ??= [ $target => true ];
			}
		}
		ksort( $oldNative, SORT_STRING );
		ksort( $newNative, SORT_STRING );
		if ( $oldNative === $newNative ) {
			return true;
		}

		// All peers, including supplemental ones, may have gained or lost
		// a translation. Native links take precedence in both groups.
		$supplemental = self::readSupplemental( $db, $pageId );
		$before = $oldNative + $supplemental;
		$after = $newNative + $supplemental;
		ksort( $before, SORT_STRING );
		ksort( $after, SORT_STRING );
		if ( $before === $after ) {
			return true;
		}

		$affected = $before;
		foreach ( $after as $lang => $targets ) {
			$affected[$lang] = ( $affected[$lang] ?? [] ) + $targets;
		}
		self::$pendingPurges[$linksUpdate] = new InterlanguageCentralExtensionPurgeJob(
			$title, [ 'ill' => $affected ]
		);
		return true;
	}

	/** Only enqueue once core has committed the link-table update. */
	public static function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		if ( self::$pendingPurges !== null && isset( self::$pendingPurges[$linksUpdate] ) ) {
			$job = self::$pendingPurges[$linksUpdate];
			unset( self::$pendingPurges[$linksUpdate] );
			MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
		}
		return true;
	}

	/**
	 * Get language links stored in DB
	 */
	public static function getILL( int $db, Title $title ) {

		$dbr = $db === DB_PRIMARY
			? MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase()
			: MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

		return self::readNative( $dbr, (int)$title->getArticleID() );
	}

	private static function readNative( $dbr, int $pageId ): array {
		$res = $dbr->select(
			'langlinks',
			[ 'll_lang', 'll_title' ],
			[ 'll_from' => $pageId ],
			__METHOD__
		);

		$a = [];

		foreach ( $res as $row ) {
			if ( !isset( $a[$row->ll_lang] ) ) {
				$a[$row->ll_lang] = [];
			}
			$a[$row->ll_lang][$row->ll_title] = true;
		}

		return $a;
	}

	private static function readSupplemental( $db, int $pageId ): array {
		global $wgLanguageCode;
		$links = [];
		foreach ( $db->select( 'interlanguage_links', [ 'ill_lang', 'ill_title' ],
			[ 'ill_from' => $pageId ], __METHOD__ ) as $row
		) {
			if ( $row->ill_lang !== $wgLanguageCode ) {
				$links[$row->ill_lang][$row->ill_title] = true;
			}
		}
		return $links;
	}

	/** The page ID survives a move, but the peers' stored French title does not. */
	public static function onPageMoveComplete( $old, $new, $user, $pageId, $redirId, $reason, $revision ): void {
		global $wgLanguageCode;
		$db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		if ( !$db->selectRow( 'page', [ 'page_id' ], [
			'page_id' => (int)$pageId,
			'page_namespace' => $new->getNamespace(),
			'page_title' => $new->getDBkey(),
		], __METHOD__ ) ) {
			return;
		}
		$affected = self::readNative( $db, (int)$pageId ) + self::readSupplemental( $db, (int)$pageId );
		unset( $affected[$wgLanguageCode] );
		if ( !$affected ) {
			return;
		}
		$job = new InterlanguageCentralExtensionPurgeJob( Title::castFromLinkTarget( $new ), [ 'ill' => $affected ] );
		$db->onTransactionCommitOrIdle( static function () use ( $job ) {
			DeferredUpdates::addCallableUpdate( static function () use ( $job ) {
				MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
			} );
		}, __METHOD__ );
	}

	/**
	 * Compare arrays
	 */
	public static function arrayCompareKeys( $a, $b ) {
		return count( array_diff_key( $a, $b ) )
			? 1
			: ( count( array_diff_key( $b, $a ) ) ? -1 : 0 );
	}

	/**
	 * Inject central interlanguage links
	 */
	public static function onLanguageLinks( Title $title, &$links, &$linkFlags ) {
		global $wgLanguageCode;

		$pageId = $title->getArticleID();
		if ( !$pageId ) {
			return true;
		}

		$a = [];

		foreach ( $links as $l ) {
			[ $lang, $titleText ] = explode( ':', $l, 2 );
			$a[$lang] = $titleText;
		}

		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

		$conds = [ 'ill_from' => $pageId ];

		if ( $a ) {
			$conds[] = 'ill_lang NOT IN (' . $dbr->makeList( array_keys( $a ) ) . ')';
		}

		$res = $dbr->select(
			'interlanguage_links',
			[ 'ill_lang', 'ill_title' ],
			$conds,
			__METHOD__
		);

		foreach ( $res as $row ) {
			if ( $row->ill_lang === $wgLanguageCode || isset( $a[$row->ill_lang] ) ) {
				continue;
			}

			$a[$row->ill_lang] = true;
			$links[] = $row->ill_lang . ':' . $row->ill_title;
		}

		return true;
	}
}
