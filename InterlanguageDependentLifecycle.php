<?php

declare( strict_types=1 );

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDatabase;

/**
 * Dependent-side page lifecycle. Native central langlinks are editorial data:
 * never rewrite or delete them here. Only touch exact, verified automatic rows.
 */
final class InterlanguageDependentLifecycle {
	public static function onPageDeleteComplete( $page, $deleter, $reason, $pageId, $revision, $logEntry, $count ): void {
		if ( !self::enabled() ) {
			return;
		}
		$title = Title::makeTitle( $page->getNamespace(), $page->getDBkey() );
		$db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		// Core may already have cleared its redirect row. The deleted
		// revision is the authoritative pre-delete source, including in
		// maintenance mode. Do not use suppressed revision content.
		$destination = $revision->getContent( SlotRecord::MAIN )?->getRedirectTarget();
		if ( $destination && ( $destination->isExternal() || $destination->getFragment() !== '' ||
			!$destination->canExist() )
		) {
			$destination = null;
		}
		self::afterCommit( $db, static function () use ( $title, $destination ) {
			self::reconcile( $title, null, 0, $destination );
		} );
	}

	public static function onPageMoveComplete( $old, $new, $user, $pageId, $redirId, $reason, $revision ): void {
		if ( !self::enabled() ) {
			return;
		}
		$old = Title::castFromLinkTarget( $old );
		$new = Title::castFromLinkTarget( $new );
		$db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		self::afterCommit( $db, static function () use ( $old, $new, $pageId ) {
			self::reconcile( $old, $new, (int)$pageId, null );
		} );
	}

	private static function enabled(): bool {
		return (bool)( $GLOBALS['wgInterlanguageExtensionDB'] ?? false ) &&
			(string)( $GLOBALS['wgLanguageCode'] ?? '' ) !== rtrim( (string)( $GLOBALS['wgInterlanguageExtensionInterwiki'] ?? '' ), ':' );
	}

	private static function afterCommit( $db, callable $callback ): void {
		$db->onTransactionCommitOrIdle( static function () use ( $callback ) {
			DeferredUpdates::addCallableUpdate( $callback );
		}, __METHOD__ );
	}

	private static function localRow( $db, Title $title ) {
		return $db->selectRow( 'page', [ 'page_id', 'page_is_redirect' ], [
			'page_namespace' => $title->getNamespace(), 'page_title' => $title->getDBkey(),
		], __METHOD__ );
	}

	private static function resolveLocal( $db, Title $title ): ?Title {
		$seen = [];
		for ( $depth = 0; $depth <= 16; $depth++ ) {
			$row = self::localRow( $db, $title );
			if ( !$row || isset( $seen[(int)$row->page_id] ) ) {
				return null;
			}
			if ( !(int)$row->page_is_redirect ) {
				return $title;
			}
			$seen[(int)$row->page_id] = true;
			$redirect = $db->selectRow( 'redirect', [ 'rd_namespace', 'rd_title', 'rd_interwiki', 'rd_fragment' ],
				[ 'rd_from' => (int)$row->page_id ], __METHOD__ );
			if ( !$redirect || (string)$redirect->rd_interwiki !== '' || (string)$redirect->rd_fragment !== '' ) {
				return null;
			}
			$title = Title::makeTitle( (int)$redirect->rd_namespace, $redirect->rd_title );
		}
		return null;
	}

	private static function refreshLocal( Title $title, int $pageId ): void {
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$row = self::localRow( $db, $title );
		if ( !$row || (int)$row->page_id !== $pageId || (int)$row->page_is_redirect ) {
			return;
		}
		$page = $services->getWikiPageFactory()->newFromTitle( $title );
		$page->doPurge();
		$page->updateParserCache( [ 'causeAction' => 'interlanguage-lifecycle' ] );
		$page->doSecondaryDataUpdates( [
			'recursive' => false, 'causeAction' => 'interlanguage-lifecycle',
			'defer' => DeferredUpdates::PRESEND, 'freshness' => wfTimestampNow(),
		] );
	}

	/**
	 * On a deletion, remove only supplemental rows which directly name a now
	 * absent local page. On a move without a redirect, a uniquely claimed
	 * automatic mapping may follow the same page ID, provided neither title
	 * is claimed by a competing effective group. Moves with a redirect keep
	 * the valid old spelling; native links always remain untouched.
	 */
	private static function reconcile( Title $old, ?Title $new, int $pageId, ?Title $formerDestination ): void {
		global $wgLanguageCode, $wgInterlanguageExtensionDB, $wgInterlanguageExtensionInterwiki;
		if ( !self::enabled() ) {
			return;
		}
		$services = MediaWikiServices::getInstance();
		$connections = $services->getConnectionProvider();
		$local = $connections->getPrimaryDatabase();
		$central = $connections->getPrimaryDatabase( $wgInterlanguageExtensionDB );
		$extension = new InterlanguageExtension();
		$oldRow = self::localRow( $local, $old );
		if ( $new !== null ) {
			$newRow = self::localRow( $local, $new );
			if ( !$newRow || (int)$newRow->page_id !== $pageId ) {
				return;
			}
		} elseif ( $oldRow ) {
			// The old title may have been restored or recreated after deletion
			// (including the redirect overwritten as part of a move).
			return;
		}
		$oldText = InterlanguageExtension::getCanonicalTitleText( $old );
		$oldAliases = $extension->getLocalRedirectTitles( $local, $old );
		$newAliases = $new ? $extension->getLocalRedirectTitles( $local, $new ) : [];
		$complete = $oldAliases !== null && $newAliases !== null;
		$targets = array_values( array_unique( array_merge(
			$oldAliases ?? [ $oldText ],
			$newAliases ?? [ InterlanguageExtension::getCanonicalTitleText( $new ) ]
		) ) );
		// Destructive cleanup and retargeting never rely on cached absence.
		$lookup = new InterlanguageReverseLookup( $central, $wgLanguageCode, true );
		$native = $lookup->findCandidates( $targets, true, true );
		$supplemental = $lookup->findCandidates( $targets, false, true );
		$direct = $lookup->findCandidates( [ $oldText ], false, true, true );
		if ( !$complete || $native === null || $supplemental === null || $direct === null ) {
			LoggerFactory::getInstance( 'Interlanguage' )->warning(
				'Incomplete page lifecycle lookup for {title}; automatic mapping changes skipped',
				[ 'title' => $oldText ]
			);
			$complete = false;
		}
		$groups = ( $native ?? [] ) + ( $supplemental ?? [] ) + ( $direct ?? [] );
		// A virtual file/message may still be available even when the local
		// page row is gone. Leave its mapping for the read-only audit.
		$virtual = in_array( $old->getNamespace(), [ NS_FILE, NS_MEDIAWIKI ], true );

		if ( $complete && !$oldRow && !$virtual && $direct ) {
			if ( $new === null ) {
				foreach ( $direct as $id => $row ) {
					$central->delete( 'interlanguage_links', [
						'ill_from' => $id, 'ill_lang' => $wgLanguageCode, 'ill_title' => $row->target,
					], __METHOD__ );
				}
			} elseif ( !$native &&
				count( $supplemental ) === 1 && count( $direct ) === 1 &&
				!(int)$newRow->page_is_redirect
			) {
				$id = array_key_first( $direct );
				$row = $direct[$id];
				// Compare the full raw row, and only retarget the one effective
				// supplemental group already proven by the old local identity.
				if ( isset( $supplemental[$id] ) && !(int)$row->page_is_redirect ) {
					$newText = InterlanguageExtension::getCanonicalTitleText( $new );
					$central->doAtomicSection( __METHOD__, static function () use ( $central, $id, $row, $wgLanguageCode, $newText ) {
						$central->delete( 'interlanguage_links', [
							'ill_from' => $id, 'ill_lang' => $wgLanguageCode, 'ill_title' => $row->target,
						], __METHOD__ );
						if ( $central->affectedRows() === 1 ) {
							// No UPDATE grant required; the atomic delete/insert
							// rolls back if a concurrent insert wins the key.
							$central->insert( 'interlanguage_links', [
								'ill_from' => $id, 'ill_lang' => $wgLanguageCode, 'ill_title' => $newText,
							], __METHOD__ );
						}
					}, IDatabase::ATOMIC_CANCELABLE );
				}
			}
			InterlanguageReverseLookup::invalidateAfterCommit( $central, $wgLanguageCode );
		}

		$affected = [];
		$centralLang = rtrim( $wgInterlanguageExtensionInterwiki, ':' );
		foreach ( $groups as $id => $row ) {
			$centralTitle = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
			$affected[$centralLang][InterlanguageExtension::getCanonicalTitleText( $centralTitle )] = true;
			foreach ( $extension->readLinksFromCentralDB( $central, $id ) as $link ) {
				if ( $link['lang'] !== $wgLanguageCode && $link['lang'] !== $centralLang ) {
					$affected[$link['lang']][$link['*']] = true;
				}
			}
		}
		$refresh = !$groups ? null : ( $new ? self::resolveLocal( $local, $new ) :
			( $formerDestination ? self::resolveLocal( $local, $formerDestination ) : null ) );
		$refreshRow = $refresh ? self::localRow( $local, $refresh ) : null;
		if ( !$groups && !$refreshRow ) {
			return;
		}
		self::afterCommit( $central, static function () use ( $old, $affected, $refresh, $refreshRow ) {
			if ( $affected && ( $GLOBALS['wgInterlanguageExtensionNotifyFallbackChanges'] ?? false ) ) {
				MediaWikiServices::getInstance()->getJobQueueGroup()->push(
					new InterlanguageCentralExtensionPurgeJob( $old, [ 'ill' => $affected ] )
				);
			}
			if ( $refresh && $refreshRow ) {
				self::refreshLocal( $refresh, (int)$refreshRow->page_id );
			}
		} );
	}
}
