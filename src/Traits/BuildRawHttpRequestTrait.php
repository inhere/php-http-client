<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2018-11-23
 * Time: 18:42
 */

namespace PhpComp\Http\Client\Traits;

use PhpComp\Http\Client\ClientUtil;

/**
 * Trait BuildRawHttpRequestTrait
 * @package PhpComp\Http\Client\Traits
 */
trait BuildRawHttpRequestTrait
{
    /**
     * build raw HTTP request data string
     * @param array $info
     * @param array $headers
     * @param array $opts
     * @param $data
     * @return string
     */
    protected function buildRawHttpData(array $info, array $headers, array $opts, $data)
    {
        $uri = $info['path'];
        if ($info['query']) {
            $uri .= '?' . $info['query'];
        }

        // build cookies value
        if ($cookies = \array_merge($this->cookies, $opts['cookies'])) {
            // "Cookie: name=value; name1=value1"
            $headers['Cookie'] = \http_build_query($cookies, '', '; ');
        }

        $headers = \array_merge($this->headers, $opts['headers'], $headers);
        $headers = ClientUtil::ucwordArrayKeys($headers);

        if (!isset($headers['Host'])) {
            $headers['Host'] = $info['host'];
        }

        $method = $this->formatAndCheckMethod($opts['method']);

        // $heads[] = "Host: www.example.com\r\n";
        // $heads[] = "Connection: Close\r\n";

        $body = '';
        if ($data) {
            // allow submit body
            if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
                $body = ClientUtil::buildBodyByContentType($headers, $data);
                // add Content-length
                $headers['Content-Length'] = \strlen($body);
            } else {
                $uri = ClientUtil::buildURL($uri, $data);
            }
        }

        // close connection. if not add, will blocked.
        if (!isset($headers['Connection'])) {
            $headers['Connection'] = 'close';
        }

        $fmtHeaders = $this->formatHeaders($headers);

        // eg. "GET / HTTP/1.1\r\nHost: www.example.com\r\n\r\n"
        return \sprintf(
            "%s %s HTTP/1.1\r\n%s\r\n\r\n%s",
            $method, $uri, \implode("\r\n", $fmtHeaders), $body
        );
    }

}
