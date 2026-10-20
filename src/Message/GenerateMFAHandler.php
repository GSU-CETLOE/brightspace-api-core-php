<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Message;

use Brightspace\Api\Core\Model\LoginUri;
use Brightspace\Api\Core\Model\MfaBody;
use Gadget\Http\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use Gadget\Util\MFA\TOTP;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @extends MessageHandler<MfaBody> */
class GenerateMFAHandler extends MessageHandler
{
    /**
     * @param string $d2lHost
     * @param string|int $mfa
     */
    public function __construct(
        private string $d2lHost,
        private string|int $mfa
    ) {
    }


    /**
     * @param RequestBuilder $requestBuilder
     * @return ServerRequestInterface
     */
    protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface
    {
        return $requestBuilder
            ->setMethod('GET')
            ->setUri('https://' . $this->d2lHost . LoginUri::MFA->value)
            ->getRequest();
    }


    /**
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @return MfaBody
     */
    protected function handleResponse(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): mixed {
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException();
        }

        /**
         * @param string[]|false $grep
         * @return string
         */
        $subject = function (array|false $grep): string {
            $v = (is_array($grep) ? array_pop($grep) : null) ?? "";
            return is_string($v) ? $v : "";
        };

        $matches = [];
        preg_match(
            '/\\\"P\\\"\:\[(.*)\]/',
            $subject(preg_grep(
                '/.*D2L\.LP\.Web\.Authentication\.Xsrf\.Init/',
                explode("\n", $response->getBody()->getContents())
            )),
            $matches
        );

        /** @var array{string,string,string} $generatedMFA */
        $generatedMFA = array_slice([
            ...array_map(
                fn(string $v) => trim($v, '\"'),
                explode(",", $matches[1] ?? ",,")
            ),
            '',
            '',
            '0'
        ], 0, 3);

        $rightNow = time();

        return new MfaBody(
            xsrfName: $generatedMFA[0],
            xsrfCode: $generatedMFA[1],
            hitCode: intval($generatedMFA[2]) + ((1000 * $rightNow + 100000000) % 100000000),
            mfaCode: match (true) {
                is_string($this->mfa) => (new TOTP())
                    ->setKey($this->mfa)
                    ->setCurrentTime($rightNow)
                    ->generate(),
                default => strval($this->mfa)
            }
        );
    }
}
