<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-08
 * Time: 16:40
 */

namespace PhpComp\Http\Client\Curl;

use PhpComp\Http\Client\AbstractClient;
use PhpComp\Http\Client\ClientUtil;
use PhpComp\Http\Client\Error\ClientException;
use PhpComp\Http\Client\Traits\ParseRawResponseTrait;

/**
 * Class Curl
 * @package PhpComp\Http\Client\Curl
 *
 * ```
 * $curl = CurlClient::make([
 *   'baseUrl' =>  'http://my-site.com'
 * ]);
 * $curl->get('/users/1');
 *
 * $headers = $curl->getResponseHeaders();
 * $data = $curl->getResponseBody();
 * $array = $curl->getArrayData();
 *
 * $post = ['name' => 'john'];
 * $curl->reset()->post('/users/1', $post);
 * // $curl->reset()->byAjax()->post('/users/1', $post);
 * // $curl->reset()->byJson()->post('/users/1', json_encode($post));
 * $array = $curl->getArrayData();
 * ```
 */
class CurlClient extends AbstractClient implements CurlClientInterface
{
    use ParseRawResponseTrait;

    // ssl auth type
    const SSL_TYPE_CERT = 'cert';
    const SSL_TYPE_KEY = 'key';

    /**
     * Can to retry request
     * @var array
     */
    private static $canRetryErrorCodes = [
        \CURLE_COULDNT_RESOLVE_HOST,
        \CURLE_COULDNT_CONNECT,
        \CURLE_HTTP_NOT_FOUND,
        \CURLE_READ_ERROR,
        \CURLE_OPERATION_TIMEOUTED,
        \CURLE_HTTP_POST_ERROR,
        \CURLE_SSL_CONNECT_ERROR,
    ];

    /**************************************************************************
     * curl config data.
     *************************************************************************/

    /**
     * setting options for curl
     * @var array
     */
    private $_curlOptions = [
        // TRUE 将 curl_exec() 获取的信息以字符串返回，而不是直接输出
        \CURLOPT_RETURNTRANSFER => true,

        // 允许重定向，最多重定向5次
        \CURLOPT_FOLLOWLOCATION => true,
        \CURLOPT_MAXREDIRS => 5,

        // true curl_exec() 会将头文件的信息作为数据流输出到响应的最前面，此时可用 [[self::parseResponse()]] 解析。
        // false curl_exec() 返回的响应就只有body data
        \CURLOPT_HEADER => true,

        // enable debug
        \CURLOPT_VERBOSE => false,

        // auto add REFERER
        \CURLOPT_AUTOREFERER => true,

        \CURLOPT_CONNECTTIMEOUT => 30,
        \CURLOPT_TIMEOUT => 30,

        // isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        \CURLOPT_USERAGENT => '5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/56.0.2924.87 Safari/537.36',
        //CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
    ];

    /**
     * save request and response info, data from curl_getinfo()
     * @link https://secure.php.net/manual/zh/function.curl-getinfo.php
     * contains key:
     * "url"
     * "content_type"
     * "http_code"
     * "header_size"
     * "request_size"
     * "filetime"
     * "ssl_verify_result"
     * "redirect_count"
     * "total_time"
     * "namelookup_time"
     * "connect_time"
     * "pretransfer_time"
     * "size_upload"
     * "size_download"
     * "speed_download"
     * "speed_upload"
     * "download_content_length"
     * "upload_content_length"
     * "starttransfer_time"
     * "redirect_time"
     */
    private $_responseInfo = [];

    /**
     * {@inheritDoc}
     */
    public static function isAvailable(): bool
    {
        return \extension_loaded('curl');
    }

    /**
     * {@inheritDoc}
     */
    public function __construct(array $options = [])
    {
        // if 'debug = true ', is valid. will output log to the file. if is empty, output to STDERR.
        $this->defaultOptions['logFile'] = '';
        // collect curl_getinfo() data to $_responseInfo
        $this->defaultOptions['saveInfo'] = true;

        parent::__construct($options);
    }

///////////////////////////////////////////////////////////////////////
// extra
///////////////////////////////////////////////////////////////////////

    /**
     * File upload
     * @param string $url The target url
     * @param string $field The post field name
     * @param string $filePath The file path
     * @param string $mimeType The post file mime type
     * param string $postFilename The post file name
     * @return mixed
     */
    public function upload(string $url, string $field, string $filePath, string $mimeType = '')
    {
        if (!$mimeType) {
            $fInfo = \finfo_open(\FILEINFO_MIME); // 返回 mime 类型
            $mimeType = \finfo_file($fInfo, $filePath) ?: 'application/octet-stream';
        }

        // create file
        if (\function_exists('curl_file_create')) {
            $file = \curl_file_create($filePath, $mimeType); // , $postFilename
        } else {
            $this->setCurlOption(\CURLOPT_SAFE_UPLOAD, true);
            $file = "@{$filePath};type={$mimeType}"; // ;filename={$postFilename}
        }

        $headers = [
            'Content-Type' => 'multipart/form-data'
        ];

        return $this->post($url, [$field => $file], $headers);
    }

    /**
     * Image file download and save
     * @param string $imgUrl image url e.g. http://static.oschina.net/uploads/user/277/554046_50.jpg
     * @param string $saveDir 图片保存路径
     * @param string $rename 图片重命名(只写名称，不用后缀) 为空则使用原名称
     * @return string
     */
    public function downloadImage(string $imgUrl, string $saveDir, string $rename = '')
    {
        // e.g. http://static.oschina.net/uploads/user/277/554046_50.jpg?t=34512323
        if (\strpos($imgUrl, '?')) {
            [$real,] = \explode('?', $imgUrl, 2);
        } else {
            $real = $imgUrl;
        }

        $last = \trim(\strrchr($real, '/'), '/');

        // special url e.g http://img.blog.csdn.net/20150929103749499
        if (false === \strpos($last, '.')) {
            $suffix = '.jpg';
            $name = $rename ?: $last;
        } else {
            // $info = \pathinfo($real, \PATHINFO_EXTENSION | \PATHINFO_FILENAME);
            $info = \pathinfo($real);
            $suffix = $info['extension'] ?: '.jpg';
            $name = $rename ?: $info['filename'];
        }

        $imgFile = $saveDir . '/' . $name . $suffix;
        if (\file_exists($imgFile)) {
            return $imgFile;
        }

        // set Referrer
        // $this->setReferrer('http://www.baidu.com');
        $imgData = $this->request($imgUrl)->getResponseBody();

        \file_put_contents($imgFile, $imgData);
        return $imgFile;
    }

    /**
     * Send request
     * @param string $url
     * @param mixed $data
     * @param string $method
     * @param array $headers
     * @param array $options
     * @return $this
     */
    public function request(string $url, $data = null, string $method = 'GET', array $headers = [], array $options = [])
    {
        if ($method = \strtoupper($method)) {
            $options['method'] = $method;
        }

        $url = $this->buildFullUrl($url);
        $ch = $this->prepareRequest($url, $data, $headers, $options);

        $response = '';
        $retries = (int)$this->options['retry'];

        // execute
        while ($retries >= 0) {
            if (false === ($response = \curl_exec($ch))) {
                $curlErrNo = \curl_errno($ch);

                if (false === \in_array($curlErrNo, self::$canRetryErrorCodes, true)) {
                    $curlError = \curl_error($ch);
                    $error = \sprintf('Curl error (code %s): %s', $curlErrNo, $curlError);

                    throw new ClientException($error, $curlErrNo);
                }

                $retries--;
                continue;
            }
            break;
        }

        // if \CURLOPT_HEADER is FALSE, only return body. no headers data
        if (false === $this->getCurlOption(\CURLOPT_HEADER, false)) {
            $this->responseBody = $response;
            $this->setResponseParsed(true);
        } else {
            // if CURLOPT_HEADER is TRUE, The raw response data contains headers and body
            $this->rawResponse = $response;
            $this->parseResponse(); // parse raw response data
        }

        $this->_responseInfo = \curl_getinfo($ch);
        $this->statusCode = (int)$this->_responseInfo['http_code'];

        // close resource
        \curl_close($ch);
        return $this;
    }

///////////////////////////////////////////////////////////////////////
//   helper method
///////////////////////////////////////////////////////////////////////

    /**
     * @param string $url
     * @param mixed $data
     * @param array $headers
     * @param array $options
     * @return resource
     */
    protected function prepareRequest(string $url, $data, array $headers, array $options = [])
    {
        $this->resetResponse();

        // open debug
        if ($this->isDebug()) {
            $this->_curlOptions[\CURLOPT_VERBOSE] = true;

            // redirect exec log to logFile.
            if ($logFile = $this->options['logFile']) {
                $this->_curlOptions[\CURLOPT_STDERR] = $logFile;
            }
        }

        // merge global options.
        $options = \array_merge($this->options, $options);
        $method = $this->formatAndCheckMethod($options['method']);

        switch ($method) {
            case 'GET':
                $curlOptions[\CURLOPT_HTTPGET] = true;
                break;
            case 'POST':
                $curlOptions[\CURLOPT_POST] = true;
                break;
            case 'PUT':
                $curlOptions[\CURLOPT_PUT] = true;
                break;
            case 'HEAD':
                $curlOptions[\CURLOPT_HEADER] = true;
                $curlOptions[\CURLOPT_NOBODY] = true;
                break;
            default:
                $curlOptions[\CURLOPT_CUSTOMREQUEST] = $method;
        }

        // init curl
        $ch = \curl_init();

        // add send data
        if ($data) {
            // allow post data
            if (self::$supportedMethods[$method]) {
                \curl_setopt($ch, \CURLOPT_POSTFIELDS, $data);
            } else {
                $url = ClientUtil::buildURL($url, $data);
            }
        }

        // merge curl options, can not use `array_merge()`, $_curlOptions key is int.
        if (isset($options['curlOptions'])) {
            $curlOptions = ClientUtil::mergeArray($this->_curlOptions, $options['curlOptions']);
        } else {
            $curlOptions = $this->_curlOptions;
        }

        // set request url
        $curlOptions[\CURLOPT_URL] = ClientUtil::encodeURL($url);

        // append http headers
        if ($headers = \array_merge($this->headers, $options['headers'], $headers)) {
            $curlOptions[\CURLOPT_HTTPHEADER] = $this->formatHeaders($headers);
        }

        // append http cookies
        if ($cookies = \array_merge($this->cookies, $options['cookies'])) {
            $curlOptions[\CURLOPT_COOKIE] = \http_build_query($cookies, '', '; ');
        }

        // set cookies form file
        if ($cookieFile = $options['cookieFile'] ?? '') {
            $curlOptions[\CURLOPT_COOKIEFILE] = $cookieFile;
        }

        // 设置超时
        if ($timeout = $options['timeout'] ?? 3) {
            $curlOptions[\CURLOPT_TIMEOUT] = $timeout;
            $curlOptions[\CURLOPT_CONNECTTIMEOUT] = $timeout;
        }

        // 设置代理
        if ($proxy = $options['proxy']) {
            $curlOptions[\CURLOPT_PROXY] = $proxy['host'];
            $curlOptions[\CURLOPT_PROXYPORT] = (int)$proxy['port'];
        }

        // set options to curl handle
        \curl_setopt_array($ch, $curlOptions);
        return $ch;
    }

///////////////////////////////////////////////////////////////////////
//   response data
///////////////////////////////////////////////////////////////////////

    /**
     * @return int
     */
    public function getConnectTime(): int
    {
        return $this->_responseInfo['connect_time'] ?? 0;
    }

    /**
     * @return int
     */
    public function getTotalTime(): int
    {
        return $this->_responseInfo['total_time'] ?? 0;
    }

    /**
     * @return $this
     */
    public function resetOptions()
    {
        // $this->_curlOptions = [];

        parent::resetOptions();
        return $this;
    }

    /**
     * @return $this
     */
    public function resetResponse()
    {
        $this->rawResponse = '';
        $this->responseParsed = false;

        parent::resetResponse();
        return $this;
    }

    /**************************************************************************
     * config curl options
     *************************************************************************/

    /**
     * @param string $userAgent
     * @return $this
     */
    public function setUserAgent(string $userAgent)
    {
        $this->_curlOptions[\CURLOPT_USERAGENT] = $userAgent;
        return $this;
    }

    /**
     * @param string $referrer
     * @return $this
     */
    public function setReferrer(string $referrer)
    {
        $this->_curlOptions[\CURLOPT_REFERER] = $referrer;
        return $this;
    }

    /**
     * Use http auth
     * @param string $user
     * @param string $pwd
     * @param int $authType CURLAUTH_BASIC CURLAUTH_DIGEST
     * @return $this
     */
    public function setUserAuth(string $user, string $pwd = '', int $authType = \CURLAUTH_BASIC)
    {
        $this->_curlOptions[\CURLOPT_HTTPAUTH] = $authType;
        $this->_curlOptions[\CURLOPT_USERPWD] = "$user:$pwd";
        return $this;
    }

    /**
     * Use SSL certificate/private-key auth
     *
     * @param string $pwd The SLL CERT/KEY password
     * @param string $file The SLL CERT/KEY file
     * @param string $authType The auth type: 'cert' or 'key'
     * @return $this
     */
    public function setSSLAuth(string $pwd, string $file, string $authType = self::SSL_TYPE_CERT)
    {
        if ($authType !== self::SSL_TYPE_CERT && $authType !== self::SSL_TYPE_KEY) {
            throw new \InvalidArgumentException('The SSL auth type only allow: cert|key');
        }

        if (!\file_exists($file)) {
            $name = $authType === self::SSL_TYPE_CERT ? 'certificate' : 'private key';
            throw new \InvalidArgumentException("The SSL $name file not found: {$file}");
        }

        if ($authType === self::SSL_TYPE_CERT) {
            $this->_curlOptions[\CURLOPT_SSLCERTPASSWD] = $pwd;
            $this->_curlOptions[\CURLOPT_SSLCERT] = $file;
        } else {
            $this->_curlOptions[\CURLOPT_SSLKEYPASSWD] = $pwd;
            $this->_curlOptions[\CURLOPT_SSLKEY] = $file;
        }

        return $this;
    }

    /**
     * disable 'https' verify
     * @return $this
     */
    public function disableSSLVerify()
    {
        $this->_curlOptions[\CURLOPT_SSL_VERIFYHOST] = 0;
        $this->_curlOptions[\CURLOPT_SSL_VERIFYPEER] = false;
        return $this;
    }

    /**
     * 如果返回的内容有关乱码，可以尝试添加这个选项
     * @return $this
     */
    public function decodeGzip()
    {
        $this->_curlOptions[\CURLOPT_ENCODING] = 'gzip';
        return $this;
    }

    /**
     * true curl_exec() 会将头文件的信息作为数据流输出到响应的最前面，此时会用 [[parseResponse()]] 解析。
     * false curl_exec() 返回的响应就只有 body data，此时无法得到响应的headers数据
     * @return $this
     */
    public function onlyReturnBody()
    {
        $this->_curlOptions[\CURLOPT_HEADER] = false;
        return $this;
    }

    /**
     * disable 'https' verify
     * @return $this
     */
    public function enableSSLVerify()
    {
        $this->_curlOptions[\CURLOPT_SSL_VERIFYHOST] = 2;
        $this->_curlOptions[\CURLOPT_SSL_VERIFYPEER] = true;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setCurlOptions(array $options)
    {
        $this->_curlOptions = array_merge($this->_curlOptions, $options);
        return $this;
    }

    /**
     * @param int $name \CURLOPT_*
     * @param $value
     * @return $this
     */
    public function setCurlOption($name, $value)
    {
        $this->_curlOptions[$name] = $value;
        return $this;
    }

    /**
     * @return array
     */
    public function getCurlOptions(): array
    {
        return $this->_curlOptions;
    }

    /**
     * @param int|string $name
     * @param bool $default
     * @return mixed
     */
    public function getCurlOption($name, $default = null)
    {
        return $this->_curlOptions[$name] ?? $default;
    }

}
