<?php

namespace Normann\SvgSanitization\Upload;

use Normann\SvgSanitization\SvgSanitiser;

/**
 * Sanitises uploaded SVG files once the validator's own checks pass, before the file is stored, so
 * script and remote references never reach the asset store. Rejects SVGs that can't be parsed.
 *
 * Upload::load() and Upload::loadIntoFile() have no extension hook before the file is stored, and
 * Upload_Validator::validate() has none either. The validator runs first and is created through the
 * Injector, so a validator subclass covers every upload path: the Files area, UploadField, FileField
 * and userforms file fields.
 *
 * For use in subclasses of SilverStripe\Assets\Upload_Validator.
 */
trait SanitisesSvgUploads
{
    public function validate()
    {
        if (parent::validate() === false) {
            return false;
        }

        if (!empty($this->tmpFile['tmp_name']) && SvgSanitiser::isSvgFilename($this->tmpFile['name'] ?? '')) {
            return $this->sanitiseSvgUpload();
        }

        return true;
    }

    /**
     * Overwrites the uploaded temp file with its sanitised content. Rejects the upload if the file
     * isn't a valid SVG document or can't be rewritten.
     */
    protected function sanitiseSvgUpload(): bool
    {
        $path = $this->tmpFile['tmp_name'];
        $contents = file_get_contents($path);
        $clean = $contents === false ? null : SvgSanitiser::create()->sanitise($contents);

        if ($clean === null) {
            $this->errors[] = _t(
                SanitisesSvgUploads::class . '.INVALIDSVG',
                'The SVG file could not be read. Please check it is a valid SVG image.'
            );
            return false;
        }

        if (file_put_contents($path, $clean) === false) {
            $this->errors[] = _t(
                SanitisesSvgUploads::class . '.SANITISEFAILED',
                'The SVG file could not be processed. Please try again.'
            );
            return false;
        }

        $this->tmpFile['size'] = strlen($clean);

        return true;
    }
}
