<?php

/**
 * @file Article.php
 *
 * Copyright (c) 2003-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article
 */


namespace APP\plugins\generic\jatsTemplate\classes;

use APP\core\Application;
use DOMException;

class Article extends \DOMDocument
{
    function __construct($record)
    {
        parent::__construct('1.0', 'UTF-8');
        $this->convertToXml($record);
    }

    /**
     * @param $record
     * @return bool|\DOMDocument
     */
    public function convertToXml($record) :bool|\DOMDocument
    {
        $submission = $record->getData('article');
        $journal = $record->getData('journal');
        $section = $record->getData('section');
        $issue = $record->getData('issue');
        $publication = $submission->getCurrentPublication();


        $request = Application::get()->getRequest();
        // create element front
        $articleFront = new ArticleFront();
        $frontElement = $articleFront->create($journal,$submission,$section,$issue,$request,$this);
        // create element body
        $articleBody = new ArticleBody();
        $bodyElement = $articleBody->create($submission);
        // create element back
        $articleBack = new ArticleBack();
        $backElement = $articleBack->create($publication);
        //append element front,body,back to element article
       $article = $this->appendChild($this->createElement('article'))
                ->setAttribute('xmlns:xlink','http://www.w3.org/1999/xlink')
                ->parentNode
                ->setAttribute('xml:lang',substr($submission->getLocale()=== null?'':$submission->getLocale(), 0, 2))
                ->parentNode
                ->setAttribute('xmlns:mml','http://www.w3.org/1998/Math/MathML')
                ->parentNode
                ->setAttribute('xmlns:xsi','http://www.w3.org/2001/XMLSchema-instance')
                ->parentNode
                ->appendChild($this->importNode($frontElement,true))
                ->parentNode
                ->appendChild($this->importNode($bodyElement,true))
                ->parentNode
                ->appendChild($this->importNode($backElement,true))
                ->parentNode;
        return $this->loadXml($this->saveXML($article));
    }


    /**
     * Map the specific HTML tags in title/ sub title for JATS schema compability
     * @see https://jats.nlm.nih.gov/publishing/0.4/xsd/JATS-journalpublishing0.xsd
     *
     * @param  string $htmlTitle The submission title/sub title as in HTML
     * @return string
     */
    public function mapHtmlTagsForTitle(string $htmlTitle): string
    {
        $mappings = [
            '<b>' 	=> '<bold>',
            '</b>' 	=> '</bold>',
            '<i>' 	=> '<italic>',
            '</i>' 	=> '</italic>',
            '<u>' 	=> '<underline>',
            '</u>' 	=> '</underline>',
        ];

        return str_replace(array_keys($mappings), array_values($mappings), $htmlTitle);
    }
}
