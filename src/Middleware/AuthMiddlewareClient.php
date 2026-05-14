<?php
// File: C:\laragon\www\auth-client\src\Middleware\AuthMiddlewareClient.php

declare(strict_types=1);

namespace Bpjs\AuthServiceClient\Middleware;

use Bpjs\AuthServiceClient\AuthServiceClient;
use Bpjs\AuthServiceClient\Exceptions\TokenExpiredException;
use Bpjs\AuthServiceClient\Exceptions\AuthenticationException;

class AuthMiddlewareClient
{
    private AuthServiceClient $authClient;
    private array $config;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->authClient = new AuthServiceClient([
            'base_url' => $this->env('AUTH_SERVICE_URL', 'http://localhost:8000'),
            'app_key' => $this->env('AUTH_APP_KEY', $this->env('APP_KEY', '')),
            'app_secret' => $this->env('AUTH_APP_SECRET', $this->env('APP_SECRET', '')),
            'timeout' => (int)$this->env('AUTH_TIMEOUT', '30'),
            'max_retries' => (int)$this->env('AUTH_MAX_RETRIES', '3'),
        ]);
        
        $this->config = [
            'redirect_url' => $this->env('AUTH_REDIRECT_URL', '/login'),
            'exclude_routes' => [
                '/login',
                '/logout', 
                '/auth/callback',
                '/api/public/*',
                '/api/auth/*',
            ],
            'token_source' => $this->env('AUTH_TOKEN_SOURCE', 'bearer'),
            'cookie_name' => $this->env('AUTH_COOKIE_NAME', 'token'),
            'header_name' => $this->env('AUTH_HEADER_NAME', 'X-Auth-Token'),
            'auto_refresh' => true,
        ];
    }
    
    /**
     *  Helper: Get env variable (support both env() and getenv())
     */
    private function env(string $key, string $default = ''): string
    {
        // 1. Coba fungsi env() custom (framework)
        if (function_exists('env')) {
            $value = env($key);
            if ($value !== null && $value !== '') {
                return (string)$value;
            }
        }
        
        // 2. Coba getenv() PHP native
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string)$value;
        }
        
        // 3. Coba $_ENV superglobal
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        
        // 4. Coba $_SERVER superglobal
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }
        
        // 5. Return default
        return $default;
    }
    
    /**
     * Handle middleware - dipanggil otomatis oleh framework
     */
    public function handle(): void
    {
        $currentRoute = $this->getCurrentRoute();
        
        // Skip excluded routes
        if ($this->isExcludedRoute($currentRoute)) {
            return;
        }
        
        try {
            // Get token
            $token = $this->getTokenFromRequest();
            
            if (!$token) {
                $this->unauthorized('Token tidak ditemukan');
            }
            
            // Verify token ke User Service
            $userData = $this->authClient->verifyToken($token);
            
            if (!$userData) {
                $this->unauthorized('Token tidak valid');
            }
            
            // Simpan user data
            $this->setUserData($userData);
            
            // Check permissions if needed
            $this->checkRoutePermission($currentRoute, $userData);
            
        } catch (TokenExpiredException $e) {
            $this->handleTokenExpired();
        } catch (AuthenticationException $e) {
            $this->unauthorized($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError($e->getMessage());
        }
    }
    
    /**
     * Get token from request
     */
    private function getTokenFromRequest(): ?string
    {
        return match ($this->config['token_source']) {
            'bearer' => $this->getBearerToken(),
            'cookie' => $_COOKIE[$this->config['cookie_name']] ?? null,
            'header' => $this->getHeaderToken(),
            'query' => $_GET['token'] ?? null,
            default => $this->getBearerToken() ?? 
                       ($_COOKIE[$this->config['cookie_name']] ?? null),
        };
    }
    
    /**
     * Get bearer token
     */
    private function getBearerToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        
        // Cek dari getallheaders()
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        
        // Cek dari $_SERVER
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Get token from custom header
     */
    private function getHeaderToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headerName = $this->config['header_name'];
        
        return $headers[$headerName] ?? $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $headerName))] ?? null;
    }
    
    /**
     * Get current route
     */
    private function getCurrentRoute(): string
    {
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    }
    
    /**
     * Check if route is excluded
     */
    private function isExcludedRoute(string $route): bool
    {
        foreach ($this->config['exclude_routes'] as $pattern) {
            if (str_ends_with($pattern, '*')) {
                $prefix = rtrim($pattern, '*');
                if (str_starts_with($route, $prefix)) {
                    return true;
                }
            } elseif ($route === $pattern) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Set user data to session
     */
    private function setUserData(array $userData): void
    {
        $_SESSION['user'] = $userData;
        $_SESSION['user_id'] = $userData['id'] ?? null;
        $_SESSION['username'] = $userData['username'] ?? null;
        $_SESSION['roles'] = $userData['roles'] ?? [];
        $_SESSION['permissions'] = $userData['permissions'] ?? [];
        
        $GLOBALS['auth_user'] = $userData;
    }
    
    /**
     * Check route-specific permissions
     */
    private function checkRoutePermission(string $route, array $userData): void
    {
        $permissionMap = [
            '/api/payroll' => 'payroll_view',
            '/api/employee' => 'employee_view',
            '/api/admin' => 'admin_access',
        ];
        
        foreach ($permissionMap as $path => $permission) {
            if (str_starts_with($route, $path)) {
                if (!$this->authClient->hasPermission($userData['id'], $permission)) {
                    $this->forbidden('Anda tidak memiliki akses ke resource ini');
                }
            }
        }
    }
    
    /**
     * Handle unauthorized
     */
    private function unauthorized(string $message = 'Unauthorized'): never
    {
        if (ob_get_level()) {
            ob_clean();
        }
        
        http_response_code(401);
        header('Content-Type: application/json');
        
        echo json_encode([
            'status' => 401,
            'message' => $message,
            'error' => 'unauthorized'
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
    
    /**
     * Handle forbidden
     */
    private function forbidden(string $message = 'Forbidden'): never
    {
        http_response_code(403);
        header('Content-Type: application/json');
        
        echo json_encode([
            'status' => 403,
            'message' => $message,
            'error' => 'forbidden'
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
    
    /**
     * Handle token expired
     */
    private function handleTokenExpired(): never
    {
        if ($this->config['auto_refresh']) {
            $refreshToken = $_COOKIE['refresh_token'] ?? $_POST['refresh_token'] ?? null;
            
            if ($refreshToken) {
                try {
                    $newTokens = $this->authClient->refreshToken($refreshToken);
                    
                    setcookie(
                        $this->config['cookie_name'],
                        $newTokens['token'],
                        time() + 86400,
                        '/',
                        '',
                        false,
                        true
                    );
                    
                    if (isset($newTokens['refresh_token'])) {
                        setcookie(
                            'refresh_token',
                            $newTokens['refresh_token'],
                            time() + 604800,
                            '/',
                            '',
                            false,
                            true
                        );
                    }
                    
                    $_SESSION['user'] = $newTokens['user'] ?? [];
                    
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                    
                } catch (\Throwable $e) {
                    // Refresh failed
                }
            }
        }
        
        session_destroy();
        
        http_response_code(401);
        header('Content-Type: application/json');
        
        echo json_encode([
            'status' => 401,
            'message' => 'Token expired',
            'error' => 'token_expired',
            'code' => 'token_expired'
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
    
    /**
     * Handle server error
     */
    private function serverError(string $message = 'Internal Server Error'): never
    {
        http_response_code(500);
        header('Content-Type: application/json');
        
        echo json_encode([
            'status' => 500,
            'message' => 'Internal Server Error',
            'error' => $message
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
    
    /**
     * Magic method untuk callable
     */
    public function __invoke(): void
    {
        $this->handle();
    }
}