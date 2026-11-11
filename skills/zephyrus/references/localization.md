# Localization and formatting

Read this when the app translates text, serves several locales, or formats
money, numbers and dates.

## Catalogs

`localization.locale_path: locale` (relative to the `basePath` given to
`withConfiguration()`) loads every `locale/<lang>/*.json` file into one
catalog per language; nested objects become dot keys. With
`locale/en/cart.json` holding
`{"cart": {"items": "{count} {count|plural:item:items}", "total": "Total: {amount|money}"}}`,
`locale/fr/cart.json` holding
`{"cart": {"items": "{count} {count|plural:article:articles}"}}`, and
`localization: { locale: en_CA, supported_locales: [en, fr], locale_path: locale, currency: CAD }`:

```php
localize('cart.items', ['count' => 3]);         // "3 items"
localize('cart.items', ['count' => 0], 'fr');   // "0 article" (French: 0 is singular)
localize('cart.total', ['amount' => 1234.5]);   // "Total: $1,234.50"
localize('cart.missing');                       // "cart.missing"
format('money', 1234.5);                        // "$1,234.50"
$app->transFromRequest('cart.items', ['count' => 1], Request::fromArray('GET', '/', headers: ['Accept-Language' => 'fr-CA,fr;q=0.9'])); // "1 article"
```

- Lookup order: the requested locale, its base language (`fr-CA`, then `fr`),
  the default locale, then its base language. A key missing everywhere
  returns the key itself.
- Placeholders are `{name}` or `{name|pipe|pipe:argument}`. Text pipes:
  `lower`, `upper`, `title`, `trim`, `ltrim`, `rtrim`, `number`, `truncate`,
  `plural` (`singular:plural`, the word only), `default`; any `Formatter` name
  (`money`, `date`, ...) also works as a pipe. An unknown pipe throws
  `LocalizationException` *(dev)*.
- `i18n()` is an alias of `localize()`.

## The request locale

`localize()` uses the default locale (`localization.locale`) unless you pass
one. `$app->transFromRequest()` and `$app->resolveLocaleFromRequest()` pick the
locale from an explicit value, then `Accept-Language`, within
`supported_locales`. For a whole request in another language, a middleware of
yours resolves the locale and installs a translator and a formatter for it:

```php
App::setTranslator(new Translator(new JsonLocaleLoader($root . '/locale'), 'fr_CA'));
App::setFormatter(new Formatter('fr_CA', 'CAD'));
```

Set both: formatter pipes inside a translation use the app's `Formatter`, so
a French catalog still prints `$1,234.50` until the formatter changes
(gotcha 33).

## Formatter

`build()` installs `new Formatter(localization.locale, localization.currency, ...)`
behind `format()`. A command-line script that never calls `build()` must call
`App::setFormatter()` first. Built-in names: `money`, `decimal`, `percent`,
`ordinal`, `spellOut`, `date`, `time`, `datetime`, `timeago`, `duration`,
`filesize`, `list`, `truncate`; an unknown name throws `FormatterException` (its message listing the
built-in names is *(dev)*; earlier releases name only the formatter). Register your own with `$formatter->register('initials', fn (string $name): string => ...)`.

In Latte, the formatter methods become filters only after
`$latteEngine->registerFormatterFilters($formatter)`:

```latte
{$price|money}   {$createdAt|date}
```
