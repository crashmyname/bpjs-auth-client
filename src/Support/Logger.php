<?php

namespace Bpjs\AuthServiceClient\Support;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;

class Logger implements LoggerInterface
{
    private ?MonologLogger $logger;
    private bool $enabled;
    private string $channel;
    
    public function __construct(array $config = [])
    {
        $this->enabled = $config['enabled'] ?? true;
        $this->channel = $config['channel'] ?? 'auth-service-client';
        
        if ($this->enabled) {
            $this->logger = new MonologLogger($this->channel);
            
            $logPath = $config['path'] ?? '/var/log/auth-service-client/';
            $logLevel = $config['level'] ?? 'INFO';
            
            if (!is_dir($logPath)) {
                @mkdir($logPath, 0755, true);
            }
            
            $handler = new RotatingFileHandler(
                $logPath . 'auth-client.log',
                30,
                $this->getLogLevel($logLevel)
            );
            
            $formatter = new LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                "Y-m-d H:i:s"
            );
            
            $handler->setFormatter($formatter);
            $this->logger->pushHandler($handler);
        }
    }
    
    private function getLogLevel(string $level): int
    {
        $levels = [
            'DEBUG' => MonologLogger::DEBUG,
            'INFO' => MonologLogger::INFO,
            'NOTICE' => MonologLogger::NOTICE,
            'WARNING' => MonologLogger::WARNING,
            'ERROR' => MonologLogger::ERROR,
            'CRITICAL' => MonologLogger::CRITICAL,
            'ALERT' => MonologLogger::ALERT,
            'EMERGENCY' => MonologLogger::EMERGENCY,
        ];
        
        return $levels[strtoupper($level)] ?? MonologLogger::INFO;
    }
    
    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }
    
    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }
    
    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }
    
    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }
    
    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }
    
    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }
    
    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }
    
    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
    
    public function log($level, $message, array $context = []): void
    {
        if ($this->enabled && $this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}