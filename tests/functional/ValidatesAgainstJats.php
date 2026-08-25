<?php

/**
 * @file ValidatesAgainstJats.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Shared JATS DTD validation assertion for this plugin's tests
 */

namespace APP\plugins\generic\jatsTemplate\tests\functional;

use DOMDocument;
use DOMImplementation;
use PKP\core\Core;

trait ValidatesAgainstJats
{
    private const JATS_12_PUBLIC_ID = '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN';
    private const JATS_12_DTD_PATH = '/dtd/jats/1.2/JATS-journalpublishing1.dtd';

    /**
     * Assert that a generated document is valid against the JATS 1.2 DTD.
     */
    protected function assertXmlValidatesAgainstJats12(DOMDocument $dom): void
    {
        $impl = new DOMImplementation();
        $dtd = $impl->createDocumentType('article', self::JATS_12_PUBLIC_ID, $this->getJats12DtdPath());

        $validationDoc = $impl->createDocument(null, '', $dtd);
        $validationDoc->encoding = 'UTF-8';
        $validationDoc->appendChild($validationDoc->importNode($dom->documentElement, true));

        libxml_use_internal_errors(true);
        $isValid = $validationDoc->validate();
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $errorMessage = '';
        foreach ($errors as $error) {
            $errorMessage .= sprintf("\nLine %d: %s", $error->line, trim($error->message));
        }

        self::assertTrue($isValid, 'JATS 1.2 DTD Validation failed:' . $errorMessage);
    }

    /**
     * Get a file: URI for the DTD bundled with the application, so that validation
     * resolves the DTD and its modules locally instead of over the network.
     */
    private function getJats12DtdPath(): string
    {
        $path = Core::getBaseDir() . self::JATS_12_DTD_PATH;
        self::assertFileExists($path, 'The bundled JATS 1.2 DTD is missing; expected it at ' . self::JATS_12_DTD_PATH);

        return 'file://' . $path;
    }
}
