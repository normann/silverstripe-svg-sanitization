<?php

namespace Normann\SvgSanitization\Upload;

use SilverStripe\MimeValidator\MimeUploadValidator;

/**
 * silverstripe/mimevalidator's MIME type checks, plus SVG sanitising. Sanitising runs after the MIME
 * check passes.
 *
 * Requires silverstripe/mimevalidator. Use it in place of MimeUploadValidator in your Injector config,
 * see the README.
 */
class SvgSanitisingMimeUploadValidator extends MimeUploadValidator
{
    use SanitisesSvgUploads;
}
