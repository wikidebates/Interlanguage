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

## Page lifecycle

Central moves keep mappings attached to the stable page ID and notify all
effective peers after commit. Core's LinksDeletionUpdate supplies the deleted
ID (not the possibly reused title); the extension removes its supplemental
rows and queues notifications alongside the native link cleanup.

Dependent moves and deletions use the primary local and central databases
after the local change commits. Only direct verified supplemental rows can
be deleted when a local page is gone; native langlinks are never changed.
A move with a redirect preserves the old valid spelling. A move without a
redirect can retarget a unique, non-shadowed supplemental row only when
no competing native or automatic claim is found by the normalized lookup; the old row is compared exactly and
deleted/inserted atomically, without requesting an additional UPDATE grant.
An incomplete lookup, competing claims, and deleted file or
MediaWiki message pages are not grounds for speculative remapping. The native
link of a move without a redirect (or deletion) may require editorial repair.

The affected central page and other peers are notified through the existing
purge queue when fallback notifications are enabled. A moved dependent page
and the surviving destination of a deleted redirect receive a targeted,
non-recursive local refresh after the central write commits. No new job type,
HTTP request during parsing, or cross-wiki transaction is introduced.
A title reused before the dependent lifecycle check suppresses destructive
cleanup. As local and central databases have separate transactions, this
is not a cross-database lock; audit discrepancies after simultaneous changes.
No automatic recovery of deleted supplemental links on undeletion is promised.

## Normalized reverse lookup (no auxiliary schema)

The only authoritative mapping tables are the existing langlinks and
interlanguage_links. For a dependent lookup, read the distinct raw title
spellings for the requested language through the existing (language, title)
index. The receiving wiki's real title parser decides the namespace and
DB key; no remote namespace approximation or rewriting of original titles.
Only spellings verified to name the local page or its whole-page redirect
aliases are passed to an exact indexed join against the live mappings and
central page IDs. Native claims take precedence over supplemental claims;
native-shadowed supplemental rows are excluded from effective lookup.

To avoid repeating title parsing, cache the derived map of simple main-space
spellings via MediaWiki's existing local-server object cache (falling back
to the configured main object cache). Its key includes a hash of the actual
raw spellings and effective main-space parsing rules. Changes to the set
of spellings create a different key. Namespace-like titles, entity forms
and interwiki syntax are parsed live, since their interpretation may change
when a prefix is reconfigured. The cache stores no source IDs, links,
permissions or authoritative claims. Changing a source, adding/removing
a link, evicting the cache or using separate cache backends cannot make an
old spelling dictionary authoritative. A disabled object cache simply
recomputes the same result. No cross-wiki cache invalidation is required.

Trade-off: this is a covering index range read for the requested language
on every actual parse/lifecycle lookup, not a constant-cost point lookup
on a persistent normalized index. An additional indexed candidate query
is needed when matching raw titles exist. The supplemental branch is only
consulted if native discovery finds no claim. No full-table scan is
requested, no SQL normalization function is applied, and no HTTP is added.
The parser cache still avoids this work on already-cached page renders.
The limits are 20,000 distinct raw spellings per language/table and 256
candidate spellings or source rows; incomplete results never authorize
automatic inference. Benchmark the real farm before adopting the design.

No new configuration option, schema updater, database privilege, backfill,
cache rebuild or cron job is required. A previously created
interlanguage_title_keys table is ignored; the extension never drops it.
Remove obsolete InterlanguageMaintainTitleKeys and InterlanguageUseTitleKeys
settings when convenient. Existing API, purge and namespace configurations
are preserved.

## Automatic targeted secondary-data refresh

The existing asynchronous purge job now requests non-recursive secondary
data refresh for dependent destinations, without an activation parameter.
Only those destinations persist the computed translation group. The central
wiki continues to receive ordinary purges because its effective links are
read directly from its mapping tables.

The dependent request uses forcelinkupdate=1 and redirects=1, never
forcerecursivelinkupdate. MediaWiki 1.43 independently authorizes purge and
linkpurge (both normally implicit rights). If the latter is refused, an
authorized ordinary purge can still succeed. Missing acknowledgements,
permission errors and rate limits are diagnosed, not bypassed or retried.
There is no additional HTTP request during parsing, no new job type and
no expansion of receiving wiki permissions. Verify the actual receiving
API's authorization and persistent properties as part of production
validation.

This replaces the optional wgInterlanguagePurgeLinkUpdateLanguages setting.
Remove it if it was added during an earlier rollout. The existing fallback
notification setting and interwiki API routes are unchanged. The job
remains bounded to 50 titles per request and does not retry automatically
on errors. An acknowledged targeted refresh is not proof that unrelated
custom HTML caches or all historical properties have been rebuilt.

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
HTTP requests or unfiltered central mapping scans are added. Both native and
supplemental reverse discovery use the normalized lookup described above.

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
