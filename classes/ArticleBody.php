<?php

/**
 * @file ArticleBody.php
 *
 * Copyright (c) 2003-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article body element
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use APP\facades\Repo;
use APP\submission\Submission;
use PKP\config\Config;
use PKP\galley\Galley;
use PKP\search\SearchFileParser;

class ArticleBody extends \DOMDocument
{
    /**
     * create xml body DOMNode
     * @param Submission $submission
     * @return \DOMNode
     */
    public function create(Submission $submission): \DOMNode
    {
        // create element body
        $bodyElement = $this->appendChild($this->createElement('body'));
        $text = '';
        $galleys = $submission->getCurrentPublication()->getData('galleys');

        // Get HTML galleys for top of list, as they're quickest to parse
        // PDFs have second-highest priority over other file types
        $items = $galleys->reduce(function (array $carry, Galley $galley) {
            $fileType = $galley->getFileType();

            switch ($fileType) {
                case 'text/html':
                    $carry['html'][] = $galley;
                    break;
                case 'application/pdf':
                    $carry['pdf'][] = $galley;
                    break;
                default:
                    $carry['other'][] = $galley;
            }
            return $carry;
        }, ['html' => [], 'pdf' => [], 'other' => []]);
        $galleys = array_merge($items['html'], $items['pdf'], $items['other']);
        // Provide the full-text.
        $fileService = app()->get('file');
        foreach ($galleys as $galley) {
            $galleyFile = Repo::submissionFile()->get((int) $galley->getData('submissionFileId'));
            if (!$galleyFile) {
                continue;
            }

            $filepath = $fileService->get($galleyFile->getData('fileId'))->path;
            $mimeType = $fileService->fs->mimeType($filepath);
            if (in_array($mimeType, ['text/html'])) {
                static $purifier;
                if (!$purifier) {
                    $config = \HTMLPurifier_Config::createDefault();
                    $config->set('HTML.Allowed', 'p');
                    $config->set('Cache.SerializerPath', 'cache');
                    $purifier = new \HTMLPurifier($config);
                }
                // Remove non-paragraph content
                $text = $purifier->purify(file_get_contents(Config::getVar('files', 'files_dir') . '/' . $filepath));

                // Remove empty paragraphs
            } else {
                $parser = SearchFileParser::fromFile($galleyFile);
                if ($parser && $parser->open()) {
                    while (($s = $parser->read()) !== false) {
                        $text .= $s;
                    }
                    $parser->close();
                }
                if (!empty($text)) {
                    // create element p
                    $bodyElement
                        ->appendChild(
                            $this->createElement('p', htmlspecialchars($text, ENT_IGNORE))
                        );
                }
            }
            // Use the first parseable galley.
            if (!empty($text)) {
                break;
            }
        }

        return $bodyElement;
    }
}
