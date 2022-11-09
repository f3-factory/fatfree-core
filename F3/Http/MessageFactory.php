<?php

/**
 *
 * Copyright (c) 2022 F3::Factory, All rights reserved.
 *
 * This file is part of the Fat-Free Framework (http://fatfreeframework.com).
 *
 * This is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or later.
 *
 * Fat-Free Framework is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with Fat-Free Framework. If not, see <http://www.gnu.org/licenses/>.
 */

namespace F3\Http;

use F3\Base;
use F3\Prefab;

/**
 * PSR-17 Http Message factory
 */
class MessageFactory {

    use Prefab;

    function __construct(
        protected Base $f3,
    ) { }

    /**
     * register class bindings to container
     */
    public function register(
        string $requestFactory,
        string $responseFactory,
        string $serverRequestFactory,
        string $uploadedFileFactory,
        string $uriFactory,
        string $streamFactory,
    ): void {
        $container = $this->f3->CONTAINER;
        $container->set('Psr\Http\Message\RequestFactoryInterface', $requestFactory);
        $container->set('Psr\Http\Message\ResponseFactoryInterface', $responseFactory);
        $container->set('Psr\Http\Message\ServerRequestFactoryInterface', $serverRequestFactory);
        $container->set('Psr\Http\Message\UploadedFileFactoryInterface', $uploadedFileFactory);
        $container->set('Psr\Http\Message\UriFactoryInterface', $uriFactory);
        $container->set('Psr\Http\Message\StreamFactoryInterface', $streamFactory);
    }

    /**
     * register Request creation shortcut
     */
    public function registerRequest(string $class): void {
        $this->f3->CONTAINER->set($class, fn() => $this->makeRequest());
    }

    /**
     * register ServerRequest creation shortcut
     */
    public function registerServerRequest(string $class): void {
        $this->f3->CONTAINER->set($class, fn() => $this->makeServerRequest());
    }

    /**
     * register Response creation shortcut
     */
    public function registerResponse(string $class): void {
        $this->f3->CONTAINER->set($class, fn() => $this->f3
            ->CONTAINER->get('Psr\Http\Message\ResponseFactoryInterface')
            ->createResponse());
    }

    /**
     * common request builder
     */
    protected function buildRequest(object $request): object {
        foreach ($this->f3->HEADERS as $key => $value) {
            $request = $request->withHeader($key,
                array_map('trim',explode(',',$value)));
        }
        if (!$this->f3->CLI) {
            list(,$version) = explode('/',$this->f3->SERVER['SERVER_PROTOCOL']);
            $request = $request->withProtocolVersion($version);
        }
        /** @var \Psr\Http\Message\StreamFactoryInterface $sf */
        $sf = $this->f3->make('Psr\Http\Message\StreamFactoryInterface');
        if ($this->f3->RAW || $this->f3->BODY) {
            if ($this->f3->RAW && !$this->f3->BODY) {
                $res = fopen('php://input','r');
                $stream = $sf->createStreamFromResource($res);
            }
            if ($this->f3->BODY)
                $stream = $sf->createStream($this->f3->BODY);
            if (isset($stream))
                $request = $request->withBody(new Stream($this->f3->BODY));
        }
        return $request;
    }

    /**
     * receive PSR-7 request object
     * @return \Psr\Http\Message\RequestInterface
     */
    public function makeRequest(): object {
        /** @var \Psr\Http\Message\RequestFactoryInterface $factory */
        $factory = $this->f3->make('Psr\Http\Message\RequestFactoryInterface');
        $request = $factory->createRequest($this->f3->VERB,$this->f3->REALM);
        return $this->buildRequest($request);
    }

    /**
     * receive PSR-7 server request object
     * @return \Psr\Http\Message\ServerRequestInterface
     */
    public function makeServerRequest(): object {
        /** @var \Psr\Http\Message\ServerRequestFactoryInterface $factory */
        $factory = $this->f3->make('Psr\Http\Message\ServerRequestFactoryInterface');
        $request = $factory->createServerRequest($this->f3->VERB,$this->f3->REALM,$this->f3->SERVER);
        $request = $this->buildRequest($request)
            ->withCookieParams($this->f3->COOKIE)
            ->withQueryParams($this->f3->GET);
        /** @var \Psr\Http\Message\StreamFactoryInterface $sf */
        $sf = $this->f3->make('Psr\Http\Message\StreamFactoryInterface');
        if ($this->f3->FILES) {
            /** @var \Psr\Http\Message\UploadedFileFactoryInterface $uff */
            $uff = $this->f3->make('Psr\Http\Message\UploadedFileFactoryInterface');
            $fetch = function($arr) use (&$fetch) {
                if (!is_array($arr))
                    return [$arr];
                $data = [];
                foreach ($arr as $sub)
                    $data = array_merge($data,$fetch($sub));
                return $data;
            };
            $out = [];
            foreach ($this->f3->FILES as $item) {
                $files = [];
                foreach ($item as $k => $mix)
                    foreach ($fetch($mix) as $i => $val)
                        $files[$i][$k] = $val;
                foreach ($files as $file) {
                    if (!empty($file['name']))
                        $out[] = $uff->createUploadedFile(
                            $sf->createStreamFromFile($file['tmp_name']),
                            $file['size'],
                            $file['error'],
                            $file['name'],
                            $file['type']
                        );
                }
            }
            if ($out)
                $request = $request->withUploadedFiles($out);
        }
        return $request;
    }
}
