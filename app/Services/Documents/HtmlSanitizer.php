<?php

namespace App\Services\Documents;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Nettoyage du contenu riche des fiches (liste blanche stricte, composant
 * symfony/html-sanitizer). Appliqué à l'enregistrement ET à l'affichage :
 * scripts, gestionnaires d'événements, styles, iframes et liens
 * "javascript:" sont systématiquement supprimés.
 */
class HtmlSanitizer
{
    private const MAX_LENGTH = 2_000_000;

    private ?SymfonySanitizer $sanitizer = null;

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return $this->sanitizer()->sanitize($html);
    }

    /**
     * Texte brut dérivé du HTML, pour la recherche et les extraits.
     */
    public function toPlainText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Espaces entre les blocs pour éviter de coller les mots.
        $spaced = (string) preg_replace('/<(\/p|br\s*\/?|\/h[1-6]|\/li|\/td|\/th|\/tr|\/pre|\/blockquote)>/i', '$0 ', $html);
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function sanitizer(): SymfonySanitizer
    {
        if ($this->sanitizer !== null) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('code')
            ->allowElement('pre')
            ->allowElement('blockquote')
            ->allowElement('ul')
            ->allowElement('ol', ['start'])
            ->allowElement('li')
            ->allowElement('hr')
            ->allowElement('a', ['href', 'title'])
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('colgroup')
            ->allowElement('col')
            // Titres de niveau 1 convertis : le titre de la fiche est le seul h1.
            ->blockElement('h1')
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withMaxInputLength(self::MAX_LENGTH);

        return $this->sanitizer = new SymfonySanitizer($config);
    }
}
