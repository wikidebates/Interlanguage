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
redirect can retarget a unique, non-shadowed supplemental row only when a
fresh normalized lookup finds no competing native or automatic claim; the old row is compared exactly and
deleted/inserted atomically, without requesting an additional UPDATE grant.
A missing or incomplete index search, competing claims, and deleted file or
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

## Normalized reverse lookup without a schema migration

The dependent wiki parses distinct raw spellings for its own language
using MediaWiki's local title parser. A small dictionary in the existing
WANObjectCache maps actual namespace/DB-key identities to alternate raw
spellings. Ordinary canonical spellings are always queried directly and
are not stored in the dictionary. Cold builds read only that language
using the existing covering indexes on (ll_lang,ll_title) and
(ill_lang,ill_title); warm lookups query current mapping rows by the
original indexed language and title predicates. The dictionary never stores
central page IDs or mappings, so an obsolete spelling cannot resurrect a
removed row. Each candidate is parsed again locally. Native precedence,
supplemental shadowing, ambiguity and bounded failure handling are
preserved. Incomplete inventories (over 20,000 distinct rows across the
two tables) or candidate sets over 256 fail closed. No new database table,
SQL grant, configuration option, HTTP request or manual backfill is required.

Cache entries are receiver-local; a shared global WAN check key, based on
the central database domain and target language, is touched after committed
native or supplemental changes. There is no process-cache TTL override or
stale grace period; entries expire in at most 120 seconds under normal
cache operation. A shared, persistent WAN backend across the farm is
necessary for immediate cross-wiki invalidation and good warm performance:
verify this on the actual deployment before switching. With an empty or
nonpersistent backend, lookup works but repeatedly builds the language
dictionary, which is not a recommended production configuration. Separate
backends or failed invalidations can leave newly introduced alternate
spellings undiscovered until expiry; live rows still prevent resurrection
of deleted mappings. New fallback associations and dependent move/delete
reconciliation use fresh (uncached) spellings before any write, so a
cached miss cannot justify an automatic data change. Concurrent edits
across independent transactions are not serialized.

Direct SQL inserts and changes bypass cache hooks; invalidate the relevant
language dictionary after such operations, or allow its short TTL to expire
before relying on it. Rebuild no page caches globally. A previous optional
interlanguage_title_keys table is no longer read or written; leave it
untouched until the new code has been validated, then remove it separately
if no older workers are using it. The former MaintainTitleKeys and
UseTitleKeys options are obsolete and should not be added to LocalSettings.

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
HTTP requests or per-lookup full-table scans are added. See the normalized
reverse lookup section for the bounded language-scoped cold-cache reads.

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
