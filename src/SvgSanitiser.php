<?php

namespace Normann\SvgSanitization;

use DOMDocument;
use DOMXPath;
use enshrined\svgSanitize\Sanitizer;
use LogicException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Sanitises SVG markup so it can't run script or load remote resources when it's served or inlined
 * into a page. Wraps enshrined/svg-sanitize, an allowlist-based sanitiser that removes any tag or
 * attribute not on its SVG allowlist (script, event handlers, javascript: links, foreignObject etc).
 *
 * Used by the upload validators and the sanitise-svgs task. Use it directly to sanitise SVG markup
 * from anywhere else, e.g. before inlining a file into a page:
 *
 *     $clean = SvgSanitiser::create()->sanitise($file->getString());
 */
class SvgSanitiser
{
    use Configurable;
    use Injectable;

    /**
     * Remove references to remote resources (e.g. <use href="https://...">, url() in <style>), so
     * an SVG can't load content from, or leak requests to, another site.
     */
    private static bool $remove_remote_references = true;

    /**
     * What the last call to sanitise() removed, or why it couldn't parse the markup.
     */
    private array $issues = [];

    /**
     * Whether the last call to sanitise() removed anything, ignoring formatting differences.
     */
    private bool $changed = false;

    public static function isSvgFilename(?string $filename): bool
    {
        return strtolower(pathinfo($filename ?? '', PATHINFO_EXTENSION)) === 'svg';
    }

    /**
     * Returns the sanitised markup, or null if it can't be parsed as an SVG document.
     */
    public function sanitise(string $svg): ?string
    {
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences((bool) static::config()->get('remove_remote_references'));
        $sanitizer->removeXMLTag(true);

        try {
            $clean = $sanitizer->sanitize($svg);
            $this->issues = $this->formatIssues($sanitizer->getXmlIssues());
        } catch (LogicException $e) {
            // 1.0.0 threw this for valid XML without exactly one <svg> element. Since 1.1.0 the library
            // returns false instead, but keep failing closed if a later version throws again.
            $clean = false;
            $this->issues = [$e->getMessage()];
        }

        // The library returns false when the markup isn't valid XML. Also reject anything whose
        // root element isn't <svg>.
        if (!is_string($clean) || !preg_match('/^\s*<svg[\s>]/i', $clean)) {
            if (empty($this->issues)) {
                $this->issues = ['Not an SVG document'];
            }
            $this->changed = true;
            return null;
        }

        // The library doesn't report everything it removes (e.g. PHP tags), so compare
        // canonical forms of the input and output instead of relying on the issues it reports.
        $this->changed = $this->canonicalise($svg) !== $this->canonicalise($clean);

        return $clean;
    }

    /**
     * Whether the last call to sanitise() removed anything or failed to parse the markup.
     */
    public function hasIssues(): bool
    {
        return !empty($this->issues);
    }

    /**
     * Whether the last call to sanitise() removed or rejected anything. Unlike hasIssues(), this
     * also catches removals the library doesn't report, and ignores whitespace and formatting.
     */
    public function wasChanged(): bool
    {
        return $this->changed;
    }

    /**
     * @return string[] e.g. "Suspicious tag 'script' (line 3)"
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Canonical XML (C14N) of the markup, ignoring whitespace-only text, comments, the XML
     * declaration, attribute order and quoting style.
     */
    private function canonicalise(string $xml): ?string
    {
        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        // preserveWhiteSpace doesn't drop all indentation, so remove whitespace-only text explicitly
        foreach (iterator_to_array((new DOMXPath($document))->query('//text()[normalize-space(.) = ""]')) as $node) {
            $node->parentNode->removeChild($node);
        }

        return $document->C14N() ?: null;
    }

    private function formatIssues(array $issues): array
    {
        // Removed comments are harmless and very common in exported SVGs, so don't report them
        $issues = array_filter($issues, fn (array $issue) => $issue['message'] !== "Suspicious node '#comment'");

        return array_values(array_map(
            fn (array $issue) => empty($issue['line'])
                ? $issue['message']
                : sprintf('%s (line %d)', $issue['message'], $issue['line']),
            $issues
        ));
    }
}
