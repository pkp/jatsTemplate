<?php

/**
 * @file UsesRequestMock.php
 *
 * Copyright (c) 2003-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Tools to mock requests etc. for this plugin
 */

namespace APP\plugins\generic\jatsTemplate\tests\functional;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\jatsTemplate\classes\Article;
use APP\plugins\generic\jatsTemplate\classes\ArticleFront;
use APP\publication\Publication;
use APP\section\Section;
use APP\submission\Submission;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\core\PKPRouter;
use PKP\core\Dispatcher;
use PKP\doi\Doi;
use PKP\galley\Galley;
use PKP\oai\OAIRecord;

trait UsesRequestMock
{
    /*
     * create mock request
     */
    protected function createRequestMockInstance(){
        $journal = new \APP\journal\Journal();

        /** @var PKPRouter|MockObject */
        $dispatcher = $this->getMockBuilder(Dispatcher::class)
            ->onlyMethods(['url', 'getApplication'])
            ->getMock();
        $dispatcher->expects($this->any())
            ->method('url')
            ->will($this->returnCallback(fn ($request, $newContext = null, $handler = null, $op = null, $path = null) => $handler . '-' . $op . '-' . $path));

        $router = $this->getMockBuilder(PKPRouter::class)
            ->onlyMethods(['url', 'handleAuthorizationFailure', 'route', 'getDispatcher', 'getContext'])
            ->getMock();

        $router->expects($this->any())
            ->method('getDispatcher')
            ->will($this->returnValue($dispatcher));

        $router->expects($this->any())
            ->method('getContext')
            ->will($this->returnValue($journal));

        // Request
        $requestMock = $this->getMockBuilder(Request::class)
            ->onlyMethods(['getRouter', 'getContext'])
            ->getMock();
        $requestMock->expects($this->any())
            ->method('getRouter')
            ->will($this->returnValue($router));
        $requestMock->expects($this->any())
            ->method('getContext')
            ->will($this->returnValue($journal));

        \PKP\core\Registry::set('request', $requestMock);
        return $requestMock;
    }
}

