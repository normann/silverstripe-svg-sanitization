# Changelog

## 1.0.0 (unreleased)

Initial release, for Silverstripe CMS 6.

- Sanitise SVG uploads before they're stored, removing script, event handlers, `javascript:` links,
  `<foreignObject>`, DTDs and remote references, and reject SVGs that can't be parsed
  (`SvgSanitisingUploadValidator`)
- Combine it with silverstripe/mimevalidator's MIME type checks (`SvgSanitisingMimeUploadValidator`)
- Report and replace unsafe SVG files already in the asset store (`sake tasks:sanitise-svgs`)
- Sanitise SVG markup from anywhere else, e.g. before inlining it into a page (`SvgSanitiser`)
