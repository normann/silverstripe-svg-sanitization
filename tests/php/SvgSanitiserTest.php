<?php

namespace Normann\SvgSanitization\Tests;

use Normann\SvgSanitization\SvgSanitiser;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests that SvgSanitiser removes script and remote references from SVG markup, keeps safe
 * markup intact and rejects files that aren't SVG documents.
 *
 * Run with:
 * vendor/bin/phpunit tests/php/SvgSanitiserTest.php
 */
class SvgSanitiserTest extends SapphireTest
{
    protected $usesDatabase = false;

    public static function provideUnsafeSvgs(): array
    {
        return [
            'script element' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>',
                ['<script', 'alert(1)'],
            ],
            'event handler attribute' => [
                '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>',
                ['onload', 'alert(1)'],
            ],
            'javascript: link' => [
                '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
                    . '<a xlink:href="javascript:alert(1)"><rect width="10" height="10"/></a></svg>',
                ['javascript:'],
            ],
            'foreignObject with HTML' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="10" height="10">'
                    . '<iframe xmlns="http://www.w3.org/1999/xhtml" src="javascript:alert(1)"></iframe>'
                    . '</foreignObject></svg>',
                ['foreignObject', 'iframe', 'javascript:'],
            ],
            'remote use reference' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://example.com/evil.svg#icon"/></svg>',
                ['example.com'],
            ],
            'external entity' => [
                '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
                    . '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>',
                ['ENTITY', 'root:'],
            ],
        ];
    }

    #[DataProvider('provideUnsafeSvgs')]
    public function testRemovesUnsafeContent(string $svg, array $unexpected): void
    {
        $sanitiser = SvgSanitiser::create();
        $clean = (string) $sanitiser->sanitise($svg);

        foreach ($unexpected as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $clean);
        }
    }

    public function testReportsWhatWasRemoved(): void
    {
        $sanitiser = SvgSanitiser::create();
        $sanitiser->sanitise('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->assertTrue($sanitiser->hasIssues());
        $this->assertStringContainsString("'script'", implode(' ', $sanitiser->getIssues()));
    }

    public function testKeepsSafeMarkup(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="icon">'
            . '<style>.st0{fill:#0c818f}</style>'
            . '<defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs>'
            . '<path class="st0 fill" d="M0 0h24v24H0z"/>'
            . '<circle cx="12" cy="12" r="4" fill="url(#g)"/>'
            . '<use href="#g"/>'
            . '</svg>';

        $sanitiser = SvgSanitiser::create();
        $clean = $sanitiser->sanitise($svg);

        $this->assertNotNull($clean);
        $this->assertFalse($sanitiser->hasIssues(), implode(', ', $sanitiser->getIssues()));
        foreach (['viewBox="0 0 24 24"', '.st0{fill:#0c818f}', 'class="st0 fill"', 'fill="url(#g)"', 'href="#g"'] as $needle) {
            $this->assertStringContainsString($needle, $clean);
        }
    }

    public function testFormattingDifferencesDoNotCountAsChanges(): void
    {
        // Typical Illustrator export: XML declaration, comment, single-quoted and self-closing tags
        $svg = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
            . "<!-- Generator: Adobe Illustrator 27.0.0, SVG Export Plug-In -->\n"
            . "<svg version='1.1' id='Layer_1' xmlns='http://www.w3.org/2000/svg' x='0px' y='0px'\n"
            . "     viewBox='0 0 24 24' style='enable-background:new 0 0 24 24;' xml:space='preserve'>\n"
            . "    <path class='st0' d='M0 0h24v24H0z'/>\n"
            . "</svg>\n";

        $sanitiser = SvgSanitiser::create();
        $sanitiser->sanitise($svg);

        $this->assertFalse($sanitiser->wasChanged(), implode(', ', $sanitiser->getIssues()));
    }

    public function testUnreportedRemovalsCountAsChanges(): void
    {
        // The library strips PHP tags without reporting an issue
        $sanitiser = SvgSanitiser::create();
        $sanitiser->sanitise('<svg xmlns="http://www.w3.org/2000/svg"><?php echo 1; ?><rect width="10" height="10"/></svg>');

        $this->assertFalse($sanitiser->hasIssues());
        $this->assertTrue($sanitiser->wasChanged());
    }

    public function testRemoteReferencesCanBeKept(): void
    {
        // A <use> that points at another document is always removed, so test with <image>
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.com/logo.png"/></svg>';

        $this->assertStringNotContainsString('example.com', (string) SvgSanitiser::create()->sanitise($svg));

        SvgSanitiser::config()->set('remove_remote_references', false);
        $this->assertStringContainsString('example.com', (string) SvgSanitiser::create()->sanitise($svg));
    }

    public function testOutputHasNoXmlDeclaration(): void
    {
        $clean = SvgSanitiser::create()->sanitise('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->assertStringStartsWith('<svg', (string) $clean);
    }

    public function testRejectsInvalidXml(): void
    {
        $sanitiser = SvgSanitiser::create();

        $this->assertNull($sanitiser->sanitise('<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>'));
        $this->assertTrue($sanitiser->hasIssues());
    }

    public function testRejectsXmlThatIsNotSvg(): void
    {
        $sanitiser = SvgSanitiser::create();

        $this->assertNull($sanitiser->sanitise('<html><body><script>alert(1)</script></body></html>'));
        $this->assertTrue($sanitiser->hasIssues());
    }

    public function testIsSvgFilename(): void
    {
        $this->assertTrue(SvgSanitiser::isSvgFilename('icons/logo.svg'));
        $this->assertTrue(SvgSanitiser::isSvgFilename('LOGO.SVG'));
        $this->assertFalse(SvgSanitiser::isSvgFilename('logo.svgz'));
        $this->assertFalse(SvgSanitiser::isSvgFilename('logo.png'));
        $this->assertFalse(SvgSanitiser::isSvgFilename(null));
    }
}
