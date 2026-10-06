# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Container registration without project binds or `#[Autowire]`: the controller extends Bolt's
  `ExtensionController` and gets every dependency as a service, so a fresh `composer require` compiles before
  `extensions:configure` has run. It is outside Bolt's frontend zone, so widgets never inject HTML into the text.
- Validation of the config and route defaults: unknown keys and values of the wrong type are errors.
- `X-Content-Type-Options: nosniff` on the response.
- Server-side cache of the rendered file for `max_age` seconds, per template, locale, host and date, dropped on
  every Doctrine flush that changes content. Logged-in users and debug mode bypass it.
