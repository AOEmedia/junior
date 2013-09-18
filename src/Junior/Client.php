<?php
namespace Junior;

use Junior\Clientside\Request,
    Junior\Clientside\Response;

class Client
{
    /**
     * Server url
     *
     * @var string
     */
    public $uri;

    /**
     * Auth header
     *
     * @var string
     */
    public $authHeader;

    /**
     * Create new client connection
     *
     * @param string $uri
     */
    public function __construct($uri)
    {
        $this->uri = $uri;
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
        $this->authHeader = "Authorization: Basic " . base64_encode("$username:$password") . "\r\n";
    }

    /**
     * Clear any existing http authentication
     */
    public function clearAuth()
    {
        $this->authHeader = null;
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
        // use http authentication header if set
        $header = "Content-Type: application/json\r\n";
        if ($this->authHeader) {
            $header .= $this->authHeader;
        }

        // prepare data to be sent
        $opts    = array(
            'http' => array(
                'method'  => 'POST',
                'header'  => $header,
                'content' => $json
            )
        );
        $context = stream_context_create($opts);

        // try to physically send data to destination
        try {
            $response = file_get_contents($this->uri, false, $context);
        } catch (\Exception $e) {
            $message = "Unable to connect to {$this->uri}";
            $message .= PHP_EOL . $e->getMessage();
            throw new Clientside\Exception($message);
        }

        // handle communication errors
        if ($response === false) {
            throw new Clientside\Exception("Unable to connect to {$this->uri}");
        }

        // notify has no response
        if ($notify) {
            return true;
        }

        // try to decode json
        $json_response = $this->decodeJSON($response);

        // handle response, create response object and return it
        return $this->handleResponse($json_response);
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
}
