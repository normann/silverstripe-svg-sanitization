<?php

namespace Normann\SvgSanitization\Tests\Upload;

use Normann\SvgSanitization\Upload\SvgSanitisingMimeUploadValidator;
use Normann\SvgSanitization\Upload\SvgSanitisingUploadValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Assets\Upload;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\MimeValidator\MimeUploadValidator;

/**
 * Tests that both validators sanitise SVG uploads before they're stored, and reject invalid SVGs.
 *
 * Run with:
 * vendor/bin/phpunit tests/php/Upload/SvgSanitisingUploadValidatorTest.php
 */
class SvgSanitisingUploadValidatorTest extends SapphireTest
{
    protected $usesDatabase = false;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The temp files below aren't real HTTP uploads
        foreach ([Upload_Validator::class, SvgSanitisingUploadValidator::class, SvgSanitisingMimeUploadValidator::class] as $class) {
            Config::modify()->set($class, 'use_is_uploaded_file', false);
        }

        if (class_exists(MimeUploadValidator::class)) {
            Config::modify()->merge(MimeUploadValidator::class, 'MimeTypes', ['svg' => ['image/svg+xml', 'image/svg']]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public static function provideValidators(): array
    {
        return [
            'default validator' => [SvgSanitisingUploadValidator::class],
            'MIME validator' => [SvgSanitisingMimeUploadValidator::class],
        ];
    }

    public function testUploadsUseTheSanitisingValidatorByDefault(): void
    {
        $this->assertInstanceOf(SvgSanitisingUploadValidator::class, Upload::create()->getValidator());
    }

    #[DataProvider('provideValidators')]
    public function testSanitisesSvgBeforeItIsStored(string $validatorClass): void
    {
        $tmpFile = $this->createTmpFile(
            'icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><rect width="10" height="10"/></svg>'
        );

        $validator = $this->createValidator($validatorClass, $tmpFile, ['svg']);

        $this->assertTrue($validator->validate(), implode(', ', $validator->getErrors()));

        $stored = file_get_contents($tmpFile['tmp_name']);
        $this->assertStringNotContainsString('alert', $stored);
        $this->assertStringContainsString('<rect', $stored);
    }

    #[DataProvider('provideValidators')]
    public function testRejectsInvalidSvg(string $validatorClass): void
    {
        $tmpFile = $this->createTmpFile('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>');

        $validator = $this->createValidator($validatorClass, $tmpFile, ['svg']);

        $this->assertFalse($validator->validate());
        $this->assertNotEmpty($validator->getErrors());
    }

    #[DataProvider('provideValidators')]
    public function testLeavesOtherFileTypesAlone(string $validatorClass): void
    {
        // A minimal 1x1 PNG
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $tmpFile = $this->createTmpFile('image.png', $png);

        $validator = $this->createValidator($validatorClass, $tmpFile, ['png']);

        $this->assertTrue($validator->validate(), implode(', ', $validator->getErrors()));
        $this->assertSame($png, file_get_contents($tmpFile['tmp_name']));
    }

    public function testMimeValidatorStillChecksTheMimeType(): void
    {
        // Script saved with an .svg extension: rejected by the MIME check before it's sanitised
        $tmpFile = $this->createTmpFile('icon.svg', "#!/bin/sh\necho 'not an image'\n");

        $validator = $this->createValidator(SvgSanitisingMimeUploadValidator::class, $tmpFile, ['svg']);

        $this->assertFalse($validator->validate());
    }

    private function createValidator(string $class, array $tmpFile, array $allowedExtensions): Upload_Validator
    {
        if (!class_exists(MimeUploadValidator::class) && $class === SvgSanitisingMimeUploadValidator::class) {
            $this->markTestSkipped('silverstripe/mimevalidator is not installed');
        }

        $validator = $class::create();
        $validator->setAllowedExtensions($allowedExtensions);
        $validator->setTmpFile($tmpFile);

        return $validator;
    }

    private function createTmpFile(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'svgtest');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return [
            'name' => $name,
            'type' => '',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
        ];
    }
}
