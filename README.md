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

Do not change production registration as part of the repository migration.
Verify the existing farm configuration separately.

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

The autonomous CI runs PHP 8.4 syntax checks and all six existing isolated
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
