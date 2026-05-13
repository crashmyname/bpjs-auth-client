<?php

namespace Bpjs\AuthServiceClient\Exceptions;

class UnauthorizedException extends AuthenticationException
{
    protected $code = 403;
    
    public function __construct(string $message = 'Unauthorized access', int $code = 403, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}