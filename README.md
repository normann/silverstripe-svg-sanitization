# Silverstripe SVG Sanitization

[![CI](https://github.com/normann/silverstripe-svg-sanitization/actions/workflows/ci.yml/badge.svg?branch=1)](https://github.com/normann/silverstripe-svg-sanitization/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/normann/silverstripe-svg-sanitization.svg)](https://packagist.org/packages/normann/silverstripe-svg-sanitization)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE)

Sanitises SVG files in Silverstripe CMS 6, so an uploaded SVG can't run script or load remote resources.

An SVG is an XML document that can contain script. Opened directly from your site, or inlined into a page, a
malicious SVG runs that script with your site's permissions: it can act as the logged-in CMS user, or show a fake
login form on your domain. Checking the file extension or MIME type doesn't help, as the file really is an SVG.

This module:

- cleans every SVG upload **before it's stored**, so the original never reaches the asset store: the Files area,
  `UploadField`, `FileField` and userforms file fields
- rejects SVGs that can't be parsed, with a message to the person uploading
- reports and cleans SVGs **already in the asset store** (`sake tasks:sanitise-svgs`)
- gives you the sanitiser to clean SVG markup from anywhere else, e.g. before inlining it into a page

Sanitising is done by [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer), an allowlist-based
sanitiser modelled on DOMPurify's SVG lists. It removes script, event handlers (`onload` etc.), `javascript:`
links, `<foreignObject>`, animations that change links, DTDs and entities, and references to remote resources.

## Installation

```sh
composer require normann/silverstripe-svg-sanitization
```

Then flush (`vendor/bin/sake flush`, or `?flush=1`).

| Module version | Branch | Silverstripe CMS | PHP |
| --- | --- | --- | --- |
| 1.x | `1` | 6 | 8.3+ |

### Allowing SVG files

Silverstripe doesn't allow SVG files by default, and this module doesn't change that. To allow them:

```yaml
SilverStripe\Assets\File:
  allowed_extensions:
    - svg
```

Don't allow `.svgz`: it's a compressed SVG, which can't be sanitised without decompressing it.

## Setup

Uploads are sanitised as soon as the module is installed, by replacing the default upload validator
(`SilverStripe\Assets\Upload_Validator`) with `SvgSanitisingUploadValidator` through the Injector.

### With silverstripe/mimevalidator

If you use [silverstripe/mimevalidator](https://github.com/silverstripe/silverstripe-mimevalidator), use
`SvgSanitisingMimeUploadValidator`, which runs the MIME type checks first and then sanitises SVGs:

```yaml
---
Name: app-upload-validator
After:
  - '#svg-sanitization'
---
SilverStripe\Core\Injector\Injector:
  SilverStripe\Assets\Upload_Validator:
    class: Normann\SvgSanitization\Upload\SvgSanitisingMimeUploadValidator

# The MIME types SVG files can be detected as
SilverStripe\MimeValidator\MimeUploadValidator:
  MimeTypes:
    svg:
      - 'image/svg+xml'
      - 'image/svg'
```

If your project already replaces `Upload_Validator` with `MimeUploadValidator`, change it to this class, or
uploads won't be sanitised.

### With your own upload validator

Use the `SanitisesSvgUploads` trait in it:

```php
use Normann\SvgSanitization\Upload\SanitisesSvgUploads;
use SilverStripe\Assets\Upload_Validator;

class MyUploadValidator extends Upload_Validator
{
    use SanitisesSvgUploads;
}
```

Then point the Injector at your class, as above.

## Sanitising existing files

Uploads are sanitised from when the module is installed, so check the SVGs already in your asset store:

```sh
vendor/bin/sake tasks:sanitise-svgs            # Report only
vendor/bin/sake tasks:sanitise-svgs --apply    # Replace unsafe files with their sanitised content
```

```
UNSAFE   icons/alert.svg (ID 12): Suspicious attribute 'onload' (line 2); Suspicious tag 'script' (line 4)
         Sanitised and republished
INVALID  logos/old.svg (ID 31): not a valid SVG, check and replace or delete it (...)

3 SVG files checked: 1 clean, 1 unsafe (1 sanitised), 1 need manual review.
```

- Clean files are left alone, so only files with unsafe content get a new version. Use `-v` to list them too.
- Files that were published are republished. Files with unpublished changes are skipped, as republishing them
  would also publish those changes: publish or revert them, then run the task again.
- Invalid and missing files are reported for you to check, and never changed.
- It changes files, so it only runs from the command line.

## Sanitising SVG markup yourself

Use `SvgSanitiser` for SVG markup that doesn't come from an upload, e.g. before inlining a file into a page:

```php
use Normann\SvgSanitization\SvgSanitiser;

$sanitiser = SvgSanitiser::create();
$clean = $sanitiser->sanitise($file->getString());

if ($clean === null) {
    // Not a valid SVG document: don't output it
}

$sanitiser->wasChanged(); // Whether anything was removed (formatting differences don't count)
$sanitiser->getIssues();  // What was removed, e.g. "Suspicious tag 'script' (line 3)"
```

The output has no XML declaration, so it can be inlined into HTML as is.

## Options

| Option | Set on | Default | Description |
| --- | --- | --- | --- |
| `remove_remote_references` | `Normann\SvgSanitization\SvgSanitiser` | `true` | Remove references to remote resources, e.g. `<image href="https://...">` and `url()` in `<style>`, so an SVG can't load content from, or send requests to, another site. A `<use>` that points at another document is always removed. |

## Serving SVG files safely

Sanitising is the main protection. As a second layer, serve SVG files with a Content-Security-Policy that stops
any script in them running, in case one ever gets past the sanitiser. It doesn't affect SVGs shown with `<img>`
or in CSS, or inlined into a page.

A module can't change your web server's configuration, so add it yourself. For Apache, in `public/.htaccess`:

```apache
<IfModule mod_headers.c>
    <FilesMatch "(?i)\.svg$">
        Header set Content-Security-Policy "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox"
        Header set X-Content-Type-Options "nosniff"
    </FilesMatch>
</IfModule>
```

For nginx, in the `server` block that serves your assets:

```nginx
location ~* \.svg$ {
    add_header Content-Security-Policy "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox" always;
    add_header X-Content-Type-Options "nosniff" always;
}
```

If you use a CDN, check it passes these headers through.

## Good to know

- Formatting can change: comments, the XML declaration and some whitespace are removed, and empty elements are
  written out in full (`<path></path>`). How the SVG looks doesn't change.
- Some editor-specific data is removed too, e.g. Adobe Illustrator's `i:aipgf` elements, as it isn't on the
  allowlist.
- Public forms with file fields (userforms) accept SVG files if you allow them. They're sanitised, but if your
  forms don't need SVGs, block them there:

  ```yaml
  SilverStripe\UserForms\Model\EditableFormField\EditableFileField:
    allowed_extensions_blacklist:
      - svg
  ```

## Development

```sh
composer install
composer lint   # PHP code style
```

The PHP tests run in a Silverstripe project with this module installed from source, ie. with
`composer require --prefer-source`, or a symlinked Composer `path` repository (copies leave the tests out):

```sh
vendor/bin/phpunit vendor/normann/silverstripe-svg-sanitization/tests/php
```

## License

[BSD-3-Clause](LICENSE)
