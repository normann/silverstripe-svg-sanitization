<?php

namespace Normann\SvgSanitization\Tests\Tasks;

use Normann\SvgSanitization\Tasks\SanitiseSvgFilesTask;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests that the sanitise-svgs task reports unsafe SVG files and, with --apply, replaces them.
 *
 * Run with:
 * vendor/bin/phpunit tests/php/Tasks/SanitiseSvgFilesTaskTest.php
 */
class SanitiseSvgFilesTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    private const UNSAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>';

    private const CLEAN_SVG = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('SanitiseSvgFilesTaskTest');

        // Silverstripe doesn't allow SVG files by default
        File::config()->merge('allowed_extensions', ['svg']);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testReportOnlyDoesNotChangeFiles(): void
    {
        $unsafe = $this->createFile('unsafe.svg', self::UNSAFE_SVG, true);
        $this->createFile('clean.svg', self::CLEAN_SVG, true);

        $result = $this->runTask(false);

        $this->assertStringContainsString('UNSAFE   unsafe.svg', $result);
        $this->assertStringNotContainsString('clean.svg', $result);
        $this->assertStringContainsString('2 SVG files checked: 1 clean, 1 unsafe (0 sanitised)', $result);
        $this->assertSame(self::UNSAFE_SVG, File::get()->byID($unsafe->ID)->getString());
    }

    public function testApplySanitisesAndRepublishesFiles(): void
    {
        $published = $this->createFile('published.svg', self::UNSAFE_SVG, true);
        $draft = $this->createFile('draft.svg', self::UNSAFE_SVG, false);

        $result = $this->runTask(true);

        $this->assertStringContainsString('2 unsafe (2 sanitised)', $result);

        $published = File::get()->byID($published->ID);
        $this->assertStringNotContainsString('alert', $published->getString());
        $this->assertTrue($published->isPublished());
        $this->assertFalse($published->isModifiedOnDraft());

        $live = Versioned::get_by_stage(File::class, Versioned::LIVE)->byID($published->ID);
        $this->assertStringNotContainsString('alert', $live->getString());

        $draft = File::get()->byID($draft->ID);
        $this->assertStringNotContainsString('alert', $draft->getString());
        $this->assertFalse($draft->isPublished());
    }

    public function testApplySkipsFilesWithUnpublishedChanges(): void
    {
        $file = $this->createFile('changed.svg', self::UNSAFE_SVG, true);
        $file->Title = 'Changed on draft';
        $file->write();

        $result = $this->runTask(true);

        $this->assertStringContainsString('Skipped: changed.svg', $result);
        $this->assertSame(self::UNSAFE_SVG, File::get()->byID($file->ID)->getString());
    }

    public function testReportsInvalidFiles(): void
    {
        $this->createFile('invalid.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>', true);

        $result = $this->runTask(true);

        $this->assertStringContainsString('INVALID  invalid.svg', $result);
        $this->assertStringContainsString('1 need manual review', $result);
    }

    private function createFile(string $filename, string $contents, bool $publish): File
    {
        $file = File::create();
        $file->setFromString($contents, $filename);
        $file->write();

        if ($publish) {
            $file->publishSingle();
        }

        return $file;
    }

    private function runTask(bool $apply): string
    {
        $task = SanitiseSvgFilesTask::create();
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);
        $input = new ArrayInput($apply ? ['--apply' => true] : [], new InputDefinition($task->getOptions()));
        $input->setInteractive(false);

        $task->run($input, $output);

        return $buffer->fetch();
    }
}
