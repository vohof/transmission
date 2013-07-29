<?php namespace Vohof;

use Guzzle\Http\Exception\ClientErrorResponseException;

class GuzzleClient extends ClientAbstract {

    protected $lastRequest;
    protected $retries = 0;
    protected $maxRetries = 5;

    public function __construct($host, $options = array())
    {
        $this->setVendorClient(new \Guzzle\Http\Client($host, $options));
    }

    public function request($method, $params = array())
    {
        $this->lastRequest = func_get_args();
        try
        {
            $req = $this->client->post($this->endpoint, null, json_encode(array(
                'method' => $method,
                'arguments' => $params
            )));

            $res = json_decode($req->send()->getBody(true), true);

            if (is_null($res))
            {
                throw TransmissionBadJsonException::factory(
                    'The response from RPC server is invalid.',
                    $req,
                    $res
                );
            }

            if ($res['result'] != 'success')
            {
                throw TransmissionResponseException::factory(
                    "The RPC server did not return a success result flag: ${res['result']}",
                    $req,
                    $res
                );
            }

            if ( ! isset($res['arguments']))
            {
                throw TransmissionResponseException::factory(
                    "The RPC server did not return any arguments.",
                    $req,
                    $res
                );
            }

            return $res['arguments'];
        }
        catch (ClientErrorResponseException $e)
        {
            $response = $e->getResponse();
            $errorCode = $response->getStatusCode();

            if ($errorCode == 409)
            {
                if ( ! $response->hasHeader('X-Transmission-Session-Id'))
                {
                    throw new TransmissionSessionException('No X-Transmission-Session-Id header found.');
                }

                $sessionId = $response->getHeader('X-Transmission-Session-Id');

                $this->client->setDefaultOption(
                    'headers/X-Transmission-Session-Id', $sessionId
                );

                $this->retries++;

                if ($this->retries > $this->maxRetries)
                {
                    throw new TransmissionSessionException('Transmission doesn\'t like our session Id.');
                }

                return call_user_func_array(
                    array($this, 'request'),
                    $this->lastRequest
                );
            }

            throw $e;
        }
    }
}

class TransmissionBadJsonException extends GuzzleException {}
class TransmissionResponseException extends GuzzleException {}
class TransmissionSessionException extends \Exception {}
