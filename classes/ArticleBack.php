<?php

/**
 * @file ArticleBack.php
 *
 * Copyright (c) 2003-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article back element
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use PKP\db\DAORegistry;

class ArticleBack extends \DOMDocument
{
    /**
     * create xml back DOMNode
     */
    public function create($publication): \DOMNode
    {
        // create element back
        $backElement = $this->appendChild($this->createElement('back'));

        $citationDao = DAORegistry::getDAO('CitationDAO');
        $citations = $citationDao->getByPublicationId($publication->getId())->toArray();
        if (count($citations)) {
            // create element ref-list
            $refListElement = $backElement->appendChild($this->createElement('ref-list'));
            $i = 1;
            foreach ($citations as $citation) {
                // create element ref
                $refListElement
                    ->appendChild($this->createElement('ref'))
                    ->setAttribute('id', 'R' . $i)
                    ->parentNode
                    ->appendChild($this->createElement('mixed-citation', htmlspecialchars($citation->getRawCitation())));
                $i++;
            }
        }

        return $backElement;
    }
}
