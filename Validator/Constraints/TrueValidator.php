<?php

namespace EWZ\Bundle\RecaptchaBundle\Validator\Constraints;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ValidatorException;

class TrueValidator extends ConstraintValidator
{
    protected $cache;

    /**
     * Enable recaptcha?
     *
     * @var Boolean
     */
    protected $enabled;

    /**
     * Recaptcha Private Key
     *
     * @var Boolean
     */
    protected $privateKey;

    /**
     * Request Stack
     *
     * @var \Symfony\Component\HttpFoundation\Request
     */
    protected $request;

    /**
     * The reCAPTCHA server URL's
     */
    const RECAPTCHA_VERIFY_SERVER = 'www.google.com';

    /**
     * Construct.
     *
     * @param ContainerInterface $container An ContainerInterface instance
     */
    public function __construct($enabled, $privateKey, Request $request)
    {
        $this->enabled = $enabled;
        $this->privateKey = $privateKey;
        $this->request = $request;
    }

    /**
     * {@inheritdoc}
     */
    public function validate($value, Constraint $constraint)
    {
        // if recaptcha is disabled, always valid
        if (!$this->enabled) {
            return true;
        }

        // define variable for recaptcha check answer
        $remoteip   = $this->request->server->get('REMOTE_ADDR');
        $challenge  = $this->request->get('recaptcha_challenge_field');
        $response   = $this->request->get('recaptcha_response_field');

        if (
            isset($this->cache[$this->privateKey]) &&
            isset($this->cache[$this->privateKey][$remoteip]) &&
            isset($this->cache[$this->privateKey][$remoteip][$challenge]) &&
            isset($this->cache[$this->privateKey][$remoteip][$challenge][$response])
        ) {
            $cached = $this->cache[$this->privateKey][$remoteip][$challenge][$response];
        } else {
            $cached = $this->cache[$this->privateKey][$remoteip][$challenge][$response] = $this->checkAnswer($this->privateKey, $remoteip, $challenge, $response);
        }

        if (!$cached) {
            $this->context->addViolation($constraint->message);
        }
    }

    /**
      * Calls an HTTP POST function to verify if the user's guess was correct
      *
      * @param string $privateKey
      * @param string $remoteip
      * @param string $challenge
      * @param string $response
      * @param array $extra_params an array of extra variables to post to the server
      *
      * @throws ValidatorException When missing remote ip
      *
      * @return Boolean
      */
    private function checkAnswer($privateKey, $remoteip, $challenge, $response, $extra_params = array())
    {
        if ($remoteip == null || $remoteip == '') {
            throw new ValidatorException('For security reasons, you must pass the remote ip to reCAPTCHA');
        }

        // discard spam submissions
        if ($challenge == null || strlen($challenge) == 0 || $response == null || strlen($response) == 0) {
            return false;
        }

        $response = $this->httpPost(self::RECAPTCHA_VERIFY_SERVER, '/recaptcha/api/verify', array(
            'privatekey' => $privateKey,
            'remoteip'   => $remoteip,
            'challenge'  => $challenge,
            'response'   => $response
        ) + $extra_params);

        $answers = explode ("\n", $response [1]);

        if (trim($answers[0]) == 'true') {
            return true;
        }

        return false;
    }

    /**
     * Submits an HTTP POST to a reCAPTCHA server
     *
     * @param string $host
     * @param string $path
     * @param array $data
     * @param int port
     *
     * @return array response
     */
    private function httpPost($host, $path, $data, $port = 80)
    {
        $req = $this->getQSEncode($data);

        $http_request  = "POST $path HTTP/1.0\r\n";
        $http_request .= "Host: $host\r\n";
        $http_request .= "Content-Type: application/x-www-form-urlencoded;\r\n";
        $http_request .= "Content-Length: ".strlen($req)."\r\n";
        $http_request .= "User-Agent: reCAPTCHA/PHP\r\n";
        $http_request .= "\r\n";
        $http_request .= $req;

        $response = null;
        if (!$fs = @fsockopen($host, $port, $errno, $errstr, 10)) {
            throw new ValidatorException('Could not open socket');
        }

        fwrite($fs, $http_request);

        while (!feof($fs)) {
            $response .= fgets($fs, 1160); // one TCP-IP packet
        }

        fclose($fs);

        $response = explode("\r\n\r\n", $response, 2);

        return $response;
    }

    /**
     * Encodes the given data into a query string format
     *
     * @param $data - array of string elements to be encoded
     *
     * @return string - encoded request
     */
    private function getQSEncode($data)
    {
        $req = null;
        foreach ($data as $key => $value) {
            $req .= $key.'='.urlencode(stripslashes($value)).'&';
        }

        // cut the last '&'
        $req = substr($req,0,strlen($req)-1);
        return $req;
    }
}
