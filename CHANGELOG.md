# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-10-06

### Added

- Server-side cache of the rendered file in Bolt's cache (`cache.app`, tag
  `llms_txt`), for `max_age` seconds per template, locale, host and date. It is
  dropped as soon as records, field values or translations, taxonomies,
  relations or media change: in the editor, with bulk actions or through the
  API. Logged-in users and debug mode always get a fresh rendering.
- `markdown_url` turns e-mail addresses into `mailto:` links and accepts
  internationalised domains and ports.
- `X-Content-Type-Options: nosniff` on the response.
- Error messages for unknown config keys, config values of the wrong type and
  an invalid `general/timezone`.

### Changed

- The controller extends Bolt's `ExtensionController` and gets every dependency
  as a service, without project binds or `#[Autowire]`. It is `final` and no
  longer extends `TwigAwareController`, so Bolt's theme asset packages are not
  set up for the rendering.
- The config and route defaults are validated strictly: values that used to be
  read leniently (`enabled: 'no'`, `max_age: 1h`) and unknown keys are errors.
  Empty values still mean the default.
- `max_age` also sets how long the server keeps the rendered file; `0` renders
  on every request.
- `html_to_text` runs in linear time (8,000 paragraphs: 9.6 s → 0.05 s) and,
  with a maximum length, stops reading once it has enough text.
- The shipped template takes a record's excerpt from its first text field with
  content, in the ContentType's field order, instead of joining all of them.

### Fixed

- Bolt's widgets could inject HTML into the text: the controller is no longer in
  Bolt's frontend zone.
- Site-relative links in rich text used the request's host instead of the
  canonical one.
- `markdown_label` kept links inside the label, and a trailing backslash broke
  the Markdown link.
- `markdown_url` turned file names and numbers (`page.html`, `3.14`) into web
  links, and `<` and `>` in URLs were not encoded.
- `html_to_text` printed the content of `<svg>`, form controls, media fallbacks
  and a full document's `<title>`, dropped text after a literal `</body>`, could
  garble text with a `<meta charset>` in the field, and returned nothing for
  very deeply nested HTML.
- `html_to_text(0)` returned `…` instead of an empty string.
- Site locales with spaces (`app_locales: "cs| en"`) did not match.
- The shipped template printed today's date, so the ETag changed every day.
- The shipped template wrote `&amp;` in projects with `twig.autoescape: html`.

## [1.0.0] - 2026-10-05

### Added

- `/llms.txt` route rendering a Twig template of the theme (`template`, default
  `llms.txt.twig`), with a generic template shipped as a fallback.
- `text/plain; charset=UTF-8` response, `public` for `max_age` seconds, with an
  xxh128 ETag and `304 Not Modified`. Works without a firewall entry: the
  response opts out of Symfony's automatic `private` for requests with a session,
  and stays `private` for logged-in users and whenever a cookie is set.
- Rendering in a configurable `locale` (request, translator and Intl), checked
  against the site's locales.
- `html_to_text` (optionally shortened to a maximum length), `markdown_label`
  and `markdown_url` Twig filters.
- `today`, `now`, `baseUrl` (canonical host) and `locale` template variables.
- Route `defaults` (`template`, `locale`, `max_age`) for serving more files,
  e.g. `/llms-full.txt`, from the same controller.
- `enabled` switch.

[Unreleased]: https://github.com/Vondry/bolt-llms-txt/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/Vondry/bolt-llms-txt/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Vondry/bolt-llms-txt/releases/tag/v1.0.0
