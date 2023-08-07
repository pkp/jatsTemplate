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

use APP\core\Services;
use APP\facades\Repo;
use APP\submission\Submission;
use PKP\config\Config;
use PKP\core\PKPString;
use PKP\search\SearchFileParser;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class ArticleBody extends \DOMDocument
{
    /**
     * create xml body DOMNode
     * @param Submission $submission
     * @return \DOMNode
     */
    public function create(Submission $submission):\DOMNode
    {
        // create element body
        $bodyElement = $this->appendChild($this->createElement('body'));
        $text = '';
        $galleys = $submission->getGalleys();

        // Give precedence to HTML galleys, as they're quickest to parse
        usort($galleys, function($a, $b) {
            return $a->getFileType() == 'text/html'?-1:1;
        });

        // Provide the full-text.
        $fileService = Services::get('file');
        foreach ($galleys as $galley) {
            $galleyFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
            if (!$galleyFile) continue;

            $filepath = $fileService->get($galleyFile->getData('fileId'))->path;
            $mimeType = $fileService->fs->mimeType($filepath);
            if (in_array($mimeType, ['text/html'])) {
                static $sanitizer = null;
                if (!$sanitizer) {
                    $sanitizer = new HTMLSanitizer(
                        (new HtmlSanitizerConfig())->allowElement('p')
                    );
                }
                // Remove non-paragraph content
                $text = PKPString::sanitizeHtmlString(
                    file_get_contents(Config::getVar('files', 'files_dir') . '/' . $filepath),
                    'p',
                    $sanitizer
                );
                
                // Remove empty paragraphs
            } else {
                $parser = SearchFileParser::fromFile($galleyFile);
                if ($parser && $parser->open()) {
                    while(($s = $parser->read()) !== false) $text .= $s;
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
            if (!empty($text)) break;
        }

        return $bodyElement;
    }
}
