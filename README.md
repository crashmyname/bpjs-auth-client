# 🔐 Auth Service Client

Library autentikasi terpusat untuk microservices BPJS. Cukup install, tambah 1 baris middleware, beres!

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://php.net)
[![Tests](https://img.shields.io/badge/Tests-32%20PASS-green)]()
[![License](https://img.shields.io/badge/License-MIT-yellow)]()

---

## 📖 Daftar Isi

- [Konsep](#-konsep)
- [Requirements](#-requirements)
- [Installasi](#-installasi)
- [Quick Start](#-quick-start)
- [Cara Kerja](#-cara-kerja)
- [Konfigurasi](#-konfigurasi)
- [Penggunaan](#-penggunaan)
- [API Reference](#-api-reference)
- [Error Handling](#-error-handling)
- [Testing](#-testing)
- [Troubleshooting](#-troubleshooting)

---

## 💡 Konsep
┌─────────────┐
│ User Service │ ← Satu-satunya tempat login & database user
│ :8000 │
└──────┬───────┘
│
│ Token diverifikasi ke sini
│
┌──────┴──────────────────────────┐
│ │
│ ┌──────────┐ ┌──────────┐ │
│ │ HRM │ │ Ticket │ │ ← Service lain TIDAK perlu
│ │ :8001 │ │ :8002 │ │ database user sendiri
│ └──────────┘ └──────────┘ │
│ │
│ User login SEKALI, │
│ bisa akses SEMUA service │
└──────────────────────────────────┘

**Keuntungan:**
- ✅ Single Sign-On (SSO) - login sekali akses semua
- ✅ Tidak perlu database user di setiap service
- ✅ Tidak perlu sharing JWT secret key
- ✅ User management terpusat
- ✅ Logout di User Service langsung berpengaruh ke semua service

---

## 📋 Requirements

- PHP >= 8.1
- ext-curl
- ext-json
- Composer

---

## 🚀 Installasi

### 1. via composer

```bash
composer require bpjs/auth-client

# .env di setiap aplikasi
AUTH_SERVICE_URL=http://192.168.1.10:8000
AUTH_APP_KEY=your_app_key
AUTH_APP_SECRET=your_app_secret
```

```php
<?php
// routes/api.php

use Bpjs\AuthServiceClient\Middleware\AuthMiddlewareClient;

// HANYA 3 BARIS!
Api::group([AuthMiddlewareClient::class], function() {
    Api::get('/payroll', [PayrollController::class, 'index']);
    Api::post('/transaction', [TransactionController::class, 'store']);
});

<?php

class PayrollController
{
    public function index()
    {
        // ✅ User sudah ada di session
        $user = $_SESSION['user'];
        
        echo "Welcome, " . $user['username'];
        // Output: Welcome, admin
    }
}
```
1. USER LOGIN (sekali)
   ┌─────────┐     POST /api/auth/login      ┌──────────────┐
   │ Browser │ ─────────────────────────────► │ User Service │
   │         │ ◄───────────────────────────── │   :8000      │
   └─────────┘   { token: "eyJ...",          └──────────────┘
                   user: {...} }

2. AKSES HRM
   ┌─────────┐  GET /api/payroll            ┌──────────────┐
   │ Browser │  Authorization: Bearer eyJ... │  HRM Service │
   │         │ ────────────────────────────► │   :8001      │
   └─────────┘                               └──────┬───────┘
                                                    │
                   3. VERIFIKASI TOKEN              │
                   ┌────────────────────────────────┘
                   │  POST /api/auth/verify-token
                   ▼
              ┌──────────────┐
              │ User Service │
              │   :8000      │
              └──────┬───────┘
                     │
              4. USER DATA
                     │
                     ▼
              ┌──────────────┐
              │  HRM Service │ → $_SESSION['user']
              │   :8001      │ → Controller
              └──────┬───────┘
                     │
              5. RESPONSE
                     │
                     ▼
              ┌─────────┐
              │ Browser │
              └─────────┘
Request 1: Token "xxx"
  → Cek cache: KOSONG
  → HTTP ke User Service (200ms)
  → Simpan cache (5 menit)

Request 2: Token "xxx" (dalam 5 menit)
  → Cek cache: ADA!
  → Langsung return (< 1ms)
  → TIDAK perlu HTTP request!

Environment Variables
Variable	Required	Default	Deskripsi
AUTH_SERVICE_URL	Ya	http://localhost:8000	URL User Service
AUTH_APP_KEY	Ya	-	App Key dari User Service
AUTH_APP_SECRET	Ya	-	App Secret dari User Service
AUTH_TIMEOUT	Tidak	30	Timeout HTTP request (detik)
AUTH_MAX_RETRIES	Tidak	3	Max retry kalau gagal
AUTH_TOKEN_SOURCE	Tidak	bearer	Sumber token: bearer, cookie, header, query
AUTH_COOKIE_NAME	Tidak	token	Nama cookie (jika pakai cookie)
AUTH_HEADER_NAME	Tidak	X-Auth-Token	Nama custom header
AUTH_REDIRECT_URL	Tidak	/login	URL redirect jika tidak authenticated

Konfigurasi Middleware

```php
$middleware = new AuthMiddlewareClient([
    'redirect_url' => 'http://user-service/login',
    'exclude_routes' => [
        '/login',
        '/register',
        '/api/public/*',
        '/health',
    ],
    'token_source' => 'bearer',    // bearer | cookie | header | query
    'cookie_name' => 'auth_token',
    'header_name' => 'X-Auth-Token',
    'auto_refresh' => true,        // Auto refresh token expired
]);
```
Penggunaan Middleware
```php
<?php
// routes/api.php

use Bpjs\AuthServiceClient\Middleware\AuthMiddlewareClient;

// Route public (tanpa middleware)
Api::post('/auth/login', [AuthController::class, 'login']);

// Route protected (dengan middleware)
Api::group([AuthMiddlewareClient::class], function() {
    
    Api::get('/dashboard', [DashboardController::class, 'index']);
    Api::get('/payroll', [PayrollController::class, 'index']);
    Api::post('/transaction', [TransactionController::class, 'store']);
    
    // Nested group dengan permission spesifik
    Api::group([AuthMiddlewareClient::class, AdminMiddleware::class], function() {
        Api::get('/admin/users', [AdminController::class, 'users']);
    });
    
});
```

Mengakses User Data di Controller
```php
<?php

class PayrollController
{
    public function index()
    {
        // Cara 1: Via $_SESSION
        $user = $_SESSION['user'];
        $userId = $_SESSION['user_id'];
        $username = $_SESSION['username'];
        $roles = $_SESSION['roles'];
        $permissions = $_SESSION['permissions'];
        
        // Cara 2: Via $GLOBALS
        $user = $GLOBALS['auth_user'];
        
        // Cara 3: Via helper (kalau dibuat)
        $user = auth_user();
        
        // Cek permission
        if (!in_array('payroll_view', $permissions)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        
        // Bisnis logic
        $data = Payroll::where('user_id', $userId)->get();
        
        return Response::json([
            'status' => 200,
            'data' => $data,
            'user' => [
                'id' => $userId,
                'username' => $username,
            ]
        ]);
    }
}
```

Manual Usage (Tanpa Middleware)
```php
<?php

use Bpjs\AuthServiceClient\AuthServiceClient;

// Inisialisasi
$auth = new AuthServiceClient([
    'base_url' => 'http://192.168.1.10:8000',
    'app_key' => 'hrm_app_key_123',
    'app_secret' => 'hrm_secret_abc',
]);

// Verifikasi token manual
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

try {
    $userData = $auth->verifyToken($token);
    
    if ($userData) {
        // Token valid
        $_SESSION['user'] = $userData;
        echo "Welcome, " . $userData['username'];
    } else {
        // Token invalid
        http_response_code(401);
        echo json_encode(['error' => 'Invalid token']);
    }
    
} catch (TokenExpiredException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Token expired']);
}
```

Login/Logout
```php
<?php

use Bpjs\AuthServiceClient\AuthServiceClient;

$auth = new AuthServiceClient([
    'base_url' => 'http://192.168.1.10:8000',
]);

// LOGIN
try {
    $result = $auth->login('admin', 'password123');
    
    // Set token ke cookie
    setcookie('token', $result['token'], time() + 86400, '/');
    setcookie('refresh_token', $result['refresh_token'], time() + 604800, '/');
    
    // Simpan user
    $_SESSION['user'] = $result['user'];
    
    echo "Login berhasil!";
    
} catch (AuthenticationException $e) {
    echo "Login gagal: " . $e->getMessage();
}

// LOGOUT
$token = $_COOKIE['token'];
$auth->logout($token);

// Clear session & cookie
session_destroy();
setcookie('token', '', time() - 3600, '/');
```
Cek Permission & Menu
```php
<?php

$auth = new AuthServiceClient([...]);

// Cek menu akses
$hasAccess = $auth->checkMenuAccess($userId, 'payroll');
if ($hasAccess) {
    // Tampilkan menu payroll
}

// Cek permission
$canEdit = $auth->hasPermission($userId, 'employee_edit');
if ($canEdit) {
    // Tampilkan tombol edit
}

// Ambil semua menu user
$menus = $auth->getUserMenus($userId, 'hrm');
foreach ($menus as $menu) {
    echo "<a href='{$menu['url']}'>{$menu['name']}</a>";
}
```

Login di Frontend
```js
// 1. Login via User Service
async function login(username, password) {
    const response = await fetch('http://192.168.1.10:8000/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password })
    });
    
    const data = await response.json();
    
    // Simpan token
    localStorage.setItem('token', data.data.token);
    localStorage.setItem('refresh_token', data.data.refresh_token);
    
    return data;
}

// 2. Akses HRM dengan token
async function fetchPayroll() {
    const token = localStorage.getItem('token');
    
    const response = await fetch('http://192.168.1.10:8001/api/payroll', {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        }
    });
    
    if (response.status === 401) {
        // Token expired, redirect ke login
        window.location.href = 'http://192.168.1.10:8000/login';
        return;
    }
    
    return response.json();
}

// 3. Auto logout jika token expired
async function apiRequest(url, options = {}) {
    const token = localStorage.getItem('token');
    
    const response = await fetch(url, {
        ...options,
        headers: {
            ...options.headers,
            'Authorization': `Bearer ${token}`,
        }
    });
    
    if (response.status === 401) {
        const data = await response.json();
        
        if (data.code === 'token_expired') {
            // Coba refresh token
            const refreshed = await refreshToken();
            if (refreshed) {
                // Retry request dengan token baru
                return apiRequest(url, options);
            }
        }
        
        // Redirect login
        localStorage.clear();
        window.location.href = '/login';
    }
    
    return response;
}
```
API Reference
AuthServiceClient
Constructor
```php
new AuthServiceClient(array $config = [], ?CacheManager $cache = null)
```
Parameters:

$config - Array konfigurasi

base_url - URL User Service

app_key - Application key

app_secret - Application secret

timeout - HTTP timeout (default: 30)

max_retries - Max retry (default: 3)

$cache - Optional CacheManager instance

Methods
verifyToken(string $token): ?array
Verifikasi token ke User Service
```php
$user = $auth->verifyToken('eyJhbGciOiJIUzI1NiIs...');
// Returns: ['id' => 1, 'username' => 'admin', ...] atau null
```
login(string $username, string $password, array $options = []): array
Login user
```php
$result = $auth->login('admin', 'password123', [
    'remember_me' => true
]);
// Returns: ['token' => '...', 'refresh_token' => '...', 'user' => [...]]
```
logout(string $token): bool
Logout user
```php
$success = $auth->logout('eyJhbGciOiJIUzI1NiIs...');
// Returns: true/false
```
refreshToken(string $refreshToken): array
Refresh token expired

```php
$newTokens = $auth->refreshToken('refresh_token_xxx');
// Returns: ['token' => '...', 'refresh_token' => '...']
```
getUserMenus(int $userId, ?string $appCode = null): array
Ambil menu user
```php
$menus = $auth->getUserMenus(1, 'hrm');
// Returns: [['name' => 'Payroll', 'url' => '/payroll', ...], ...]
```
checkMenuAccess(int $userId, string $menuCode): bool
Cek akses menu
```php
$canAccess = $auth->checkMenuAccess(1, 'payroll');
// Returns: true/false
```
hasPermission(int $userId, string $permission): bool
Cek permission user
```php
$canEdit = $auth->hasPermission(1, 'employee_edit');
// Returns: true/false
```
AuthMiddlewareClient
```php
Api::group([AuthMiddlewareClient::class], function() {
    // Protected routes
});
```
Exceptions
Exception	HTTP Code	Deskripsi
AuthenticationException	401	Auth gagal
TokenExpiredException	401	Token expired
NetworkException	500	Network error
UnauthorizedException	403	Tidak diizinkan
🐛 Error Handling
Contoh Lengkap Error Handling
```php
<?php

use Bpjs\AuthServiceClient\AuthServiceClient;
use Bpjs\AuthServiceClient\Exceptions\{
    AuthenticationException,
    TokenExpiredException,
    NetworkException
};

try {
    $user = $auth->verifyToken($token);
    
    if (!$user) {
        // Token tidak valid
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    
    // Sukses
    return response()->json(['data' => $user]);
    
} catch (TokenExpiredException $e) {
    // Token expired - minta user login ulang
    return response()->json([
        'error' => 'Token expired',
        'code' => 'token_expired',
        'message' => 'Silakan login kembali'
    ], 401);
    
} catch (AuthenticationException $e) {
    // Auth error - credentials salah
    return response()->json([
        'error' => 'Authentication failed',
        'message' => $e->getMessage()
    ], 401);
    
} catch (NetworkException $e) {
    // User Service down
    return response()->json([
        'error' => 'Service unavailable',
        'message' => 'Layanan autentikasi sedang tidak tersedia'
    ], 503);
    
} catch (\Throwable $e) {
    // Unknown error
    return response()->json([
        'error' => 'Internal server error'
    ], 500);
}
```
Response Format
Sukses (200)
```php
{
    "status": "success",
    "data": {
        "id": 1,
        "username": "admin",
        "roles": ["admin"],
        "permissions": ["payroll_view", "employee_edit"]
    }
}
```
Token Tidak Ditemukan (401)
```php
{
    "status": 401,
    "message": "Token tidak ditemukan",
    "error": "unauthorized"
}
```
Token Expired (401)
```php
{
    "status": 401,
    "message": "Token expired",
    "error": "token_expired",
    "code": "token_expired"
}
```
Forbidden (403)
```php
{
    "status": 403,
    "message": "Anda tidak memiliki akses ke resource ini",
    "error": "forbidden"
}
```
Troubleshooting
Error: "Token tidak ditemukan"
Penyebab: Token tidak dikirim atau format salah

Solusi:
```js
// Pastikan header Authorization dikirim
fetch(url, {
    headers: {
        'Authorization': 'Bearer ' + token  // ← Format harus "Bearer <token>"
    }
})
```
Changelog
v1.0.0 (2024-01-01)
✅ Initial release

✅ Token verification

✅ Login/Logout

✅ Permission check

✅ Menu access

✅ Caching support

✅ Middleware ready

📄 License
MIT License

👥 Contributors
Team BPJS Development
