<?php

namespace Bpjs\AuthServiceClient\Exceptions;

class NetworkException extends \RuntimeException
{
    protected $code = 500;
    
    public function __construct(string $message = 'Network error occurred', int $code = 500, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}