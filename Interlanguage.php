<?php
/**
 * MediaWiki Interlanguage extension
 *
 * Copyright © 2008-2011 Nikola Smolenski <smolensk@eunet.rs> and others
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

$wgInterlanguageExtensionDB = false;
$wgInterlanguageExtensionApiUrl = false;
$wgInterlanguageExtensionInterwiki = "";
$wgInterlanguageExtensionSort = 'none';
$wgInterlanguageExtensionSortPrepend = false;
$wgInterlanguageExtensionNotifyFallbackChanges = false;

$wgExtensionCredits['parserhook'][] = array(
	'path'			=> __FILE__,
	'name'			=> 'Interlanguage',
	'author'		=> 'Nikola Smolenski',
	'url'			=> 'https://www.mediawiki.org/wiki/Extension:Interlanguage',
	'version'		=> '1.6.1',
	'descriptionmsg'	=> 'interlanguage-desc',
);

$wgMessagesDirs['Interlanguage'] = __DIR__ . '/i18n/interlanguage';
$wgExtensionMessagesFiles['InterlanguageMagic'] = dirname(__FILE__) . '/Interlanguage.i18n.magic.php';
$wgAutoloadClasses['InterlanguageExtension'] = dirname(__FILE__) . '/InterlanguageExtension.php';
$wgAutoloadClasses['InterlanguageTitleLookup'] = __DIR__ . '/InterlanguageTitleLookup.php';
$wgAutoloadClasses['InterlanguageDependentLifecycle'] = __DIR__ . '/InterlanguageDependentLifecycle.php';
$wgAutoloadLocalClasses['ApiQueryLangLinks'] = dirname(__FILE__) . '/api/ApiQueryLangLinks.php';
$wgAutoloadClasses['InterlanguageEffectiveLangLinks'] = __DIR__ . '/api/InterlanguageEffectiveLangLinks.php';
$wgAutoloadClasses['InterlanguageCentralExtensionPurgeJob'] = __DIR__ . '/InterlanguageCentralExtensionPurgeJob.php';
$wgJobClasses['purgeDependentWikis'] = 'InterlanguageCentralExtensionPurgeJob';
$wgHooks['ParserFirstCallInit'][] = 'wfInterlanguageExtension';
$wgResourceModules['ext.Interlanguage'] = array(
	'styles' => 'modules/interlanguage.css',
	'localBasePath' => dirname( __FILE__ ),
	'remoteExtPath' => 'Interlanguage',
);

/**
 * @param $parser Parser
 * @return bool
 */
function wfInterlanguageExtension( $parser ) {
	global $wgInterlanguageExtension;

	if ( !isset( $wgInterlanguageExtension ) ) {
		$wgInterlanguageExtension = new InterlanguageExtension();
		$hookContainer = \MediaWiki\MediaWikiServices::getInstance()->getHookContainer();
		$hookContainer->register( 'OutputPageParserOutput', $wgInterlanguageExtension );
		$hookContainer->register( 'EditPage::showEditForm:fields', [ $wgInterlanguageExtension, 'pageLinks' ] );
		$hookContainer->register( 'SkinTemplateOutputPageBeforeExec', [ $wgInterlanguageExtension, 'onSkinTemplateOutputPageBeforeExec' ] );
		$hookContainer->register( 'ContentAlterParserOutput', $wgInterlanguageExtension );
		$hookContainer->register( 'OutputPageParserOutput', [ $wgInterlanguageExtension, 'onWikirougeOutputPageParserOutput' ] );
	}

	// Each parser instance must receive the function, not just the first one.
	$parser->setFunctionHook( 'interlanguage', [ $wgInterlanguageExtension, 'interlanguage' ], Parser::SFH_NO_HASH );
	return true;
}

$wgHooks['PageDeleteComplete'][] = 'InterlanguageDependentLifecycle::onPageDeleteComplete';
$wgHooks['PageMoveComplete'][] = 'InterlanguageDependentLifecycle::onPageMoveComplete';
