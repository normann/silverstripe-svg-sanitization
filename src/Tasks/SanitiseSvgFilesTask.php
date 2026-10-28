<?php

namespace Normann\SvgSanitization\Tasks;

use Normann\SvgSanitization\SvgSanitiser;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Checks every SVG file in the asset store with SvgSanitiser and reports the ones that contain unsafe
 * content (script, event handlers, remote references etc). With --apply, replaces them with their
 * sanitised content and republishes the ones that were published.
 *
 * SVG uploads are sanitised before they're stored, so this is for files uploaded before the module
 * was installed, or added to the asset store without an upload.
 *
 * Run with:
 * vendor/bin/sake tasks:sanitise-svgs            # report only
 * vendor/bin/sake tasks:sanitise-svgs --apply    # replace unsafe files
 */
class SanitiseSvgFilesTask extends BuildTask
{
    protected static string $commandName = 'sanitise-svgs';

    protected string $title = 'Sanitise SVG files';

    protected static string $description = 'Reports SVG files containing unsafe content (script, event handlers, remote references) and, with --apply, replaces them with sanitised copies.';

    // Writes to files, so only run it from the command line
    private static bool $can_run_in_browser = false;

    public function getOptions(): array
    {
        return [
            new InputOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Replace unsafe files with their sanitised content. Without this, only reports.'
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $counts = ['checked' => 0, 'clean' => 0, 'unsafe' => 0, 'sanitised' => 0, 'manual' => 0];

        if (!$apply) {
            $output->writeln('Report only. Run with --apply to replace unsafe files.');
        }

        // Work on the draft stage so unpublished files are included
        Versioned::withVersionedMode(function () use ($apply, $output, &$counts) {
            Versioned::set_stage(Versioned::DRAFT);

            foreach (File::get()->filter('FileFilename:EndsWith:nocase', '.svg') as $file) {
                $counts['checked']++;
                $this->checkFile($file, $apply, $output, $counts);
            }
        });

        $output->writeln('');
        $output->writeln(sprintf(
            '%d SVG files checked: %d clean, %d unsafe (%d sanitised), %d need manual review.',
            $counts['checked'],
            $counts['clean'],
            $counts['unsafe'],
            $counts['sanitised'],
            $counts['manual']
        ));

        return Command::SUCCESS;
    }

    private function checkFile(File $file, bool $apply, PolyOutput $output, array &$counts): void
    {
        $label = OutputFormatter::escape(sprintf('%s (ID %d)', $file->getFilename(), $file->ID));
        $sanitiser = SvgSanitiser::create();

        $contents = $file->getString();
        if (!$contents) {
            $counts['manual']++;
            $output->writeln("MISSING  $label: file not found in the asset store");
            return;
        }

        $clean = $sanitiser->sanitise($contents);
        if ($clean === null) {
            $counts['manual']++;
            $output->writeln(sprintf('INVALID  %s: not a valid SVG, check and replace or delete it (%s)', $label, $this->issues($sanitiser)));
            return;
        }

        if (!$sanitiser->wasChanged()) {
            $counts['clean']++;
            $output->writeln("CLEAN    $label", PolyOutput::VERBOSITY_VERBOSE);
            return;
        }

        $counts['unsafe']++;
        $output->writeln(sprintf('UNSAFE   %s: %s', $label, $this->issues($sanitiser)));

        if (!$apply) {
            return;
        }

        // Republishing would also publish the other unpublished changes, so leave those files alone
        if ($file->isPublished() && $file->isModifiedOnDraft()) {
            $counts['manual']++;
            $output->writeln("         Skipped: $label has unpublished changes. Publish or revert them, then run this again.");
            return;
        }

        $wasPublished = $file->isPublished();
        $file->setFromString($clean, $file->getFilename(), null, null, ['conflict' => AssetStore::CONFLICT_OVERWRITE]);
        $file->write();
        if ($wasPublished) {
            $file->publishSingle();
        }

        $counts['sanitised']++;
        $output->writeln('         Sanitised' . ($wasPublished ? ' and republished' : ''));
    }

    private function issues(SvgSanitiser $sanitiser): string
    {
        return OutputFormatter::escape(implode('; ', array_unique($sanitiser->getIssues())) ?: 'content removed');
    }
}
