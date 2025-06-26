# Security Fixes - Implementation Guide

## Overview

This document provides specific code examples and implementation guidance for fixing the security vulnerabilities identified in the Map Integration plugin security assessment.

## High Priority Fixes

### 1. Fix File Write Vulnerability (HIGH RISK)

**Current Code (map-integration.php:44):**
```php
public static function log_message($message)
{
    $log_file = MAP_INTEGRATION_PLUGIN_PATH . 'geocodelogs.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}
```

**Secure Implementation:**
```php
public static function log_message($message)
{
    // Use WordPress built-in logging when WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf('[Map Integration] %s', $message));
    }
    
    // Alternative: Use WordPress uploads directory with proper security
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/map-integration-logs/';
    
    // Create directory if it doesn't exist
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
        // Create .htaccess to prevent direct access
        file_put_contents($log_dir . '.htaccess', "deny from all\n");
    }
    
    $log_file = $log_dir . 'geocode-' . date('Y-m') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
    
    // Validate file permissions and size
    if (is_writable($log_dir) && (!file_exists($log_file) || filesize($log_file) < 10485760)) { // 10MB limit
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
}
```

### 2. Remove Debug Output (MEDIUM RISK)

**Current Code (map-integration.php:1126):**
```php
echo '<script>console.log("Clinic Data:", ' . json_encode($clinic_data) . ');</script>';
```

**Secure Implementation:**
```php
// Only output debug information in development environment
if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
    echo '<script>console.log("Clinic Data:", ' . wp_json_encode($clinic_data) . ');</script>';
}
```

### 3. Improve SQL Query Security (MEDIUM RISK)

**Current Code (includes/class-geocoding-service.php:647-656):**
```php
$query = "DELETE FROM {$table_name}";
if (!empty($where)) {
    $query .= ' WHERE ' . implode(' AND ', $where);
}

if (!empty($where_values)) {
    $query = $wpdb->prepare($query, $where_values);
}

$deleted = $wpdb->query($query);
```

**Secure Implementation:**
```php
// Validate table name exists
$valid_tables = array($wpdb->prefix . self::$cache_table);
if (!in_array($table_name, $valid_tables)) {
    self::log_message("Invalid table name attempted: " . $table_name);
    return 0;
}

// Use WordPress methods for better security
if (!empty($options['older_than'])) {
    $cutoff_date = date('Y-m-d H:i:s', time() - $options['older_than']);
    $where_conditions = array('created_at < %s');
    $where_values = array($cutoff_date);
    
    if (!empty($options['provider'])) {
        $where_conditions[] = 'provider = %s';
        $where_values[] = $options['provider'];
    }
    
    $query = $wpdb->prepare(
        "DELETE FROM {$table_name} WHERE " . implode(' AND ', $where_conditions),
        $where_values
    );
} else {
    $query = "DELETE FROM {$table_name}";
}

$deleted = $wpdb->query($query);
```

## Medium Priority Fixes

### 4. Strengthen Input Validation

**Enhanced Address Validation:**
```php
/**
 * Validate address input comprehensively
 */
private static function validate_address_input($address) {
    // Basic sanitization
    $address = sanitize_text_field($address);
    
    // Length validation
    if (strlen($address) > 255) {
        return false;
    }
    
    // Pattern validation - basic address format
    if (!preg_match('/^[a-zA-Z0-9\s,.\-#]+$/', $address)) {
        return false;
    }
    
    // Check for suspicious patterns
    $suspicious_patterns = array(
        '/script/i',
        '/javascript/i',
        '/eval\(/i',
        '/expression\(/i'
    );
    
    foreach ($suspicious_patterns as $pattern) {
        if (preg_match($pattern, $address)) {
            return false;
        }
    }
    
    return $address;
}
```

### 5. Improve Address Component Sanitization

**Current Code (includes/class-street-parser.php:549):**
```php
private static function sanitize_component($component)
{
    // Remove potentially harmful characters
    $component = preg_replace('/[<>"\']+/', '', $component);
    
    // Trim whitespace
    $component = trim($component);
    
    // Limit length
    $component = substr($component, 0, 100);
    
    return $component;
}
```

**Secure Implementation:**
```php
private static function sanitize_component($component)
{
    // Use WordPress sanitization
    $component = sanitize_text_field($component);
    
    // Additional validation for address components
    $component = preg_replace('/[^a-zA-Z0-9\s\-\.#]/', '', $component);
    
    // Trim whitespace
    $component = trim($component);
    
    // Limit length
    $component = substr($component, 0, 100);
    
    // Ensure it's not empty after sanitization
    if (empty($component)) {
        return '';
    }
    
    return $component;
}
```

## Low Priority Fixes

### 6. Secure File Inclusion

**Current Code (map-integration.php:2168):**
```php
include MAP_INTEGRATION_PLUGIN_PATH . 'admin/partials/geocoding-test.php';
```

**Secure Implementation:**
```php
$include_file = MAP_INTEGRATION_PLUGIN_PATH . 'admin/partials/geocoding-test.php';

// Validate file exists and is within plugin directory
$real_file = realpath($include_file);
$plugin_dir = realpath(MAP_INTEGRATION_PLUGIN_PATH);

if ($real_file && $plugin_dir && strpos($real_file, $plugin_dir) === 0) {
    include $include_file;
} else {
    wp_die('Invalid file access attempt.');
}
```

### 7. Enhance Error Handling

**Current Implementation:**
```php
if (is_wp_error($response)) {
    $error_message = $response->get_error_message();
    self::log_message("Nominatim API request failed with WP_Error: {$error_message}");
    return false;
}
```

**Secure Implementation:**
```php
if (is_wp_error($response)) {
    $error_code = $response->get_error_code();
    $error_message = $response->get_error_message();
    
    // Log detailed error for debugging
    self::log_message("API request failed - Code: {$error_code}");
    
    // Don't expose detailed error messages to users
    if (defined('WP_DEBUG') && WP_DEBUG) {
        self::log_message("Debug - Error details: {$error_message}");
    }
    
    return false;
}
```

## Additional Security Measures

### 8. Add Security Headers for Admin Pages

```php
/**
 * Add security headers for admin pages
 */
public function add_security_headers() {
    if (is_admin()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

// Hook into WordPress
add_action('admin_init', array($this, 'add_security_headers'));
```

### 9. Rate Limiting Enforcement

```php
/**
 * Enhanced rate limiting with enforcement
 */
private static function enforce_rate_limit($provider)
{
    if (!isset(self::$rate_limits[$provider])) {
        return;
    }
    
    $rate_limit = self::$rate_limits[$provider];
    $min_interval = 1.0 / $rate_limit['requests_per_second'];
    
    $time_since_last = microtime(true) - $rate_limit['last_request_time'];
    
    if ($time_since_last < $min_interval) {
        $sleep_time = $min_interval - $time_since_last;
        
        // Add maximum sleep time to prevent blocking
        $max_sleep = 5.0; // 5 seconds maximum
        if ($sleep_time > $max_sleep) {
            self::log_message("Rate limit exceeded maximum wait time for provider: {$provider}");
            return false; // Fail the request instead of blocking indefinitely
        }
        
        usleep($sleep_time * 1000000);
    }
    
    self::$rate_limits[$provider]['last_request_time'] = microtime(true);
    return true;
}
```

## Implementation Checklist

- [ ] Replace direct file writing with secure logging
- [ ] Remove or secure debug output
- [ ] Improve SQL query construction
- [ ] Strengthen input validation
- [ ] Enhance address component sanitization
- [ ] Secure file inclusion statements
- [ ] Improve error handling
- [ ] Add security headers for admin pages
- [ ] Implement rate limiting enforcement
- [ ] Test all changes thoroughly
- [ ] Update documentation

## Testing Recommendations

1. **Unit Tests:** Add tests for all sanitization and validation functions
2. **Security Testing:** Test with malicious input patterns
3. **Integration Testing:** Verify WordPress compatibility
4. **Performance Testing:** Ensure security measures don't impact performance

---

*This document should be used in conjunction with the main security assessment report.*