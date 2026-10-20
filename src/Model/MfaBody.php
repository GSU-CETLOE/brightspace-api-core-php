<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Model;

class MfaBody
{
    /**
     * @param string $xsrfName
     * @param string $xsrfCode
     * @param int $hitCode
     * @param string $mfaCode
     */
    public function __construct(
        public string $xsrfName,
        public string $xsrfCode,
        public int $hitCode,
        public string $mfaCode
    ) {
    }


    /**
     * @return array<string,string|int>
     */
    public function getBody(): array
    {
        return [
            'd2l_rf' => 'VerifyPin',
            'params' => "{\"param1\":\"{$this->mfaCode}\"}",
            "{$this->xsrfName}" => $this->xsrfCode,
            'd2l_hitcode' => $this->hitCode,
            'd2l_action' => 'rpc'
        ];
    }
}
