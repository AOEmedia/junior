<?php
namespace Junior;

use Junior\Clientside\Request,
    Junior\Clientside\Response;

class Client
{
    /**
     * Server domain name
     *
     * @var string
     */
    protected $_serverDomain;

    /**
     * Server path
     *
     * @var string
     */
    protected $_serverPath;

    /**
     * Server port
     *
     * @var int
     */
    protected $_serverPort = 80;

    /**
     * Auth header
     *
     * @var string
     */
    protected $_authHeader;

    /**
     * Timeout for the request in seconds
     *
     * @var int
     */
    protected $_timeOut = 60;

    /**
     * Create new client connection
     *
     * @param string $domain
     * @param int $port
     * @param string $path
     */
    public function __construct($domain, $port = 80, $path = '')
    {
        $this->_serverDomain = (string)$domain;
        $this->_serverPort   = (int)$port;
        $this->_serverPath   = (string)$path;
    }

    /**
     * Shortcut to call a single, non-notification request
     *
     * @param string $method
     * @param mixed $params
     * @return Response
     */
    public function __call($method, $params)
    {
        return $this->sendRequest(new Request($method, $params));
    }

    /**
     * Set basic http authentication
     *
     * @param string $username
     * @param string $password
     */
    public function setBasicAuth($username, $password)
    {
        $this->_authHeader = "Authorization: Basic " . base64_encode("$username:$password") . "\r\n";
    }

    /**
     * Set timeout for request in seconds
     *
     * @param int $timeOut
     */
    public function setTimeOut($timeOut)
    {
        $this->_timeOut = (int)$timeOut;
    }

    /**
     * Clear any existing http authentication
     */
    public function clearAuth()
    {
        $this->_authHeader = null;
    }

    /**
     * Send a single request object
     *
     * @param Request $request
     * @return array|bool|Response
     * @throws Clientside\Exception
     */
    public function sendRequest(Request $request)
    {
        $response = $this->send($request->getJSON());

        if ($response->id != $request->id) {
            throw new Clientside\Exception("Mismatched request id");
        }

        return $response;
    }

    /**
     * Send a single notify request object
     *
     * @param Request $request
     * @return bool
     * @throws Clientside\Exception
     */
    public function sendNotify(Request $request)
    {
        if (property_exists($request, 'id') && $request->id != null) {
            throw new Clientside\Exception("Notify requests must not have ID set");
        }

        $this->send($request->getJSON(), true);

        return true;
    }

    /**
     * Send an array of request objects as a batch
     *
     * @param Request[] $requests
     * @return array|bool
     * @throws Clientside\Exception
     */
    public function sendBatch(array $requests)
    {
        $arr        = array();
        $ids        = array();
        $all_notify = true;
        foreach ($requests as $req) {
            if ($req->id) {
                $all_notify = false;
                $ids[]      = $req->id;
            }
            $arr[] = $req->getArray();
        }
        $response = $this->send(json_encode($arr), $all_notify);

        // no response if batch is all notifications
        if ($all_notify) {
            return true;
        }

        // check for missing ids and return responses in order of requests
        $orderedResponse = array();
        foreach ($ids as $id) {
            if (array_key_exists($id, $response)) {
                $orderedResponse[] = $response[$id];
                unset($response[$id]);
            } else {
                throw new Clientside\Exception("Missing id in response");
            }
        }

        // check for extra ids in response
        if (count($response) > 0) {
            throw new Clientside\Exception("Extra id(s) in response");
        }

        return $orderedResponse;
    }

    /**
     * Send raw json to the server
     *
     * @param string $json
     * @param bool $notify
     * @return array|bool|Response
     * @throws Clientside\Exception
     */
    public function send($json, $notify = false)
    {
        // try to physically send data to destination
        try {
            $response = $this->_doRequest($json);
        } catch (\Exception $e) {
            $message = "Unable to connect to {$this->_serverPath}";
            $message .= PHP_EOL . $e->getMessage();
            throw new Clientside\Exception($message);
        }

        // notify has no response
        if ($notify) {
            return true;
        }

        // try to decode json
        $jsonResponse = $this->decodeJSON($response);

        // handle response, create response object and return it
        return $this->handleResponse($jsonResponse);
    }

    /**
     * @param string $json
     * @return string
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    protected function _doRequest($json)
    {
        $streamHandle = $this->_openConnection($json);

        $response = '';
        $info     = stream_get_meta_data($streamHandle);
        while (!feof($streamHandle) && !$info['timed_out']) {
            $response .= fgets($streamHandle, 4096);
            $info = stream_get_meta_data($streamHandle);
        }
        fclose($streamHandle);

        if ($info['timed_out']) {
            throw new \RuntimeException("Connection timed out");
        } else {
            $parts = explode("\r\n\r\n", $response);
            // strip headers
            array_shift($parts);

            return trim(implode("\r\n\r\n", $parts));
        }
    }

    /**
     * Create connection to server via socket
     *
     * @param string $json
     * @return resource
     * @throws \InvalidArgumentException
     */
    protected function _openConnection($json)
    {
        $streamHandle = fsockopen($this->_serverDomain, $this->_serverPort, $errorCode, $errorMessage, $this->_timeOut);
        if ($streamHandle === false) {
            throw new \InvalidArgumentException(sprintf("Unable to connect: [%d] %s", $errorCode, $errorMessage));
        }

        fwrite($streamHandle, "POST {$this->_serverPath} HTTP/1.0\r\n");
        fwrite($streamHandle, "Host: {$this->_serverDomain}\r\n");
        fwrite($streamHandle, "Content-Type: application/json\r\n");
        // use http authentication header if set
        if ($this->_authHeader) {
            fwrite($streamHandle, $this->_authHeader);
        }
        fwrite($streamHandle, "Content-Length: " . mb_strlen($json) . "\r\n");
        fwrite($streamHandle, "Connection: close\r\n");
        fwrite($streamHandle, "\r\n");

        // set timeOut for request
        stream_set_timeout($streamHandle, $this->_timeOut);

        fwrite($streamHandle, $json);

        return $streamHandle;
    }

    /**
     * Decode json throwing exception if unable
     *
     * @param string $json
     * @return mixed
     * @throws Clientside\Exception
     */
    public function decodeJSON($json)
    {
        $json_response = json_decode($json);
        if ($json_response === null) {
            throw new Clientside\Exception("Unable to decode JSON response from: {$json}");
        }

        return $json_response;
    }

    /**
     * Handle the response and return a result or an error
     *
     * @param mixed $response
     * @return array|Response
     */
    public function handleResponse($response)
    {
        // recursion for batch
        if (is_array($response)) {
            $responseArray = array();
            foreach ($response as $res) {
                $responseArray[$res->id] = $this->handleResponse($res);
            }

            return $responseArray;
        }

        // return error response
        if (property_exists($response, 'error')) {
            return new Response(null, $response->id, $response->error->code, $response->error->message);
        }

        // return successful response
        return new Response($response->result, $response->id);
    }

    /**
     * @return string
     */
    public function getAuthHeader()
    {
        return $this->_authHeader;
    }
}
