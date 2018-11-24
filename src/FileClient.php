<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2018-11-23
 * Time: 19:29
 */

namespace PhpComp\Http\Client;

use PhpComp\Http\Client\Error\ClientException;
use PhpComp\Http\Client\Traits\RawResponseParserTrait;
use PhpComp\Http\Client\Traits\StreamContextBuildTrait;

/**
 * Class FileClient - powered by func file_get_contents()
 * @package PhpComp\Http\Client
 */
class FileClient extends AbstractClient
{
    use StreamContextBuildTrait, RawResponseParserTrait;

    /**
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return \function_exists('file_get_contents');
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $url, $data = null, string $method = self::GET, array $headers = [], array $options = [])
    {
        if ($method) {
            $options['method'] = \strtoupper($method);
        }

        // get request url info
        $url = $this->buildUrl($url);

        // merge global options data.
        $options = \array_merge($this->options, $options);

        try {
            $ctx = $this->buildStreamContext($url, $headers, $options, $data);
            $fullUrl = ClientUtil::encodeURL($this->fullUrl);

            // send request
            $this->responseBody = \file_get_contents($fullUrl, false, $ctx);

            // false is failure
            if ($this->responseBody === false) {
                $this->responseBody = '';
            }
        } catch (\Throwable $e) {
            throw new ClientException($e->getMessage(), $e->getCode(), $e);
        }

        /**
         * $http_response_header will auto save HTTP response headers data.
         * @see https://secure.php.net/manual/zh/reserved.variables.httpresponseheader.php
         */
        if ($http_response_header !== null) {
            $this->parseResponseHeaders($http_response_header);
        }

        return $this;
    }
}
