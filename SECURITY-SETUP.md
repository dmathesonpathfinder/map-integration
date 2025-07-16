# Security Configuration Guide - Map Integration Plugin

## Quick Security Setup

After installing the security updates, follow these steps to ensure optimal security:

### 1. Environment Configuration

#### Production Environment
Add this to your `wp-config.php` for production sites:
```php
// Disable file logging in production
define('MAP_INTEGRATION_ALLOW_FILE_LOGGING', false);

// Enable security headers
define('MAP_INTEGRATION_SECURITY_HEADERS', true);
```

#### Development Environment
For development/testing environments:
```php
// Enable file logging for debugging
define('MAP_INTEGRATION_ALLOW_FILE_LOGGING', true);

// Set environment type
define('WP_ENVIRONMENT_TYPE', 'development');
```

### 2. Google API Key Security

When configuring your Google Maps API key:

1. **Restrict the API Key**:
   - Go to Google Cloud Console
   - Select your API key
   - Add HTTP referrer restrictions for your domain(s)
   - Limit to only the APIs you need (Geocoding API)

2. **Monitor Usage**:
   - Set up billing alerts
   - Monitor for unexpected usage spikes
   - Regularly review API key usage logs

### 3. File Permissions

Ensure proper file permissions on your server:
```bash
# WordPress uploads directory
chmod 755 wp-content/uploads/

# Plugin log directory (if enabled)
chmod 750 wp-content/uploads/map-integration-logs/

# Log files
chmod 640 wp-content/uploads/map-integration-logs/*.log
```

### 4. Security Headers Verification

Test your security headers using online tools:
- SecurityHeaders.com
- Mozilla Observatory
- OWASP ZAP

### 5. Regular Maintenance

#### Weekly
- Review plugin logs for suspicious activity
- Monitor failed login attempts
- Check for plugin updates

#### Monthly
- Review and rotate API keys if necessary
- Clean up old log files (automatic, but verify)
- Audit user permissions

#### Quarterly
- Perform security audit
- Update all WordPress components
- Review and update security policies

## Security Monitoring

### Log File Locations
If file logging is enabled:
- Main logs: `wp-content/uploads/map-integration-logs/geocode-YYYY-MM.log`
- Security events are logged with `[Security]` prefix

### Key Security Events to Monitor
- Failed validation attempts
- Suspicious address patterns
- Path traversal attempts
- Rate limit violations
- API key exposure attempts

### WordPress Security Plugins
Consider using additional security plugins:
- Wordfence Security
- Sucuri Security
- iThemes Security Pro

## Emergency Response

### If You Suspect a Security Breach

1. **Immediate Actions**:
   ```php
   // Add to wp-config.php to disable the plugin temporarily
   define('MAP_INTEGRATION_EMERGENCY_DISABLE', true);
   ```

2. **Investigation**:
   - Check plugin logs for suspicious activity
   - Review WordPress security logs
   - Scan for malware

3. **Containment**:
   - Change all passwords
   - Revoke and regenerate API keys
   - Update all plugins and WordPress core

4. **Recovery**:
   - Apply security patches
   - Test thoroughly
   - Re-enable plugin with monitoring

## Security Testing

### Running Security Tests
Navigate to WordPress admin and add `?page=map-integration-security-test` to test security functions.

### Manual Security Checks

1. **Input Validation Test**:
   ```
   Try entering malicious code in address fields:
   - <script>alert('xss')</script>
   - ../../etc/passwd
   - ${system('whoami')}
   ```

2. **File Access Test**:
   ```
   Try accessing log files directly:
   - yoursite.com/wp-content/uploads/map-integration-logs/
   ```

3. **AJAX Security Test**:
   ```
   Test AJAX endpoints without proper nonces
   Check for CSRF vulnerabilities
   ```

## Compliance Considerations

### GDPR/Privacy
- Geocoding may process personal data (addresses)
- Ensure proper privacy notices
- Implement data retention policies
- Consider data minimization

### OWASP Compliance
This plugin addresses:
- A1: Injection (SQL injection, log injection)
- A2: Broken Authentication (capability checks)
- A3: Sensitive Data Exposure (API key protection)
- A5: Broken Access Control (permission checks)
- A6: Security Misconfiguration (headers, defaults)
- A7: Cross-Site Scripting (output escaping)

## Support and Updates

### Getting Security Updates
- Watch the plugin repository for security releases
- Subscribe to security mailing lists
- Monitor CVE databases for related vulnerabilities

### Reporting Security Issues
If you discover a security vulnerability:
1. Do NOT post publicly
2. Contact plugin maintainers directly
3. Provide detailed reproduction steps
4. Allow time for responsible disclosure

## Advanced Security Configuration

### Custom Security Headers
```php
// Add custom headers via wp-config.php
add_action('send_headers', function() {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
});
```

### Rate Limiting Configuration
```php
// Adjust rate limiting in wp-config.php
define('MAP_INTEGRATION_RATE_LIMIT_REQUESTS', 10);
define('MAP_INTEGRATION_RATE_LIMIT_WINDOW', 60);
```

### Logging Configuration
```php
// Custom log settings
define('MAP_INTEGRATION_MAX_LOG_SIZE', 5242880); // 5MB
define('MAP_INTEGRATION_LOG_RETENTION_DAYS', 90);
```

Remember: Security is an ongoing process, not a one-time setup. Regularly review and update your security configuration as threats evolve.