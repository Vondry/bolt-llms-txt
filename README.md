# llms.txt for Bolt CMS

Serves a dynamic [`/llms.txt`](https://llmstxt.org) from a Bolt 6 site, rendered from your own Twig template on every
request, so it always matches the content. You write the template; the extension takes care of the rest:

- the `/llms.txt` route, rendered in a locale of your choice (records, `__()` / `|trans` and dates)
- a `text/plain; charset=UTF-8` response that proxies and browsers may cache (`public`, `max_age` from the config),
  with an ETag, so clients get a `304 Not Modified` while nothing has changed
- no firewall entry needed: Symfony normally makes every frontend response of Bolt `private`, the extension opts out
  for `/llms.txt`. It stays `private` for logged-in users and whenever a cookie is set
- Twig filters that turn CMS field HTML into single-line Markdown text: `html_to_text`, `markdown_label`,
  `markdown_url`
- blank-line cleanup, so `{% if %}` blocks in the template don't leave gaps
- a generic default template (site name, payoff and the records of every ContentType) until your theme has its own

## Requirements

- Bolt CMS `^6.0`, PHP `>=8.2` with the `dom` and `libxml` extensions

## Installation

```bash
composer require tomvondracek/bolt-llms-txt
bin/console extensions:configure --with-config
bin/console cache:clear
```

`extensions:configure` copies the route and the `@llms-txt` Twig namespace into the project
(`config/routes/extension_bolt-llms-txt.yaml`, `config/packages/extension_bolt-llms-txt.yaml`), and `--with-config`
the configuration to `config/extensions/tomvondracek-llmstxt.yaml`. The extension's services need no configuration:
Bolt registers them as they are.

Remove a static `public/llms.txt` if there is one: Apache and nginx serve existing files before Symfony gets the
request.

## Writing the template

Create `llms.txt.twig` in your theme (or point `template` in the config at another file). The extension looks it up in
the theme (and its `template_directory`) first and renders it with the usual Bolt Twig environment (`setcontent`,
`config`, `__()`, `|link`, …) plus:

| Variable | Value |
|---|---|
| `today` | Today's date (`Y-m-d`) in the site's timezone (`general/timezone`); printing it changes the ETag daily |
| `now` | The same moment as a `DateTimeImmutable` |
| `baseUrl` | Scheme, host and base path, e.g. `https://example.com` |
| `locale` | The locale the file is rendered in |

A minimal template:

```twig
{#- Autoescaping must be off for a text file: pass every CMS value through the filters instead. -#}
{%- autoescape false -%}
{%- setcontent pages = 'pages' limit 1000 page 1 -%}
# {{ config.get('general/sitename')|html_to_text }}

> {{ config.get('general/payoff')|html_to_text }}

This file is generated from the website's content management system.

## Pages

{% for page in pages %}
- [{{ page.title|markdown_label }}]({{ page|link(true) }}): {{ page.teaser|html_to_text(200) }}
{% endfor %}
{% endautoescape %}
```

Symfony's default `autoescape: name` strategy already switches escaping off for `.txt.twig` files; the tag keeps it
off when a project sets `twig.autoescape: html`, which would turn `&` into `&amp;`.

The [llms.txt format](https://llmstxt.org) in short: an H1, an optional `>` summary, free Markdown **without
headings**, then H2 sections that contain only `- [name](url): notes` lists. An `## Optional` section holds links that
can be skipped.

The file is cached for everyone, so it must not depend on who is viewing it: don't use `app.user`,
`is_granted()` or edit links. (A logged-in user's copy is never stored by shared caches, but browsers and the
ETag still treat it like everyone else's.)

### Filters

| Filter | Does |
|---|---|
| `html_to_text` | Any field value (rich text, plain text, Twig markup) as one line of plain text. Paragraphs and list items are separated with punctuation instead of running together, entities are decoded once, invisible content (`<script>`, `<style>`, `<svg>`, form controls, media fallbacks, a full document's `<title>`) is dropped, image `alt` texts kept, and links stay Markdown links with absolute URLs (site-relative ones on the canonical host). `html_to_text(200)` shortens the text to 200 characters at a word boundary, ending with `…`, and stops reading a long field once it has enough; links then keep only their text, so no link is cut in half. |
| `markdown_label` | The text only (links too keep only their text), safe as the `[label]` of a Markdown link (`[` `]` become `(` `)`). Give it the raw field, not text that already went through `html_to_text`. |
| `markdown_url` | A URL field as an absolute URL for the `(url)` of a Markdown link (`www.example.com` → `https://www.example.com`, `info@example.com` → `mailto:info@example.com`, `/page` → `https://site/page`), or `''` when it is not a link a reader can follow (`javascript:`, `#anchor`, `page.html`). |

### Tips

- Add `page 1` to every `setcontent`, so `?page=` in the URL can't pick another page of a listing.
- Order everything deterministically (no `|shuffle`) and leave out `today`/`now`: an unchanged site then gives the
  same body and ETag, so revalidating clients get a 304.
- `baseUrl` is the address `|link(true)` writes, so with `general/canonical` set both use the canonical host; so do
  site-relative links in rich text.
- For an excerpt, take one field (`record.teaser|html_to_text(200)`) rather than joining all of them: the filter stops
  reading once it has enough text, but only within the field it is given.
- Records are loaded in the configured `locale`. Fields without a value in that locale fall back to the default
  locale when `localization/fallback_when_missing` is on in `config/bolt/config.yaml`.

## Configuration

`config/extensions/tomvondracek-llmstxt.yaml`:

```yaml
enabled: true            # false = /llms.txt returns 404
template: llms.txt.twig  # theme template; while it doesn't exist, the shipped generic one is used
locale: ~                # one of the site's locales (app_locales), e.g. en; empty = the default locale
max_age: 3600            # seconds browsers and proxies may cache the response
```

A `tomvondracek-llmstxt_local.yaml` next to it overrides single values, as for other Bolt extensions.

A template you name explicitly (anything other than the default `llms.txt.twig`) must exist; a typo is an error, not a
silent fallback. The same goes for the config itself: an unknown key or a value of the wrong type (`max_age: 1h`) is
an error that names the problem, an empty value means the default.

### More files, e.g. /llms-full.txt

The controller reads `template`, `locale` and `max_age` from the route's `defaults` too, so a project route can serve
more files. Add it to `config/routes.yaml`, above Bolt's frontend routes:

```yaml
llms_full_txt:
  path: /llms-full.txt
  methods: [GET, HEAD]
  controller: Tomvondracek\LlmsTxt\Controller\LlmsTxtController
  defaults:
    template: llms-full.txt.twig
```

## Development

```bash
composer install

vendor/bin/phpunit
vendor/bin/ecs check src tests
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/rector process --dry-run
```

To try it in a Bolt project from a local checkout:

```bash
composer config repositories.llms-txt '{"type":"path","url":"../bolt-llms-txt"}'
composer require tomvondracek/bolt-llms-txt:@dev
bin/console extensions:configure --with-config
```

### Layout

| Path | What |
|---|---|
| `src/Controller/LlmsTxtController.php` | `/llms.txt` route: config and route defaults, locale, rendering |
| `src/LlmsTxtResponse.php` | Normalized body, `text/plain`, public caching, ETag, 304 |
| `src/EventSubscriber/LlmsTxtResponseSubscriber.php` | Makes the response `private` when a cookie is set; removes Symfony's internal cache header |
| `src/LlmsTxtConfig.php`, `src/LlmsTxtConfigLoader.php` | Typed config with defaults, loaded like Bolt's `ConfigTrait` |
| `src/SiteUrl.php` | The site's canonical homepage URL, for `baseUrl` and site-relative links |
| `src/Twig/HtmlToTextExtension.php`, `src/Twig/PlainTextBuffer.php` | `html_to_text`, `markdown_label`, `markdown_url`, in linear time |
| `templates/llms.txt.twig` | Generic default template (`@llms-txt/llms.txt.twig`) |

## License

MIT
