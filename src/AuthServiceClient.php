<?php

declare(strict_types=1);

namespace Bpjs\AuthServiceClient;

use Bpjs\AuthServiceClient\Exceptions\AuthenticationException;
use Bpjs\AuthServiceClient\Exceptions\NetworkException;
use Bpjs\AuthServiceClient\Exceptions\TokenExpiredException;
use Bpjs\AuthServiceClient\Support\CacheManager;

class AuthServiceClient
{
    private string $baseUrl;
    private string $appKey;
    private string $appSecret;
    private int $timeout;
    private int $maxRetries;
    private CacheManager $cache;
    
    /**
     * @param array $config Configuration array
     * @param CacheManager|null $cache Cache manager instance
     */
    public function __construct(array $config = [], ?CacheManager $cache = null)
    {
        $this->baseUrl = rtrim(
            $config['base_url'] ?? $this->env('AUTH_SERVICE_URL', 'http://localhost:8000'), 
            '/'
        );
        $this->appKey = $config['app_key'] ?? $this->env('AUTH_APP_KEY', '');
        $this->appSecret = $config['app_secret'] ?? $this->env('AUTH_APP_SECRET', '');
        $this->timeout = (int)($config['timeout'] ?? $this->env('AUTH_TIMEOUT', '30'));
        $this->maxRetries = (int)($config['max_retries'] ?? $this->env('AUTH_MAX_RETRIES', '3'));
        $this->cache = $cache ?? new CacheManager();
    }

    private function env(string $key, string $default = ''): string
    {
        // Coba fungsi env() custom framework dulu
        if (function_exists('env')) {
            $value = env($key);
            if ($value !== null && $value !== '') {
                return (string)$value;
            }
        }
        
        // Fallback ke getenv()
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string)$value;
        }
        
        // Coba $_ENV
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        
        return $default;
    }
    
    /**
     * Verify token and get user data
     * 
     * @param string $token JWT token
     * @return array|null User data or null if invalid
     * @throws AuthenticationException|TokenExpiredException|NetworkException
     */
    public function verifyToken(string $token): ?array
    {
        $cacheKey = 'auth_token_' . md5($token);
        
        // Try cache first
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        try {
            $response = $this->request('POST', '/api/auth/verify-token', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'X-App-Key' => $this->appKey,
                ],
            ]);
            
            $userData = $response['data'] ?? null;
            
            if ($userData) {
                // Cache for 5 minutes
                $this->cache->set($cacheKey, $userData, 300);
            }
            
            return $userData;
            
        } catch (TokenExpiredException $e) {
            throw $e;
        } catch (NetworkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AuthenticationException('Failed to verify token: ' . $e->getMessage(), 401, $e);
        }
    }
    
    /**
     * Login user
     * 
     * @param string $username
     * @param string $password
     * @param array $options Additional options
     * @return array Login result with token and user data
     * @throws AuthenticationException|NetworkException
     */
    public function login(string $username, string $password, array $options = []): array
    {
        try {
            $response = $this->request('POST', '/api/auth/login', [
                'json' => [
                    'username' => $username,
                    'password' => $password,
                    'app_key' => $this->appKey,
                    'remember_me' => $options['remember_me'] ?? false,
                ],
            ]);
            
            return $response['data'] ?? [];
            
        } catch (\Throwable $e) {
            throw new AuthenticationException('Login failed: ' . $e->getMessage(), 401, $e);
        }
    }
    
    /**
     * Logout user
     * 
     * @param string $token
     * @return bool
     */
    public function logout(string $token): bool
    {
        try {
            $this->request('POST', '/api/auth/logout', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);
            
            // Clear cache
            $this->cache->delete('auth_token_' . md5($token));
            
            return true;
            
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * Refresh token
     * 
     * @param string $refreshToken
     * @return array New token data
     * @throws AuthenticationException|NetworkException
     */
    public function refreshToken(string $refreshToken): array
    {
        try {
            $response = $this->request('POST', '/api/auth/refresh-token', [
                'json' => [
                    'refresh_token' => $refreshToken,
                    'app_key' => $this->appKey,
                ],
            ]);
            
            return $response['data'] ?? [];
            
        } catch (\Throwable $e) {
            throw new AuthenticationException('Token refresh failed: ' . $e->getMessage(), 401, $e);
        }
    }
    
    /**
     * Get user menus
     * 
     * @param int $userId
     * @param string|null $appCode Filter by application code
     * @return array
     */
    public function getUserMenus(int $userId, ?string $appCode = null): array
    {
        $cacheKey = 'user_menus_' . $userId . ($appCode ? '_' . $appCode : '');
        
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        try {
            $params = ['user_id' => $userId];
            if ($appCode) {
                $params['app_code'] = $appCode;
            }
            
            $response = $this->request('GET', '/api/menus/user', [
                'query' => $params,
                'headers' => [
                    'X-App-Key' => $this->appKey,
                ],
            ]);
            
            $menus = $response['data'] ?? [];
            
            // Cache for 10 minutes
            $this->cache->set($cacheKey, $menus, 600);
            
            return $menus;
            
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Check menu access
     * 
     * @param int $userId
     * @param string $menuCode
     * @return bool
     */
    public function checkMenuAccess(int $userId, string $menuCode): bool
    {
        $cacheKey = 'menu_access_' . $userId . '_' . $menuCode;
        
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }
        
        try {
            $response = $this->request('GET', '/api/menus/check-access', [
                'query' => [
                    'user_id' => $userId,
                    'menu_code' => $menuCode,
                ],
                'headers' => [
                    'X-App-Key' => $this->appKey,
                ],
            ]);
            
            $hasAccess = (bool)($response['data']['has_access'] ?? false);
            
            // Cache for 5 minutes
            $this->cache->set($cacheKey, $hasAccess, 300);
            
            return $hasAccess;
            
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * Check if user has specific permission
     * 
     * @param int $userId
     * @param string $permission
     * @return bool
     */
    public function hasPermission(int $userId, string $permission): bool
    {
        $cacheKey = 'user_permission_' . $userId . '_' . $permission;
        
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }
        
        try {
            $response = $this->request('GET', '/api/auth/permissions/check', [
                'query' => [
                    'user_id' => $userId,
                    'permission' => $permission,
                ],
                'headers' => [
                    'X-App-Key' => $this->appKey,
                ],
            ]);
            
            $hasPermission = (bool)($response['data']['has_permission'] ?? false);
            
            // Cache for 5 minutes
            $this->cache->set($cacheKey, $hasPermission, 300);
            
            return $hasPermission;
            
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * Make HTTP request to auth service
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $options Request options
     * @return array Response data
     * @throws NetworkException|TokenExpiredException|AuthenticationException
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $attempts = 0;
        $lastException = null;
        
        while ($attempts < $this->maxRetries) {
            try {
                $attempts++;
                
                $ch = curl_init();
                
                // Build URL with query params for GET
                if (strtoupper($method) === 'GET' && isset($options['query'])) {
                    $url .= '?' . http_build_query($options['query']);
                }
                
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => $this->buildHeaders($options['headers'] ?? []),
                ]);
                
                // Set method and body
                switch (strtoupper($method)) {
                    case 'POST':
                        curl_setopt($ch, CURLOPT_POST, true);
                        if (isset($options['json'])) {
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json']));
                        }
                        break;
                        
                    case 'PUT':
                    case 'PATCH':
                    case 'DELETE':
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
                        if (isset($options['json'])) {
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json']));
                        }
                        break;
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    throw new NetworkException('CURL Error: ' . $curlError);
                }
                
                $data = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new NetworkException('Invalid JSON response');
                }
                
                // Handle HTTP errors
                if ($httpCode >= 400) {
                    $message = $data['message'] ?? 'HTTP Error ' . $httpCode;
                    
                    if ($httpCode === 401 && str_contains(strtolower($message), 'expired')) {
                        throw new TokenExpiredException($message);
                    }
                    
                    if ($httpCode === 401 || $httpCode === 403) {
                        throw new AuthenticationException($message, $httpCode);
                    }
                    
                    throw new NetworkException($message, $httpCode);
                }
                
                return $data;
                
            } catch (TokenExpiredException | AuthenticationException $e) {
                // Don't retry auth errors
                throw $e;
            } catch (\Throwable $e) {
                $lastException = $e;
                
                if ($attempts < $this->maxRetries) {
                    // Exponential backoff
                    usleep(500000 * pow(2, $attempts - 1)); // 0.5s, 1s, 2s
                }
            }
        }
        
        throw new NetworkException(
            'Request failed after ' . $this->maxRetries . ' attempts: ' . 
            ($lastException ? $lastException->getMessage() : 'Unknown error'),
            0,
            $lastException
        );
    }
    
    /**
     * Build HTTP headers
     * 
     * @param array $customHeaders
     * @return array
     */
    private function buildHeaders(array $customHeaders = []): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        
        foreach ($customHeaders as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }
        
        return $headers;
    }
}