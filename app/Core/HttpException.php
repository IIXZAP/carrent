<?php

namespace App\Core;

/**
 * โยนจากที่ไหนก็ได้ (middleware, controller, service) เพื่อหยุดงานแล้วตอบ Response นี้ทันที
 */
class HttpException extends \RuntimeException
{
    public function __construct(private Response $response)
    {
        parent::__construct('HTTP ' . $response->statusCode());
    }

    public function response(): Response
    {
        return $this->response;
    }
}
