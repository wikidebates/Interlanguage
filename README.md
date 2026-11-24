# Interlanguage — Wikidebates fork

Centralized interlanguage links for a MediaWiki farm. One central wiki holds
language mappings used by dependent wikis, without duplicating translations on
every wiki. This fork targets MediaWiki 1.43 (tested with 1.43.12) and PHP 8.4.

## Origin and history

This is a customized port of the archived
[Wikimedia Interlanguage extension](https://www.mediawiki.org/wiki/Extension:Interlanguage)
([upstream Git repository](https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/Interlanguage/)),
not an official Wikimedia release. It preserves the historical copyright
notices of Nikola Smolenski and other contributors, including Yuri Astrakhan
in the customized API module and the translation authors in the i18n files.
The original Wikimedia history before the local import is not reconstructed.

The six commits of local history were extracted from
[the farm](https://github.com/wikidebates/wikidebates-mediawiki-farm) using
`git subtree split` on 24 November 2026:

- Source farm commit: `f0e2bf8a7fd3d903c7ac237fe76aa455de74e6bd`.
- Imported commit: `5fcef8e782aaba7718dcf983961cb8b1e4b210d3`.
- Verified identical Git tree: `3efd6932cb1eeb2193f3f447641c328ea3832c97`.

The first local snapshot is from July 2026. The historical `.gitreview` file
is retained for provenance, not as an instruction to submit changes upstream.
The repository migration does not change runtime code.

## Installation and modes

Install or pin this repository at `extensions/Interlanguage`.
Preserve your existing LocalSettings.php configuration in an established farm.

Central wiki:

```php
require_once "$IP/extensions/Interlanguage/InterlanguageCentral.php";
wfLoadExtension( 'Interlanguage' );
```

Dependent wiki:

```php
require_once "$IP/extensions/Interlanguage/Interlanguage.php";
$wgInterlanguageExtensionDB = 'central_wiki_db';
$wgInterlanguageExtensionInterwiki = 'en:';
```

The central language and database domain in this example are placeholders.
The dependent mode also supports the existing HTTP API configuration
(`$wgInterlanguageExtensionApiUrl`) instead of a foreign database.

Configure interwiki prefixes and URLs (and API endpoints for purges) for each
language. The central database must already have the `interlanguage_links`
table with the required `ill_from`, `ill_lang`, `ill_title` columns and unique
key on `(ill_from, ill_lang)`. This snapshot does **not** supply a schema updater
for this custom table; running MediaWiki update alone does not create it.
For a new deployment, provision and review its schema separately.
The farm migration does not require a database change.

On MediaWiki 1.43, the stock API langlinks module is namespaced. To explicitly
use the legacy customized module, the disposable integration fixture configures:

```php
$wgAutoloadLocalClasses['ApiQueryLangLinks'] = "$IP/extensions/Interlanguage/api/ApiQueryLangLinks.php";
$wgAPIPropModules['langlinks'] = 'ApiQueryLangLinks';
```

The old API module is retained for compatibility only. For this farm,
prefer the new uniquely named read-only module once the integration tests
and deployment audit have passed. It must be opted into explicitly, after
the central/dependent loader, on all six editions:

```php
$wgAPIPropModules['langlinks'] = [
	'class' => 'InterlanguageEffectiveLangLinks',
	'services' => [
		'LanguageNameUtils',
		'ContentLanguage',
		'UrlUtils',
	],
];
```

It uses native and supplemental mappings on the central wiki, and native
links plus persisted parser properties on dependent wikis. Native links take
precedence. Unlike the old module, it retains local page IDs across
multi-title requests, and applies filtering and continuation after merging.
It never renders or writes pages. Missing or stale parser properties must
still be addressed by the coordinated cache migration and offline audit.
Do not configure both overrides.

## Automatic same-title mappings and invalidation

A same-title fallback never overwrites a previously stored mapping. An
explicit native match (including an existing local redirect match) requires
no additional supplemental write. If the central group already has a
different translation in this edition, do not infer a competing relation
merely because a local page has the same title.

On dependent wikis, an opt-in setting enables invalidation of the central
page and the other existing peers when a new automatic mapping is committed:

```php
$wgInterlanguageExtensionNotifyFallbackChanges = true;
```

The job is enqueued on the dependent wiki after the database commit. Its
normal job runner must process purgeDependentWikis, and each relevant
interwiki prefix must have a working purge API endpoint. Verify those
conditions before activation. No remote request is made by the parsing
hook; unchanged mappings do not generate jobs. This preserves the existing
historical parse-time discovery of same-title mappings, not an editorial
proof of translation equivalence. The ordinary purge only invalidates
MediaWiki rendering caches; it does not itself guarantee that persisted
page properties or custom external HTML caches have been rebuilt. The
offline mapping audit remains necessary.

## Indexed normalized reverse lookup (optional, central SQL migration)

This mode recognizes legitimate target spellings without changing the raw
`langlinks` or `interlanguage_links` values. The central dictionary indexes
raw spellings by a coarse suffix key independent of namespace names.
It never decides whether a colon is a namespace separator: the dependent
wiki parses each live candidate with its own MediaWiki title parser and
compares namespace number and DB key. Native mappings take precedence over
supplemental mappings; ambiguous candidates and incomplete searches (more
than 256 SQL candidates) do not create an automatic mapping. A supplemental
row overridden by a native row for the same central source/language is
excluded. Existing forward links are not rewritten, even in a conflicting
group. No additional HTTP or foreign database access from the central wiki.

The table is append-only for spellings. Queries join the actual native or
supplemental mapping, so deleting a mapping immediately makes its old key
irrelevant. Central link updates and dependent fallback insertion remember
new spellings before publishing them. Direct SQL inserts or changes to
either mapping table bypass these hooks and must be followed by a verified
backfill before enabling or continuing to rely on normalized lookup.

Rollout on MySQL/MariaDB (disabled by default; no global cache rebuild):

1. Create the central-only table from `sql/interlanguage_title_keys.sql`
   (the central schema-update hook also registers it).
2. After loading the extension, set `$wgInterlanguageMaintainTitleKeys = true;`
   on the central wiki **and all dependent writers**. Leave
   `$wgInterlanguageUseTitleKeys` false.
3. From the MediaWiki root, on the central wiki only, run
   `php maintenance/run.php ./extensions/Interlanguage/maintenance/buildTitleKeys.php --wiki=fr --apply`
   and then repeat without `--apply` to verify zero missing keys. This
   offline operation reads mapping rows and only inserts auxiliary keys.
4. Enable `$wgInterlanguageUseTitleKeys = true;` on dependent wikis
   after successful verification. A reader also maintains keys for its
   own fallback inserts. The existing exact native lookup remains available
   when the reader setting is false.
5. Repeat the read-only coverage check after imports/direct SQL operations,
   changes to core title normalization, and before switching readers on again.
   If coverage is uncertain, disable the readers until it is restored.

The central reader account needs SELECT on the new table; both central and
dependent writer accounts need SELECT and INSERT there. No DELETE on the
dictionary is necessary for normal operation. The extension does not grant
SQL rights or alter the original mapping tables. The index changes candidate
discovery, not the stored API titles or the canonical URL configuration
used by WikiSEO.

## Targeted secondary-data refresh (optional)

Plain API purges invalidate rendering caches, but do not guarantee that
MediaWiki rewrites the persisted parser property. A separate, opt-in
configuration on the **sending** wiki can request core's non-recursive
secondary-data update for specific destination language codes:

```php
$wgInterlanguagePurgeLinkUpdateLanguages = [ 'en' ];
```

Set this after the central or dependent loader. The default is an empty list,
so existing installations keep ordinary purges. The opt-in request uses
`forcelinkupdate=1` and `redirects=1` (not the recursive flag). It
requires the receiving API to authorize **both** purge and linkpurge for
the actual request identity. MediaWiki 1.43 normally defines these as
implicit rights, so a default installation may already authorize the job's
anonymous request. If an installation deliberately declares linkpurge as
an explicit available right and restricts its permissions, the job does not
authenticate to the remote API by itself. Do not relax existing restrictions
to enable this option. Verify the target's actual authorization, both API
flags and the persisted page property before activation. A successful HTTP
response or plain purge is not proof of an updated property.

The job logs missing acknowledgements and incomplete responses, skips missing
or deleted peers, limits requests to batches of at most 50 titles and retains
the existing non-retrying behavior. No new HTTP request is made by a parsing
hook. Production authorization and targeted retry/reconciliation policy must
be handled separately; no global link-table or cache rebuild is implied.

## Redirect safety

Explicit central DB lookups follow up to 16 local redirects, using the real
destination namespace and DB key. Broken targets, interwiki redirects and
cycles return a missing-page result rather than an unrelated group.

For automatic dependent discovery, local whole-page redirect aliases are
searched backwards with indexed target lookups (up to 16 levels and 64 titles,
including the current page). Section redirects and interwiki redirects are
not whole-page aliases. An incomplete search or competing native source
pages prevents automatic discovery. Redirect pages cannot themselves create
same-title mappings, and central redirect sources are not automatically
treated as equivalent to their destinations. Ordinary pages add one indexed
local redirect lookup compared with the former exact-match fast path; no
HTTP requests or full-table scans are added. This change does not yet
normalize all stored native title forms or index supplemental reverse links.

## Architecture

- Central mode combines native language links and the central mapping table;
  link changes enqueue the existing remote-purge job.
- Dependent mode reads central mappings, retains existing fallback mapping
  writes during parsing, and adds language links through MediaWiki OutputPage.
- The custom API module reads native and supplemental mappings.
- Existing serialized parser properties, cache behavior, job semantics and
  parser function names are retained unchanged.
- No dependency on WikiSEO or Wikidebates is introduced.

See [README-MW143.txt](README-MW143.txt) for the historical adaptation notes.
Its original installation instructions are historical; use the registration
and schema caveats above for the current code.

## Tests

The autonomous CI runs PHP 8.4 syntax checks and all nine isolated
regression scripts:

```sh
find . -type f -name '*.php' -print0 | xargs -0 -n 1 php -l
for test in tests/*SmokeTest.php; do
	php "$test"
done
```

The farm separately tests real MediaWiki installations, both wiki modes,
cross-database mappings and HTTP API responses. Isolated tests are not a
substitute for live integration or validation of a specific production config.

## License

GPL-2.0-or-later; see [COPYING](COPYING). Original file-level attributions
and translation credits are preserved.
