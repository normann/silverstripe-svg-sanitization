<?php

namespace Normann\SvgSanitization\Upload;

use SilverStripe\Assets\Upload_Validator;

/**
 * The default upload validator, plus SVG sanitising. Replaces Upload_Validator through the Injector
 * (see _config/config.yml).
 */
class SvgSanitisingUploadValidator extends Upload_Validator
{
    use SanitisesSvgUploads;
}
