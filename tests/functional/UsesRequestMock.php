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

use APP\core\Request;
use APP\journal\Journal;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\core\PKPRouter;
use PKP\core\Dispatcher;
use PKP\core\Registry;

trait UsesRequestMock
{
    /*
     * create mock request
     */
    protected function createRequestMockInstance()
    {
        $journal = new Journal();

        /** @var PKPRouter|MockObject */
        $dispatcher = $this->getMockBuilder(Dispatcher::class)
            ->onlyMethods(['url', 'getApplication'])
            ->getMock();
        $dispatcher->expects($this->any())
            ->method('url')
            ->willReturnCallback(fn ($request, $newContext = null, $handler = null, $op = null, $path = null) => $handler . '-' . $op . '-' . $path);

        $router = $this->getMockBuilder(PKPRouter::class)
            ->onlyMethods(['url', 'handleAuthorizationFailure', 'route', 'getDispatcher', 'getContext'])
            ->getMock();

        $router->expects($this->any())
            ->method('getDispatcher')
            ->willReturn($dispatcher);

        $router->expects($this->any())
            ->method('getContext')
            ->willReturn($journal);

        // Request
        $requestMock = $this->getMockBuilder(Request::class)
            ->onlyMethods(['getRouter', 'getContext'])
            ->getMock();
        $requestMock->expects($this->any())
            ->method('getRouter')
            ->willReturn($router);
        $requestMock->expects($this->any())
            ->method('getContext')
            ->willReturn($journal);

        Registry::set('request', $requestMock);
        return $requestMock;
    }
}
