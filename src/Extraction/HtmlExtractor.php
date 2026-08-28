<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use Sifrious\Aleph\Web\FetchResult;

final readonly class HtmlExtractor implements MechanicalExtractor
{
    public function format(): ObservationFormat
    {
        return ObservationFormat::Html;
    }

    public function name(): string
    {
        return 'aleph.html';
    }

    public function version(): string
    {
        return '1';
    }

    public function extract(FetchResult $observation): MechanicalExtraction
    {
        $body = $observation->body ?? '';

        if (trim($body) === '') {
            return $this->extraction('', []);
        }

        $document = $this->document($body);
        $discoveries = $this->discoveries($document);
        $text = preg_replace('/\s+/u', ' ', $document->textContent);

        return $this->extraction(trim(mb_scrub($text ?? '', 'UTF-8')), $discoveries);
    }

    /**
     * @param  list<DiscoveredReference>  $discoveries
     */
    private function extraction(string $text, array $discoveries): MechanicalExtraction
    {
        return new MechanicalExtraction(
            $this->format(),
            $this->name(),
            $this->version(),
            [
                'classification' => $this->format()->value,
                'text' => $text,
                'reference_count' => count($discoveries),
            ],
            $discoveries,
        );
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new RuntimeException('The HTML observation could not be parsed.');
        }

        return $document;
    }

    /**
     * @return list<DiscoveredReference>
     */
    private function discoveries(DOMDocument $document): array
    {
        $nodes = (new DOMXPath($document))->query('//a[@href] | //iframe[@src] | //embed[@src] | //object[@data]');

        if ($nodes === false) {
            throw new RuntimeException('The HTML discovery query could not be evaluated.');
        }

        $discoveries = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            [$attribute, $relationship] = $this->discoveryShape($node);
            $reference = trim($node->getAttribute($attribute));

            if ($reference !== '') {
                $discoveries[] = new DiscoveredReference($reference, $relationship);
            }
        }

        return $discoveries;
    }

    /**
     * @return array{string, DiscoveryRelationship}
     */
    private function discoveryShape(DOMNode $node): array
    {
        return match ($node->nodeName) {
            'a' => ['href', DiscoveryRelationship::Link],
            'iframe' => ['src', DiscoveryRelationship::Iframe],
            'embed' => ['src', DiscoveryRelationship::Embed],
            default => ['data', DiscoveryRelationship::Embed],
        };
    }
}
