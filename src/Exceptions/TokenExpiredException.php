<?php

namespace Bpjs\AuthServiceClient\Exceptions;

class TokenExpiredException extends AuthenticationException
{
    protected $code = 401;
    
    public function __construct(string $message = 'Token has expired', int $code = 401, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}