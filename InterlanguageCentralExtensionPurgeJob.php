<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/**
 * MediaWiki InterlanguageCentral extension
 * InterlanguageCentralExtensionPurgeJob class
 *
 * Copyright © 2010-2011 Nikola Smolenski <smolensk@eunet.rs>
 * @version 1.2
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * For more information,
 * @see https://www.mediawiki.org/wiki/Extension:Interlanguage
 */

// Based on https://www.mediawiki.org/wiki/Manual:Job_queue/For_developers
class InterlanguageCentralExtensionPurgeJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'purgeDependentWikis', $title, $params );
	}

	/**
	 * Execute the job
	 *
	 * @return bool
	 */
	public function run() {
		// Only dependent pages persist the computed translation group.
		// The central wiki reads the mapping tables directly; ordinary purge
		// is enough there. MediaWiki itself authorizes both actions.
		$central = rtrim( (string)( $GLOBALS['wgInterlanguageExtensionInterwiki'] ?? '' ), ':' );
		$central = $central !== '' ? $central : (string)$GLOBALS['wgLanguageCode'];

		$ill = $this->params['ill'];
		$mwServices = MediaWikiServices::getInstance();
		$logger = LoggerFactory::getInstance( 'Interlanguage' );

		foreach ( $ill as $lang => $pages ) {
			$iw = $mwServices->getInterwikiLookup()->fetch( $lang );
			if ( !$iw || !$iw->getAPI() ) {
				continue;
			}
			$apiUrl = $iw->getAPI();
			$force = $lang !== $central;

			// Core limits ordinary API title lists to 50. Batches also avoid
			// unbounded requests if old queued jobs contain many targets.
			foreach ( array_chunk( array_keys( $pages ), 50 ) as $titles ) {
				$params = [
					'action' => 'purge',
					'format' => 'json',
					'titles' => implode( '|', $titles ),
				];
				if ( $force ) {
					// Core checks linkpurge separately from purge. A denied
					// link update is diagnosed below, not retried or bypassed.
					$params['forcelinkupdate'] = 1;
					$params['redirects'] = 1;
				}
				$url = $apiUrl . ( str_contains( $apiUrl, '?' ) ? '&' : '?' ) . wfArrayToCGI( $params );
				$response = $mwServices->getHttpRequestFactory()->post( $url, [], __METHOD__ );
				$result = is_string( $response ) ? json_decode( $response, true ) : null;

				if ( !is_array( $result ) || !is_array( $result['purge'] ?? null ) ||
					!$result['purge'] || isset( $result['error'] ) || isset( $result['continue'] )
				) {
					$logger->warning(
						'Remote purge failed or returned an incomplete response for interwiki {language}',
						[ 'language' => $lang ]
					);
					continue;
				}
				foreach ( $result['purge'] as $page ) {
					if ( !is_array( $page ) ) {
						$logger->warning(
							'Remote purge returned an invalid page result for interwiki {language}',
							[ 'language' => $lang ]
						);
						continue;
					}
					// A deleted or nonexistent old peer cannot be refreshed.
					if ( isset( $page['missing'] ) || isset( $page['invalid'] ) ) {
						continue;
					}
					if ( !isset( $page['purged'] ) || ( $force && !isset( $page['linkupdate'] ) ) ) {
						$logger->warning(
							'Remote purge or requested link update was not acknowledged for interwiki {language}, page {title}',
							[ 'language' => $lang, 'title' => $page['title'] ?? '' ]
						);
					}
				}
			}
			// Preserve non-retrying jobs: permission errors must not cause retry storms.
		}

		return true;
	}

	//TODO: custom insert with duplicate merging
}
