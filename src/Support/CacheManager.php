<?php

declare(strict_types=1);

namespace Bpjs\AuthServiceClient\Support;

class CacheManager
{
    private array $memoryCache = [];
    private string $prefix;
    private int $defaultTtl;
    
    public function __construct(array $config = [])
    {
        $this->prefix = $config['prefix'] ?? 'auth_service_';
        $this->defaultTtl = (int)($config['ttl'] ?? 300); // 5 menit default
    }
    
    /**
     * Get value from cache
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->prefix . $key;
        
        if (isset($this->memoryCache[$key])) {
            $entry = $this->memoryCache[$key];
            
            // Cek TTL (null = forever)
            if ($entry['expires_at'] === null || $entry['expires_at'] > time()) {
                return $entry['value'];
            }
            
            // Expired, hapus
            unset($this->memoryCache[$key]);
        }
        
        return $default;
    }
    
    /**
     * Set value to cache
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $key = $this->prefix . $key;
        $ttl = $ttl ?? $this->defaultTtl;
        
        $this->memoryCache[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttl,
        ];
        
        return true;
    }
    
    /**
     * Delete value from cache
     */
    public function delete(string $key): bool
    {
        $key = $this->prefix . $key;
        
        if (isset($this->memoryCache[$key])) {
            unset($this->memoryCache[$key]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Clear all cache
     */
    public function clear(): bool
    {
        $this->memoryCache = [];
        return true;
    }
    
    /**
     * Check if key exists
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
    
    /**
     * Get multiple values
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }
    
    /**
     * Set multiple values
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }
    
    /**
     * Delete multiple values
     */
    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }
    
    /**
     * Get cache stats for debugging
     */
    public function getStats(): array
    {
        $total = count($this->memoryCache);
        $expired = 0;
        $now = time();
        
        foreach ($this->memoryCache as $entry) {
            if ($entry['expires_at'] !== null && $entry['expires_at'] <= $now) {
                $expired++;
            }
        }
        
        return [
            'total_keys' => $total,
            'expired_keys' => $expired,
            'active_keys' => $total - $expired,
        ];
    }
    
    /**
     * Remove expired entries
     */
    public function garbageCollect(): int
    {
        $count = 0;
        $now = time();
        
        foreach ($this->memoryCache as $key => $entry) {
            if ($entry['expires_at'] !== null && $entry['expires_at'] <= $now) {
                unset($this->memoryCache[$key]);
                $count++;
            }
        }
        
        return $count;
    }
}