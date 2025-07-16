<?php

/**
 * Plugin Name: Map Integration
 * Plugin URI: https://example.com/map-integration
 * Description: A minimal plugin for integrating maps into your WordPress site.
 * Version: 1.0.0
 * Author: Danny Dollars
 * License: GPL      
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: map-integration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MAP_INTEGRATION_VERSION', '1.0.0');
define('MAP_INTEGRATION_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MAP_INTEGRATION_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Security configuration constants
define('MAP_INTEGRATION_MAX_LOG_SIZE', 5242880); // 5MB
define('MAP_INTEGRATION_MAX_CACHE_ENTRIES', 10000);
define('MAP_INTEGRATION_RATE_LIMIT_WINDOW', 60); // 1 minute

// Load new classes and functions
require_once MAP_INTEGRATION_PLUGIN_PATH . 'includes/class-street-parser.php';
require_once MAP_INTEGRATION_PLUGIN_PATH . 'includes/class-geocoding-service.php';
require_once MAP_INTEGRATION_PLUGIN_PATH . 'includes/geocoding-functions.php';

/**
 * Geocoding Class for handling Nominatim API requests
 */
class MapGeocoder
{

    private static $last_request_time = 0;
    /**
     * Write to secure log using WordPress logging mechanism
     */
    public static function log_message($message)
    {
        // Sanitize message to prevent log injection
        $message = self::sanitize_log_message($message);
        
        // Use WordPress built-in logging when WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[Map Integration] %s', $message));
        }
        
        // Only allow file logging in secure environments
        if (!self::is_file_logging_safe()) {
            return;
        }
        
        // Alternative: Use WordPress uploads directory with proper security
        $upload_dir = wp_upload_dir();
        if (!$upload_dir || isset($upload_dir['error']) || !is_writable($upload_dir['basedir'])) {
            return;
        }
        
        $log_dir = $upload_dir['basedir'] . '/map-integration-logs/';
        
        // Validate log directory path
        $real_upload_dir = realpath($upload_dir['basedir']);
        $real_log_dir = realpath($log_dir);
        
        // Create directory if it doesn't exist
        if (!file_exists($log_dir)) {
            if (!wp_mkdir_p($log_dir)) {
                return;
            }
            // Create .htaccess to prevent direct access with more secure rules
            $htaccess_content = "# Deny all access to log files\n" .
                               "Order Deny,Allow\n" . 
                               "Deny from all\n" .
                               "<Files \"*.log\">\n" .
                               "    Deny from all\n" .
                               "</Files>\n";
            
            file_put_contents($log_dir . '.htaccess', $htaccess_content, LOCK_EX);
            // Set restrictive permissions
            chmod($log_dir, 0750);
        }
        
        // Additional path validation after directory creation
        $real_log_dir = realpath($log_dir);
        if (!$real_log_dir || !$real_upload_dir || strpos($real_log_dir, $real_upload_dir) !== 0) {
            return;
        }
        
        $log_file = $log_dir . 'geocode-' . sanitize_file_name(date('Y-m')) . '.log';
        $timestamp = current_time('mysql');
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
        
        // Validate file permissions and size before writing
        if (is_writable($log_dir) && (!file_exists($log_file) || filesize($log_file) < 5242880)) { // 5MB limit
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
            // Set restrictive file permissions
            if (file_exists($log_file)) {
                chmod($log_file, 0640);
            }
        }
    }

    /**
     * Sanitize log message to prevent log injection
     * 
     * @param string $message Raw log message
     * @return string Sanitized log message
     */
    private static function sanitize_log_message($message)
    {
        // Remove control characters and newlines to prevent log injection
        $message = preg_replace('/[\x00-\x1F\x7F]/', '', $message);
        
        // Limit message length
        $message = substr($message, 0, 1000);
        
        // Remove potentially dangerous patterns
        $message = str_replace(array('[', ']'), array('(', ')'), $message);
        
        return trim($message);
    }

    /**
     * Check if file logging is safe in current environment
     * 
     * @return bool Whether file logging should be allowed
     */
    private static function is_file_logging_safe()
    {
        // Disable file logging in production unless explicitly enabled
        if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production') {
            return defined('MAP_INTEGRATION_ALLOW_FILE_LOGGING') && MAP_INTEGRATION_ALLOW_FILE_LOGGING;
        }
        
        // Check if uploads directory is secure
        $upload_dir = wp_upload_dir();
        if (!$upload_dir || isset($upload_dir['error'])) {
            return false;
        }
        
        return true;
    }

    /**
     * Geocode an address using Nominatim with fallback strategies
     */
    public static function geocode_address($street, $city, $province)
    {
        // Validate and clean inputs
        $street = self::validate_address_input($street);
        $city = self::validate_address_input($city);
        $province = self::validate_address_input($province);
        
        // If any critical validation fails, return false
        if (($street !== false && $street !== null && $street !== '') && $street === false) {
            self::log_message("Address validation failed for street: " . substr($street, 0, 50));
            return false;
        }
        if (($city !== false && $city !== null && $city !== '') && $city === false) {
            self::log_message("Address validation failed for city: " . substr($city, 0, 50));
            return false;
        }
        if (($province !== false && $province !== null && $province !== '') && $province === false) {
            self::log_message("Address validation failed for province: " . substr($province, 0, 50));
            return false;
        }

        // Clean and normalize inputs
        $street = self::clean_address_part($street ?: '');
        $city = self::clean_address_part($city ?: '');
        $province = self::clean_address_part($province ?: '');

        // Try multiple address combinations in order of specificity
        $address_variations = self::build_address_variations($street, $city, $province);

        foreach ($address_variations as $address) {
            if (empty($address)) continue;

            self::log_message("Trying geocode for: " . substr($address, 0, 100));
            $coordinates = self::try_geocode($address);

            if ($coordinates) {
                self::log_message("Successfully geocoded: " . substr($address, 0, 100));
                return $coordinates;
            }
        }

        self::log_message("All geocoding attempts failed for: street=" . substr($street, 0, 50) . ", city=" . substr($city, 0, 50) . ", province=" . substr($province, 0, 50));
        return false;
    }

    /**
     * Clean and normalize address parts
     */
    private static function clean_address_part($part)
    {
        if (empty($part)) return '';

        // Remove extra whitespace and normalize
        $part = trim($part);
        $part = preg_replace('/\s+/', ' ', $part);

        // Common address cleanups
        $part = str_replace(['St.', 'St ', 'Street'], 'Street', $part);
        $part = str_replace(['Ave.', 'Ave ', 'Avenue'], 'Avenue', $part);
        $part = str_replace(['Rd.', 'Rd ', 'Road'], 'Road', $part);
        $part = str_replace(['Dr.', 'Dr ', 'Drive'], 'Drive', $part);
        $part = str_replace(['Blvd.', 'Blvd ', 'Boulevard'], 'Boulevard', $part);

        // Province normalization
        if (strtolower($part) === 'ns' || strtolower($part) === 'nova scotia') {
            $part = 'Nova Scotia';
        }

        return $part;
    }

    /**
     * Extract street address from full address line using regex
     * Removes suite numbers, unit numbers, apartment numbers, etc.
     */
    private static function extract_street_address($address)
    {
        if (empty($address)) return '';

        $address = trim($address);

        // Common patterns to remove (suite, unit, apt, etc.)
        $patterns_to_remove = array(
            // Suite/Unit patterns
            '/\b(suite|ste|unit|apt|apartment)\s*#?\s*\w+/i',
            '/\b#\s*\d+[a-z]?/i',
            // PO Box patterns
            '/\bpo\s*box\s*\d+/i',
            '/\bp\.?o\.?\s*box\s*\d+/i',
            // Floor patterns
            '/\b\d+(st|nd|rd|th)\s*floor\b/i',
            '/\bfloor\s*\d+/i',
            // Building/Room patterns
            '/\bbuilding\s*\w+/i',
            '/\broom\s*\w+/i',
            '/\brm\s*\w+/i',
            // Other common additions
            '/\b(rear|back|front)\b/i',
            '/\b(upper|lower|basement)\b/i'
        );

        $cleaned = $address;
        foreach ($patterns_to_remove as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }

        // Clean up extra spaces and commas
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = preg_replace('/,\s*,/', ',', $cleaned);
        $cleaned = trim($cleaned, ' ,');

        // If we removed too much, return original
        if (strlen($cleaned) < 3) {
            return $address;
        }

        return $cleaned;
    }

    /**
     * Build multiple address variations to try
     */
    private static function build_address_variations($street, $city, $province)
    {
        $variations = array();

        // Full address
        if (!empty($street) && !empty($city) && !empty($province)) {
            $variations[] = "{$street}, {$city}, {$province}, Canada";
            $variations[] = "{$street}, {$city}, {$province}";
        }

        // Try cleaned street address (remove suite numbers, etc.)
        if (!empty($street) && !empty($city) && !empty($province)) {
            $cleaned_street = self::extract_street_address($street);
            if ($cleaned_street !== $street && !empty($cleaned_street)) {
                $variations[] = "{$cleaned_street}, {$city}, {$province}, Canada";
                $variations[] = "{$cleaned_street}, {$city}, {$province}";
            }
        }

        // // Without street number (in case it's causing issues)
        // if (!empty($street) && !empty($city) && !empty($province)) {
        //     $street_no_number = preg_replace('/^\d+\s*/', '', $street);
        //     if ($street_no_number !== $street) {
        //         $variations[] = "{$street_no_number}, {$city}, {$province}, Canada";
        //     }

        //     // Also try cleaned version without street number
        //     $cleaned_street = self::extract_street_address($street);
        //     $cleaned_no_number = preg_replace('/^\d+\s*/', '', $cleaned_street);
        //     if ($cleaned_no_number !== $street_no_number && !empty($cleaned_no_number)) {
        //         $variations[] = "{$cleaned_no_number}, {$city}, {$province}, Canada";
        //     }
        // }

        // // City + Province only
        // if (!empty($city) && !empty($province)) {
        //     $variations[] = "{$city}, {$province}, Canada";
        //     $variations[] = "{$city}, {$province}";
        // }

        // // Province only as last resort
        // if (!empty($province)) {
        //     $variations[] = "{$province}, Canada";
        // }

        return array_unique(array_filter($variations));
    }

    /**
     * Try geocoding a single address string using multiple providers
     */
    private static function try_geocode($address)
    {
        // Try Google Geocoding API first (if API key is available)
        $google_api_key = get_option('map_integration_google_api_key', '');
        if (!empty($google_api_key)) {
            self::log_message("Trying Google Geocoding API first for: {$address}");
            $google_result = self::try_google_geocode($address, $google_api_key);
            if ($google_result) {
                self::log_message("Google Geocoding successful");
                return $google_result;
            }
            self::log_message("Google Geocoding failed, falling back to Nominatim");
        } else {
            self::log_message("No Google API key configured, using Nominatim only");
        }

        // Fallback to Nominatim (OpenStreetMap)
        self::log_message("Trying Nominatim for: {$address}");
        return self::try_nominatim_geocode($address);
    }

    /**
     * Try geocoding using Google Geocoding API
     */
    private static function try_google_geocode($address, $api_key)
    {
        // Rate limiting - Google allows more requests but let's be conservative
        $current_time = time();
        if ($current_time - self::$last_request_time < 0.1) {
            usleep(100000); // 0.1 second delay
        }
        self::$last_request_time = time();

        // Build Google Geocoding API request
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(array(
            'address' => $address,
            'region' => 'ca', // Bias results to Canada
            'key' => $api_key
        ));

        // Log request without exposing the API key
        $safe_url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(array(
            'address' => substr($address, 0, 50) . (strlen($address) > 50 ? '...' : ''),
            'region' => 'ca',
            'key' => '[API_KEY_HIDDEN]'
        ));
        self::log_message("Making Google API request to: " . $safe_url);

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'WordPress Map Integration Plugin'
            )
        ));

        if (is_wp_error($response)) {
            $error_code = $response->get_error_code();
            $error_message = $response->get_error_message();
            
            // Log detailed error for debugging
            self::log_message("Google API request failed - Code: {$error_code}");
            
            // Don't expose detailed error messages to users
            if (defined('WP_DEBUG') && WP_DEBUG) {
                self::log_message("Debug - Error details: {$error_message}");
            }
            
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $body = wp_remote_retrieve_body($response);

        self::log_message("Google API Response: Code {$response_code} - {$response_message}");

        if ($response_code !== 200) {
            self::log_message("Google API returned non-200 status. Response body: " . substr($body, 0, 500));
            return false;
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log_message("Google API JSON decode error: " . json_last_error_msg() . ". Raw response: " . substr($body, 0, 500));
            return false;
        }

        // Check Google API response status
        if (!isset($data['status'])) {
            self::log_message("Google API missing status field");
            return false;
        }

        if ($data['status'] !== 'OK') {
            self::log_message("Google API returned status: " . $data['status']);
            if (isset($data['error_message'])) {
                self::log_message("Google API error message: " . $data['error_message']);
            }
            return false;
        }

        if (empty($data['results'])) {
            self::log_message("Google API returned no results for address: {$address}");
            return false;
        }

        $result_data = $data['results'][0];
        if (!isset($result_data['geometry']['location'])) {
            self::log_message("Google API result missing geometry/location data");
            return false;
        }

        $location = $result_data['geometry']['location'];
        $result = array(
            'lat' => floatval($location['lat']),
            'lng' => floatval($location['lng']),
            'provider' => 'google'
        );

        // Add confidence information based on location type
        if (isset($result_data['geometry']['location_type'])) {
            $location_type = $result_data['geometry']['location_type'];
            switch ($location_type) {
                case 'ROOFTOP':
                    $result['confidence_score'] = 100;
                    break;
                case 'RANGE_INTERPOLATED':
                    $result['confidence_score'] = 90;
                    break;
                case 'GEOMETRIC_CENTER':
                    $result['confidence_score'] = 70;
                    break;
                case 'APPROXIMATE':
                    $result['confidence_score'] = 50;
                    break;
                default:
                    $result['confidence_score'] = 80;
            }
        }

        // Log additional details about the result
        $formatted_address = isset($result_data['formatted_address']) ? $result_data['formatted_address'] : 'N/A';
        $place_types = isset($result_data['types']) ? implode(', ', $result_data['types']) : 'N/A';
        $location_type = isset($result_data['geometry']['location_type']) ? $result_data['geometry']['location_type'] : 'N/A';

        self::log_message("Google geocoding successful: lat={$result['lat']}, lng={$result['lng']}, formatted_address='{$formatted_address}', location_type='{$location_type}', types='{$place_types}'");

        return $result;
    }

    /**
     * Try geocoding using Nominatim (OpenStreetMap)
     */
    private static function try_nominatim_geocode($address)
    {
        // Rate limiting - Nominatim allows 1 request per second
        $current_time = time();
        if ($current_time - self::$last_request_time < 1) {
            sleep(1);
        }
        self::$last_request_time = time();

        // Make API request
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query(array(
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'ca'
        ));

        self::log_message("Making Nominatim API request to: {$url}");

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'WordPress Map Integration Plugin'
            )
        ));

        if (is_wp_error($response)) {
            $error_code = $response->get_error_code();
            $error_message = $response->get_error_message();
            
            // Log detailed error for debugging
            self::log_message("Nominatim API request failed - Code: {$error_code}");
            
            // Don't expose detailed error messages to users
            if (defined('WP_DEBUG') && WP_DEBUG) {
                self::log_message("Debug - Error details: {$error_message}");
            }
            
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $body = wp_remote_retrieve_body($response);

        self::log_message("Nominatim API Response: Code {$response_code} - {$response_message}");

        if ($response_code !== 200) {
            self::log_message("Nominatim API returned non-200 status. Response body: " . substr($body, 0, 500));
            return false;
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log_message("Nominatim JSON decode error: " . json_last_error_msg() . ". Raw response: " . substr($body, 0, 500));
            return false;
        }

        self::log_message("Nominatim returned " . count($data) . " results");

        if (empty($data)) {
            self::log_message("No Nominatim results found for address: {$address}");
            return false;
        }

        if (!isset($data[0]['lat']) || !isset($data[0]['lon'])) {
            self::log_message("Invalid Nominatim result structure. First result: " . json_encode($data[0]));
            return false;
        }

        $result = array(
            'lat' => floatval($data[0]['lat']),
            'lng' => floatval($data[0]['lon']),
            'provider' => 'nominatim'
        );

        // Add confidence based on result class and type
        if (isset($data[0]['class']) && isset($data[0]['type'])) {
            $class = $data[0]['class'];
            $type = $data[0]['type'];

            if ($class === 'place' && in_array($type, ['house', 'building'])) {
                $result['confidence_score'] = 95;
            } elseif ($class === 'highway' || $class === 'place') {
                $result['confidence_score'] = 80;
            } else {
                $result['confidence_score'] = 60;
            }
        } else {
            $result['confidence_score'] = 70;
        }

        // Log additional details about the result
        $display_name = isset($data[0]['display_name']) ? $data[0]['display_name'] : 'N/A';
        $place_type = isset($data[0]['type']) ? $data[0]['type'] : 'N/A';
        self::log_message("Nominatim geocoding successful: lat={$result['lat']}, lng={$result['lng']}, display_name='{$display_name}', type='{$place_type}'");

        return $result;
    }

    /**
     * Save coordinates to user meta
     */
    public static function save_coordinates($user_id, $coordinates, $suffix = '')
    {
        if (!$coordinates) {
            return false;
        }

        $lat_key = 'mepr_clinic_lat' . $suffix;
        $lng_key = 'mepr_clinic_lng' . $suffix;
        $time_key = 'mepr_clinic_geocoded_at' . $suffix;
        $confidence_key = 'mepr_clinic_geo_confidence' . $suffix;
        $fallback_key = 'mepr_clinic_geo_fallback' . $suffix;
        $provider_key = 'mepr_clinic_geo_provider' . $suffix;

        // Handle both old format (lat/lng) and new format (latitude/longitude)
        $lat = isset($coordinates['lat']) ? $coordinates['lat'] : (isset($coordinates['latitude']) ? $coordinates['latitude'] : null);
        $lng = isset($coordinates['lng']) ? $coordinates['lng'] : (isset($coordinates['longitude']) ? $coordinates['longitude'] : null);

        if ($lat === null || $lng === null) {
            return false;
        }

        update_user_meta($user_id, $lat_key, $lat);
        update_user_meta($user_id, $lng_key, $lng);
        update_user_meta($user_id, $time_key, current_time('mysql'));

        MapGeocoder::log_message("Saved coordinates for user {$user_id} (suffix: {$suffix}): lat={$lat}, lng={$lng}");

        // Save additional metadata if available
        if (isset($coordinates['confidence_score'])) {
            update_user_meta($user_id, $confidence_key, $coordinates['confidence_score']);
        }

        if (isset($coordinates['fallback_used'])) {
            update_user_meta($user_id, $fallback_key, $coordinates['fallback_used']);
        }

        if (isset($coordinates['provider'])) {
            update_user_meta($user_id, $provider_key, $coordinates['provider']);
        }

        return true;
    }

    /**
     * Get stored coordinates for a user
     */
    public static function get_coordinates($user_id, $suffix = '')
    {
        $lat_key = 'mepr_clinic_lat' . $suffix;
        $lng_key = 'mepr_clinic_lng' . $suffix;
        $confidence_key = 'mepr_clinic_geo_confidence' . $suffix;
        $fallback_key = 'mepr_clinic_geo_fallback' . $suffix;
        $provider_key = 'mepr_clinic_geo_provider' . $suffix;
        $time_key = 'mepr_clinic_geocoded_at' . $suffix;

        $lat = get_user_meta($user_id, $lat_key, true);
        $lng = get_user_meta($user_id, $lng_key, true);

        if (empty($lat) || empty($lng)) {
            return false;
        }

        $result = array(
            'lat' => floatval($lat),
            'lng' => floatval($lng),
            'latitude' => floatval($lat),
            'longitude' => floatval($lng)
        );

        // Add additional metadata if available
        $confidence = get_user_meta($user_id, $confidence_key, true);
        if (!empty($confidence)) {
            $result['confidence_score'] = intval($confidence);
        }

        $fallback = get_user_meta($user_id, $fallback_key, true);
        if (!empty($fallback)) {
            $result['fallback_used'] = $fallback;
        }

        $geocoded_at = get_user_meta($user_id, $time_key, true);
        if (!empty($geocoded_at)) {
            $result['geocoded_at'] = $geocoded_at;
        }

        $provider = get_user_meta($user_id, $provider_key, true);
        if (!empty($provider)) {
            $result['provider'] = $provider;
        }

        return $result;
    }

    /**
     * Check if coordinates are province-level (too general)
     */
    public static function is_province_level_coordinate($lat, $suffix, $user_id)
    {
        $lng = get_user_meta($user_id, 'mepr_clinic_lng' . $suffix, true);

        // Check if recently geocoded (within last hour) - don't re-geocode
        $geocoded_at = get_user_meta($user_id, 'mepr_clinic_geocoded_at' . $suffix, true);
        if (!empty($geocoded_at)) {
            $geocoded_time = strtotime($geocoded_at);
            if ($geocoded_time && (time() - $geocoded_time) < 3600) { // 1 hour
                return false; // Recently geocoded, don't re-process
            }
        }

        // Check confidence score - if we have high confidence, don't re-geocode
        $confidence = get_user_meta($user_id, 'mepr_clinic_geo_confidence' . $suffix, true);
        if (!empty($confidence) && intval($confidence) >= 80) {
            return false; // High confidence coordinates should not be re-geocoded
        }

        // Check provider - Google and high-quality Nominatim results should not be re-geocoded
        $provider = get_user_meta($user_id, 'mepr_clinic_geo_provider' . $suffix, true);
        if ($provider === 'google') {
            return false; // Google results are typically high quality
        }

        // Nova Scotia province center coordinates (approximate)
        $ns_lat = 44.6820;
        $ns_lng = -63.7443;

        // If coordinates are very close to province center, consider them too general
        // Use much tighter threshold - only coordinates within ~5km of province center
        $lat_diff = abs(floatval($lat) - $ns_lat);
        $lng_diff = abs(floatval($lng) - $ns_lng);

        // 0.05 degrees is roughly 5km - much more specific than the previous 0.5 degrees (~55km)
        return ($lat_diff < 0.05 && $lng_diff < 0.05);
    }
}

/**
 * Main Map Integration Class
 */
class MapIntegration
{

    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    /**
     * Initialize the plugin
     */
    public function init()
    {
        // Add shortcode for displaying maps
        add_shortcode('map_integration', array($this, 'display_map_shortcode'));

        // Add shortcode for chiropractor directory
        add_shortcode('chiropractor_directory', array($this, 'display_chiropractor_directory'));

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Add security headers for admin pages
        add_action('admin_init', array($this, 'add_security_headers'));

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Hook into user meta updates to trigger geocoding
        add_action('updated_user_meta', array($this, 'handle_user_meta_update'), 10, 4);

        // Add AJAX handlers for bulk geocoding control
        add_action('wp_ajax_start_bulk_geocoding', array($this, 'ajax_start_bulk_geocoding'));
        add_action('wp_ajax_stop_bulk_geocoding', array($this, 'ajax_stop_bulk_geocoding'));
        add_action('wp_ajax_get_bulk_geocoding_status', array($this, 'ajax_get_bulk_geocoding_status'));

        // Add scheduled event handler for background processing
        add_action('process_geocoding_batch', array($this, 'process_geocoding_batch'));
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        // Create geocoding cache table
        $this->create_geocoding_cache_table();

        // Add any other activation logic here
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        // Add any deactivation logic here
        flush_rewrite_rules();
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_options_page(
            'Map Integration Settings',
            'Map Integration',
            'manage_options',
            'map-integration',
            array($this, 'admin_page')
        );

        // Add geocoding tools submenu
        add_submenu_page(
            'options-general.php',
            'Geocoding Tools',
            'Geocoding Tools',
            'manage_options',
            'map-integration-geocoding',
            array($this, 'geocoding_tools_page')
        );
    }
    /**
     * Admin page content
     */
    public function admin_page()
    {
        // Security check - ensure user has proper capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Handle Google API key setting
        if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'save_settings_action')) {
            $google_api_key = sanitize_text_field($_POST['google_api_key']);
            
            // Additional validation for API key format
            if (!empty($google_api_key)) {
                // Google API keys should be alphanumeric with specific length
                if (!preg_match('/^[A-Za-z0-9_-]{35,45}$/', $google_api_key)) {
                    echo '<div class="notice notice-error"><p>Invalid Google API key format. Please check your API key.</p></div>';
                } else {
                    update_option('map_integration_google_api_key', $google_api_key);
                    echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
                }
            } else {
                // Allow empty API key (disables Google geocoding)
                update_option('map_integration_google_api_key', '');
                echo '<div class="notice notice-success"><p>Google API key removed. Nominatim will be used for geocoding.</p></div>';
            }
        }

        // Enqueue admin scripts for AJAX functionality
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'mapIntegrationAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bulk_geocoding_control')
        ));

        // Handle bulk geocoding action
        if (isset($_POST['bulk_geocode']) && wp_verify_nonce($_POST['_wpnonce'], 'bulk_geocode_action')) {
            $this->bulk_geocode_users();
        }

        // Handle clear geocoding data action
        if (isset($_POST['clear_geocoding']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_geocoding_action')) {
            $this->clear_all_geocoding_data();
        }

        // Handle clear geocoding data action (duplicate handling removed)
        if (isset($_POST['clear_geocoding_data']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_geocoding_data_action')) {
            $this->clear_all_geocoding_data();
        }

        // Handle clear failed geocoding markers action
        if (isset($_POST['clear_failed_markers']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_failed_markers_action')) {
            $this->clear_all_failed_markers();
        }

        // Get geocoding statistics
        $stats = $this->get_geocoding_stats();

?>
        <div class="wrap">
            <h1>Map Integration Settings</h1>

            <h2>Geocoding Settings</h2>
            <form method="post">
                <?php wp_nonce_field('save_settings_action'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Google Maps API Key</th>
                        <td>
                            <input type="text" name="google_api_key" class="regular-text"
                                value="<?php echo esc_attr(get_option('map_integration_google_api_key', '')); ?>" />
                            <p class="description">
                                <strong>Google Geocoding API Key:</strong> If provided, Google will be used as the primary geocoding provider with Nominatim (OpenStreetMap) as fallback.
                                Google provides more accurate results but requires an API key.
                                <a href="https://developers.google.com/maps/documentation/geocoding/get-api-key" target="_blank">Get your API key here</a>.
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="save_settings" class="button-primary" value="Save Settings" />
                </p>
            </form>

            <h2>Geocoding Statistics</h2>
            <table class="widefat">
                <tr>
                    <td><strong>Users with Primary Address:</strong></td>
                    <td><?php echo $stats['primary_addresses']; ?></td>
                </tr>
                <tr>
                    <td><strong>Users with Primary Coordinates:</strong></td>
                    <td><?php echo $stats['primary_coordinates']; ?></td>
                </tr>
                <tr>
                    <td><strong>Users with Secondary Address:</strong></td>
                    <td><?php echo $stats['secondary_addresses']; ?></td>
                </tr>
                <tr>
                    <td><strong>Users with Secondary Coordinates:</strong></td>
                    <td><?php echo $stats['secondary_coordinates']; ?></td>
                </tr>
                <tr>
                    <td><strong>Users with Third Address:</strong></td>
                    <td><?php echo $stats['third_addresses']; ?></td>
                </tr>
                <tr>
                    <td><strong>Users with Third Coordinates:</strong></td>
                    <td><?php echo $stats['third_coordinates']; ?></td>
                </tr>
                <tr style="border-top: 2px solid #ddd;">
                    <td><strong>Geocoded by Google:</strong></td>
                    <td><?php echo $stats['google_geocoded']; ?></td>
                </tr>
                <tr>
                    <td><strong>Geocoded by Nominatim:</strong></td>
                    <td><?php echo $stats['nominatim_geocoded']; ?></td>
                </tr>
            </table>

            <h2>Addresses Not Geocoded</h2>
            <?php
            $non_geocoded = $this->get_non_geocoded_addresses();
            $non_geocoded_count = count($non_geocoded);
            ?>
            <p><strong>Total non-geocoded addresses: <?php echo $non_geocoded_count; ?></strong></p>
            <?php if (!empty($non_geocoded)): ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>User Name</th>
                            <th>Address Type</th>
                            <th>Street</th>
                            <th>City</th>
                            <th>Province</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($non_geocoded as $address): ?>
                            <tr>
                                <td><?php echo esc_html($address['user_id']); ?></td>
                                <td><?php echo esc_html($address['user_name']); ?></td>
                                <td><?php echo esc_html($address['type']); ?></td>
                                <td><?php echo esc_html($address['street']); ?></td>
                                <td><?php echo esc_html($address['city']); ?></td>
                                <td><?php echo esc_html($address['province']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>✅ All addresses have been geocoded successfully!</p>
            <?php endif; ?>

            <h2>Interactive Bulk Geocoding</h2>
            <p>Geocode all existing clinic addresses that don't have coordinates yet. This process can be started, stopped, and monitored in real-time.</p>

            <div id="bulk-geocoding-controls">
                <button id="start-geocoding" class="button button-primary">Start Bulk Geocoding</button>
                <button id="stop-geocoding" class="button" disabled>Stop Geocoding</button>
                <button id="refresh-status" class="button">Refresh Status</button>
            </div>

            <div id="geocoding-status" style="margin-top: 15px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                <h4>Status: <span id="status-indicator">Not running</span></h4>
                <div id="progress-info">
                    <p><strong>Processed:</strong> <span id="processed-count">0</span></p>
                    <p><strong>Successful:</strong> <span id="success-count">0</span></p>
                    <p><strong>Failed:</strong> <span id="failed-count">0</span></p>
                    <p><strong>Current:</strong> <span id="progress-message">Ready to start</span></p>
                </div>
            </div>

            <script>
                jQuery(document).ready(function($) {
                    var geocodingNonce = '<?php echo wp_create_nonce('bulk_geocoding_control'); ?>';
                    var statusCheckInterval;
                    var errorCount = 0;

                    // Start geocoding
                    $('#start-geocoding').click(function() {
                        var button = $(this);
                        button.prop('disabled', true).text('Starting...');

                        $.post(ajaxurl, {
                            action: 'start_bulk_geocoding',
                            nonce: geocodingNonce
                        }, function(response) {
                            if (response.success) {
                                $('#stop-geocoding').prop('disabled', false);
                                $('#status-indicator').text('Starting...');
                                startStatusUpdates();
                                button.text('Start Bulk Geocoding');
                            } else {
                                alert('Error: ' + response.data);
                                button.prop('disabled', false).text('Start Bulk Geocoding');
                            }
                        }).fail(function() {
                            alert('Network error occurred. Please try again.');
                            button.prop('disabled', false).text('Start Bulk Geocoding');
                        });
                    });

                    // Stop geocoding
                    $('#stop-geocoding').click(function() {
                        if (!confirm('Are you sure you want to stop the geocoding process?')) {
                            return;
                        }

                        var button = $(this);
                        button.prop('disabled', true).text('Stopping...');

                        $.post(ajaxurl, {
                            action: 'stop_bulk_geocoding',
                            nonce: geocodingNonce
                        }, function(response) {
                            if (response.success) {
                                $('#start-geocoding').prop('disabled', false);
                                stopStatusUpdates();
                                button.text('Stop Geocoding');
                            } else {
                                alert('Error: ' + response.data);
                                button.prop('disabled', false).text('Stop Geocoding');
                            }
                        }).fail(function() {
                            alert('Network error occurred. Please try again.');
                            button.prop('disabled', false).text('Stop Geocoding');
                        });
                    });

                    // Refresh status
                    $('#refresh-status').click(function() {
                        updateStatus();
                    });

                    // Update status function
                    function updateStatus() {
                        $.post(ajaxurl, {
                            action: 'get_bulk_geocoding_status',
                            nonce: geocodingNonce
                        }, function(response) {
                            if (response.success) {
                                var status = response.data;
                                $('#status-indicator').text(status.running ? 'Running' : 'Not running');
                                $('#processed-count').text(status.total_processed || 0);
                                $('#success-count').text(status.total_success || 0);
                                $('#failed-count').text(status.total_failed || 0);
                                $('#progress-message').text(status.progress_message || 'Ready');

                                // Update button states
                                $('#start-geocoding').prop('disabled', status.running);
                                $('#stop-geocoding').prop('disabled', !status.running);

                                // Auto-stop status updates if not running
                                if (!status.running && statusCheckInterval) {
                                    stopStatusUpdates();
                                }

                                // Reset error count on successful update
                                errorCount = 0;
                            } else {
                                console.error('Status update error:', response.data);
                                errorCount++;
                                if (errorCount > 3) {
                                    stopStatusUpdates();
                                    $('#progress-message').text('Error getting status - stopped monitoring');
                                }
                            }
                        }).fail(function() {
                            console.error('Network error during status update');
                            errorCount++;
                            if (errorCount > 3) {
                                stopStatusUpdates();
                                $('#progress-message').text('Network error - stopped monitoring');
                            }
                        });
                    }

                    // Start status updates
                    function startStatusUpdates() {
                        updateStatus();
                        statusCheckInterval = setInterval(updateStatus, 3000); // Update every 3 seconds
                    }

                    // Stop status updates
                    function stopStatusUpdates() {
                        if (statusCheckInterval) {
                            clearInterval(statusCheckInterval);
                            statusCheckInterval = null;
                        }
                    }

                    // Initial status check
                    updateStatus();
                });
            </script>

            <h3>Legacy Bulk Geocoding</h3>
            <p><em>For one-time batch processing (old method):</em></p>
            <form method="post" action="">
                <?php wp_nonce_field('bulk_geocode_action'); ?>
                <input type="submit" name="bulk_geocode" class="button button-secondary" value="Run Legacy Bulk Geocoding"
                    onclick="return confirm('This may take a while and cannot be interrupted. Use the Interactive Bulk Geocoding above instead. Continue?');">
                <p><em>Note: This is the old method that respects batch limits and cannot be interrupted.</em></p>
            </form>

            <h2>Clear Geocoding Data</h2>
            <p>Remove all geocoding data (coordinates, timestamps) from all users.</p>
            <form method="post" action="">
                <?php wp_nonce_field('clear_geocoding_data_action'); ?>
                <input type="submit" name="clear_geocoding_data" class="button button-danger" value="Clear All Geocoding Data"
                    onclick="return confirm('Are you sure you want to clear all geocoding data from all users? This action cannot be undone.');">
            </form>

            <h2>Clear Failed Geocoding Markers</h2>
            <p>Clear all failed geocoding markers to allow retry of previously failed addresses.</p>
            <form method="post" action="">
                <?php wp_nonce_field('clear_failed_markers_action'); ?>
                <input type="submit" name="clear_failed_markers" class="button button-secondary" value="Clear Failed Markers"
                    onclick="return confirm('This will allow the system to retry geocoding previously failed addresses. Continue?');">
            </form>

            <h2>Usage</h2>
            <p>The plugin automatically geocodes clinic addresses when they are updated.</p>

            <h3>Map Shortcodes</h3>
            <p>Use these shortcodes to display maps and listings:</p>
            <ul>
                <li><code>[map_integration]</code> - Display all clinic locations on an interactive map</li>
                <li><code>[map_integration show_listings="true"]</code> - Display clinic listings only</li>
                <li><code>[map_integration show_listings="true" show_clinics="true"]</code> - Display both listings and map</li>
                <li><code>[map_integration width="800px" height="500px"]</code> - Custom size map</li>
                <li><code>[map_integration center_lat="44.6488" center_lng="-63.5752" zoom="8"]</code> - Custom center and zoom</li>
                <li><code>[map_integration show_clinics="false" location="Custom Location"]</code> - Legacy placeholder map</li>
            </ul>

            <h3>Chiropractor Directory Shortcodes</h3>
            <p>Use these shortcodes to display searchable chiropractor directories:</p>
            <ul>
                <li><code>[chiropractor_directory]</code> - Display searchable chiropractor directory</li>
                <li><code>[chiropractor_directory show_search="false"]</code> - Directory without search</li>
                <li><code>[chiropractor_directory include_map="true"]</code> - Directory with integrated map</li>
                <li><code>[chiropractor_directory show_map_links="false"]</code> - Directory without map links</li>
                <li><code>[chiropractor_directory show_contact="false"]</code> - Directory without contact info</li>
                <li><code>[chiropractor_directory user_role="subscriber"]</code> - Filter by user role</li>
            </ul>

            <h3>Data Storage</h3>
            <p>Coordinates are stored in user meta fields:</p>
            <ul>
                <li><code>mepr_clinic_lat</code>, <code>mepr_clinic_lng</code> - Primary clinic coordinates</li>
                <li><code>mepr_clinic_lat_2</code>, <code>mepr_clinic_lng_2</code> - Secondary clinic coordinates</li>
                <li><code>mepr_clinic_lat_3</code>, <code>mepr_clinic_lng_3</code> - Third clinic coordinates</li>
            </ul>
        </div>
<?php
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts()
    {
        wp_enqueue_style(
            'map-integration-style',
            MAP_INTEGRATION_PLUGIN_URL . 'assets/style.css',
            array(),
            MAP_INTEGRATION_VERSION
        );

        // Enqueue chiropractor directory styles
        wp_enqueue_style(
            'chiropractor-directory-style',
            MAP_INTEGRATION_PLUGIN_URL . 'assets/chiropractor-directory.css',
            array(),
            MAP_INTEGRATION_VERSION
        );

        // Enqueue chiropractor directory script
        wp_enqueue_script(
            'chiropractor-directory-script',
            MAP_INTEGRATION_PLUGIN_URL . 'assets/chiropractor-directory.js',
            array('jquery'),
            MAP_INTEGRATION_VERSION,
            true
        );

        // Enqueue Font Awesome for search icons
        wp_enqueue_style(
            'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
            array(),
            '6.0.0'
        );

        // Enqueue Leaflet CSS first to prevent white grid lines
        wp_enqueue_style(
            'leaflet-css',
            MAP_INTEGRATION_PLUGIN_URL . 'assets/leaflet.css',
            array(),
            '1.9.4'
        );

        // Enqueue Leaflet JavaScript with CSS dependency
        wp_enqueue_script(
            'leaflet-js',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            array(),
            '1.9.4',
            true
        );

        // Enqueue Leaflet Search CSS and JS
        wp_enqueue_style(
            'leaflet-search-css',
            'https://unpkg.com/leaflet-search@3.0.8/dist/leaflet-search.min.css',
            array('leaflet-css'),
            '3.0.8'
        );
        wp_enqueue_script(
            'leaflet-search-js',
            'https://unpkg.com/leaflet-search@3.0.8/dist/leaflet-search.min.js',
            array('leaflet-js'),
            '3.0.8',
            true
        );
    }
    /**
     * Display map shortcode
     */
    public function display_map_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'width' => '100%',
            'height' => '400px',
            'location' => 'Halifax, NS',
            'show_clinics' => 'true',
            'show_listings' => 'false',
            'center_lat' => '44.6488', // Default to Nova Scotia
            'center_lng' => '-63.5752',
            'zoom' => '7',
            'user_role' => 'subscriber' // Default to subscribers only
        ), $atts);

        $output = '';

        // Show listings if requested
        if ($atts['show_listings'] === 'true') {
            $output .= $this->display_clinic_listings($atts);
        }

        // Show map if requested
        if ($atts['show_clinics'] === 'true') {
            $output .= $this->display_clinic_map($atts);
        } else if ($atts['show_listings'] !== 'true') {
            // Legacy placeholder map
            $output = '<div class="map-integration-container" style="width: ' . esc_attr($atts['width']) . '; height: ' . esc_attr($atts['height']) . ';">';
            $output .= '<div class="map-placeholder">';
            $output .= '<p>Map Integration Placeholder</p>';
            $output .= '<p>Location: ' . esc_html($atts['location']) . '</p>';
            $output .= '<p><em>Connect your preferred map service API to display interactive maps here.</em></p>';
            $output .= '</div>';
            $output .= '</div>';
        }

        return $output;
    }
    /**
     * Display interactive clinic map with Leaflet.js
     */
    public function display_clinic_map($atts)
    {
        // Get all clinic locations with coordinates (filtered by user role)
        $clinic_data = $this->get_all_clinic_coordinates($atts['user_role']);

        // Sanitize clinic data for safe output in JavaScript
        $sanitized_clinic_data = array();
        foreach ($clinic_data as $clinic) {
            $sanitized_clinic_data[] = array(
                'lat' => floatval($clinic['lat']),
                'lng' => floatval($clinic['lng']),
                'name' => sanitize_text_field($clinic['name']),
                'address' => sanitize_text_field($clinic['address']),
                'phone' => sanitize_text_field($clinic['phone']),
                'email' => sanitize_email($clinic['email']),
                'website' => esc_url_raw($clinic['website']),
                'user_id' => intval($clinic['user_id']),
                'suffix' => sanitize_text_field($clinic['suffix'])
            );
        }

        // Only output debug information in development environment
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
            echo '<script>console.log("Clinic Data:", ' . wp_json_encode($sanitized_clinic_data) . ');</script>';
        }

        // Generate unique map ID
        $map_id = 'map-integration-' . wp_generate_uuid4();

        // Create map HTML
        $output = '<div id="' . esc_attr($map_id) . '" class="map-integration-leaflet" style="width: ' . esc_attr($atts['width']) . '; height: ' . esc_attr($atts['height']) . ';"></div>';
        
        // Generate secure JavaScript using wp_json_encode for proper escaping
        $output .= '<script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            function initializeMap() {
                if (typeof L === "undefined" || typeof L.Control === "undefined") {
                    setTimeout(initializeMap, 100);
                    return;
                }
                try {
                    var mapId = ' . wp_json_encode($map_id) . ';
                    var centerLat = ' . floatval($atts['center_lat']) . ';
                    var centerLng = ' . floatval($atts['center_lng']) . ';
                    var zoomLevel = ' . intval($atts['zoom']) . ';
                    
                    var map = L.map(mapId).setView([centerLat, centerLng], zoomLevel);
                    var tileLayer = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                        attribution: "&copy; <a href=\"https://www.openstreetmap.org/copyright\">OpenStreetMap</a> contributors",
                        maxZoom: 18
                    });
                    tileLayer.addTo(map);
                    
                    var clinics = ' . wp_json_encode($sanitized_clinic_data) . ';
                    var bounds = [];
                    var markers = [];
                    
                    clinics.forEach(function(clinic, index) {
                        if (clinic.lat && clinic.lng) {
                            var marker = L.marker([clinic.lat, clinic.lng]).addTo(map);
                            
                            // Build popup content with proper escaping
                            var popupContent = "";
                            if (clinic.name) {
                                popupContent += "<h4>" + clinic.name + "</h4>";
                            }
                            if (clinic.address) {
                                popupContent += "<p>" + clinic.address + "</p>";
                            }
                            if (clinic.phone) {
                                popupContent += "<p>Phone: <a href=\"tel:" + clinic.phone + "\">" + clinic.phone + "</a></p>";
                            }
                            if (clinic.email) {
                                popupContent += "<p>Email: <a href=\"mailto:" + clinic.email + "\">" + clinic.email + "</a></p>";
                            }
                            if (clinic.website) {
                                popupContent += "<p>Website: <a href=\"" + clinic.website + "\" target=\"_blank\" rel=\"noopener noreferrer\">" + clinic.website + "</a></p>";
                            }
                            
                            marker.bindPopup(popupContent);
                            marker.clinicName = clinic.name || "";
                            markers.push(marker);
                            bounds.push([clinic.lat, clinic.lng]);
                        }
                    });
                    
                    if (bounds.length > 0) {
                        map.fitBounds(bounds, {padding: [20, 20]});
                    }
                    
                    if (typeof L.Control.Search !== "undefined") {
                        var markerLayer = L.layerGroup(markers);
                        var searchControl = new L.Control.Search({
                            layer: markerLayer,
                            propertyName: "clinicName",
                            marker: false,
                            moveToLocation: function(latlng, title, map) {
                                map.setView(latlng, 14);
                            }
                        });
                        searchControl.on("search:locationfound", function(e) {
                            if (e.layer._popup) e.layer.openPopup();
                        });
                        map.addControl(searchControl);
                    }
                    
                    setTimeout(function() { 
                        if (map) { 
                            map.invalidateSize(); 
                        } 
                    }, 250);

                    // Add global function to center on a clinic by name
                    window.centerMapOnClinic = function(clinicName) {
                        if (!clinicName || typeof clinicName !== "string") {
                            return false;
                        }
                        
                        var found = false;
                        markers.forEach(function(marker) {
                            if (marker.clinicName && marker.clinicName.toLowerCase() === clinicName.toLowerCase()) {
                                // First scroll to the map
                                var mapElement = document.getElementById(mapId);
                                if (mapElement) {
                                    mapElement.scrollIntoView({ 
                                        behavior: "smooth", 
                                        block: "center" 
                                    });
                                }
                                
                                // Then center the map on the clinic
                                setTimeout(function() {
                                    map.setView(marker.getLatLng(), 13, {
                                        animate: true,
                                        duration: 1.5
                                    });
                                    setTimeout(function() {
                                        marker.openPopup();
                                    }, 1600);
                                }, 500);
                                found = true;
                                return false;
                            }
                        });
                        
                        if (!found) {
                            console.log("Available clinics:", markers.map(function(m) { return m.clinicName; }));
                            alert("Clinic \"" + clinicName + "\" not found on map.");
                        }
                    };
                } catch (error) {
                    console.error("Map initialization error:", error);
                    var mapElement = document.getElementById(mapId);
                    if (mapElement) {
                        mapElement.innerHTML = "<div style=\"padding: 20px; text-align: center; color: #666;\">Error loading map. Please refresh the page.</div>";
                    }
                }
            }
            initializeMap();
        });
        </script>';

        return $output;
    }
    /**
     * Get all clinic coordinates for map display
     */
    public function get_all_clinic_coordinates($user_role = 'subscriber')
    {
        $clinic_data = array();

        // Define address sets for primary, secondary, and third addresses
        $address_sets = array(
            '' => 'Primary',
            '_2' => 'Secondary',
            '_3' => 'Third'
        );

        foreach ($address_sets as $suffix => $label) {
            $lat_key = 'mepr_clinic_lat' . $suffix;
            $lng_key = 'mepr_clinic_lng' . $suffix;
            $province_key = 'mepr_clinic_province' . $suffix;
            $street_key = 'mepr_clinic_street' . $suffix;
            $city_key = 'mepr_clinic_city' . $suffix;
            $name_key = 'mepr_clinic_name' . $suffix;
            $phone_key = 'mepr_clinic_phone' . $suffix;
            $email_key = 'mepr_clinic_email_address' . $suffix;
            $website_key = 'mepr_clinic_website' . $suffix;

            $args = array(
                'role'    => $user_role,
                'meta_query' => array(
                    array(
                        'key'     => $lat_key,
                        'compare' => 'EXISTS',
                    ),
                    array(
                        'key'     => $lng_key,
                        'compare' => 'EXISTS',
                    ),
                    array(
                        'key'     => $province_key,
                        'value'   => array('ns', 'NS', 'nova scotia', 'Nova Scotia'),
                        'compare' => 'IN',
                    ),
                ),
                'fields' => 'all',
            );

            $users = get_users($args);

            foreach ($users as $user) {
                // Filter out users who don't have pw_user_status equal to "approved"
                $user_status = get_user_meta($user->ID, 'pw_user_status', true);
                if ($user_status !== 'approved') {
                    continue;
                }

                // Filter out users who have "Student Membership" subscription type
                if (class_exists('MeprUser')) {
                    $mepr_user = new MeprUser($user->ID);
                    $active_memberships = $mepr_user->active_product_subscriptions('products');
                    $has_student_membership = false;
                    foreach ($active_memberships as $membership) {
                        if (isset($membership->post_title) && $membership->post_title === 'Student Membership') {
                            $has_student_membership = true;
                            break;
                        }
                    }
                    if ($has_student_membership) {
                        continue;
                    }
                }

                $lat = get_user_meta($user->ID, $lat_key, true);
                $lng = get_user_meta($user->ID, $lng_key, true);
                $street = get_user_meta($user->ID, $street_key, true);
                $city = get_user_meta($user->ID, $city_key, true);
                $province = get_user_meta($user->ID, $province_key, true);
                $name = get_user_meta($user->ID, $name_key, true);
                $phone = get_user_meta($user->ID, $phone_key, true);
                $email = get_user_meta($user->ID, $email_key, true);
                $website = get_user_meta($user->ID, $website_key, true);

                $address_parts = array_filter(array($street, $city, $province));
                $address = implode(', ', $address_parts);

                $clinic_data[] = array(
                    'user_id' => $user->ID,
                    'lat' => floatval($lat),
                    'lng' => floatval($lng),
                    'name' => $name ?: ($user->display_name . ' (' . $label . ')'),
                    'address' => $address,
                    'phone' => $phone,
                    'email' => $email,
                    'website' => $website,
                    'suffix' => $suffix
                );
            }
        }

        return $clinic_data;
    }

    /**
     * Display clinic listings with improved UI
     */
    public function display_clinic_listings($atts)
    {
        // Get all clinic locations with coordinates (filtered by user role)
        $clinic_data = $this->get_all_clinic_coordinates($atts['user_role']);

        if (empty($clinic_data)) {
            return '<div class="clinic-listings-container"><p>No clinic locations found.</p></div>';
        }

        // Group clinics by user (chiropractor) to handle multiple locations
        $grouped_clinics = array();
        foreach ($clinic_data as $clinic) {
            $user_id = $clinic['user_id'];
            if (!isset($grouped_clinics[$user_id])) {
                $grouped_clinics[$user_id] = array(
                    'user_id' => $user_id,
                    'chiropractor_name' => '',
                    'locations' => array()
                );
            }

            // Extract chiropractor name (remove location suffix)
            $chiropractor_name = $clinic['name'];
            if (preg_match('/^(.+?)\s*\((?:Primary|Secondary|Third)\)$/', $chiropractor_name, $matches)) {
                $chiropractor_name = trim($matches[1]);
            }

            if (empty($grouped_clinics[$user_id]['chiropractor_name'])) {
                $grouped_clinics[$user_id]['chiropractor_name'] = $chiropractor_name;
            }

            $grouped_clinics[$user_id]['locations'][] = $clinic;
        }

        // Generate unique map ID for the listings to work with
        $map_id = 'map-integration-' . uniqid();

        $output = '<div class="clinic-listings-container">';

        foreach ($grouped_clinics as $group) {
            $chiropractor_name = $group['chiropractor_name'];
            $locations = $group['locations'];

            // Determine if this chiropractor has multiple locations
            $has_multiple_locations = count($locations) > 1;

            if ($has_multiple_locations) {
                // Group display for multiple locations
                $output .= '<div class="clinic-group clinic-group-multiple">';
                $output .= '<div class="clinic-group-header">';
                $output .= '<h3 class="chiropractor-name">' . esc_html($chiropractor_name) . '</h3>';
                $output .= '<p class="multiple-locations-note">' . count($locations) . ' locations</p>';
                $output .= '</div>';

                foreach ($locations as $location) {
                    $output .= $this->render_single_location($location, $chiropractor_name, true);
                }

                $output .= '</div>'; // Close clinic-group-multiple
            } else {
                // Single location display
                $output .= '<div class="clinic-group clinic-group-single">';
                $output .= $this->render_single_location($locations[0], $chiropractor_name, false);
                $output .= '</div>'; // Close clinic-group-single
            }
        }

        $output .= '</div>'; // Close clinic-listings-container

        // Add a script to handle clicks when no map is present
        $output .= '<script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            // Provide fallback if centerMapOnClinic is not defined (no map present)
            if (typeof window.centerMapOnClinic === "undefined") {
                window.centerMapOnClinic = function(clinicName) {
                    alert("Map not available. Clinic: " + clinicName);
                };
            }
        });
        </script>';

        return $output;
    }

    /**
     * Render a single clinic location listing
     */
    private function render_single_location($location, $chiropractor_name, $is_grouped = false)
    {
        $clinic_name = $location['name'];
        $address = $location['address'];
        $phone = $location['phone'];
        $email = $location['email'];
        $website = $location['website'];

        // Extract location name from clinic name if it has a suffix
        $location_name = '';
        if (preg_match('/\(([^)]+)\)$/', $clinic_name, $matches)) {
            $location_name = $matches[1];
        }

        $output = '<div class="clinic-listing">';

        if (!$is_grouped) {
            // For single locations, show chiropractor name prominently
            $output .= '<h3 class="chiropractor-name">';
            $output .= '<a href="#" class="clinic-link" onclick="centerMapOnClinic(\'' . esc_js($clinic_name) . '\'); return false;">';
            $output .= esc_html($chiropractor_name);
            $output .= '</a>';
            $output .= '</h3>';
        } else {
            // For grouped locations, show location name as clickable
            if ($location_name) {
                $output .= '<h4 class="location-name">';
                $output .= '<a href="#" class="clinic-link" onclick="centerMapOnClinic(\'' . esc_js($clinic_name) . '\'); return false;">';
                $output .= esc_html($location_name) . ' Location';
                $output .= '</a>';
                $output .= '</h4>';
            }
        }

        if ($address) {
            $output .= '<p class="clinic-address">' . esc_html($address) . '</p>';
        }

        if ($phone) {
            $output .= '<p class="clinic-phone">Phone: ' . esc_html($phone) . '</p>';
        }

        if ($email) {
            $output .= '<p class="clinic-email">Email: <a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a></p>';
        }

        if ($website) {
            $output .= '<p class="clinic-website"><a href="' . esc_url($website) . '" target="_blank">Visit Website</a></p>';
        }

        $output .= '</div>'; // Close clinic-listing

        return $output;
    }

    /**
     * Handle user meta updates and trigger geocoding for clinic addresses
     */
    public function handle_user_meta_update($meta_id, $user_id, $meta_key, $meta_value)
    {
        // Define the clinic address fields we're watching
        $address_fields = array(
            '' => array('mepr_clinic_street', 'mepr_clinic_city', 'mepr_clinic_province'),
            '_2' => array('mepr_clinic_street_2', 'mepr_clinic_city_2', 'mepr_clinic_province_2'),
            '_3' => array('mepr_clinic_street_3', 'mepr_clinic_city_3', 'mepr_clinic_province_3')
        );

        // Check if the updated field is one of our clinic address fields
        $needs_geocoding = false;
        $address_suffix = '';

        foreach ($address_fields as $suffix => $fields) {
            if (in_array($meta_key, $fields)) {
                $needs_geocoding = true;
                $address_suffix = $suffix;
                break;
            }
        }

        if (!$needs_geocoding) {
            return;
        }

        // Get all address components for this address set
        $street_key = 'mepr_clinic_street' . $address_suffix;
        $city_key = 'mepr_clinic_city' . $address_suffix;
        $province_key = 'mepr_clinic_province' . $address_suffix;

        $street = get_user_meta($user_id, $street_key, true);
        $city = get_user_meta($user_id, $city_key, true);
        $province = get_user_meta($user_id, $province_key, true);

        // Only geocode if we have street or city data (exclude province-only addresses)
        if (empty($street) && empty($city)) {
            return;
        }

        // Clear any previous failed markers since the address was updated
        $this->clear_geocoding_failed($user_id, $address_suffix);

        // Geocode the address
        $coordinates = MapGeocoder::geocode_address($street, $city, $province);
        if ($coordinates) {
            MapGeocoder::save_coordinates($user_id, $coordinates, $address_suffix);
            MapGeocoder::log_message("Successfully geocoded address for user {$user_id} (suffix: {$address_suffix})");
        } else {
            // Mark as failed to prevent immediate re-processing
            $this->mark_geocoding_failed($user_id, $address_suffix);
            MapGeocoder::log_message("Failed to geocode address for user {$user_id} (suffix: {$address_suffix}) - marked as failed");
        }
    }

    /**
     * Get geocoding statistics
     */
    public function get_geocoding_stats()
    {
        global $wpdb;

        $stats = array();

        // Count users with each type of address
        $stats['primary_addresses'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('mepr_clinic_street', 'mepr_clinic_city') 
            AND meta_value != ''
        ");

        $stats['secondary_addresses'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('mepr_clinic_street_2', 'mepr_clinic_city_2') 
            AND meta_value != ''
        ");

        $stats['third_addresses'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('mepr_clinic_street_3', 'mepr_clinic_city_3') 
            AND meta_value != ''
        ");

        // Count users with coordinates
        $stats['primary_coordinates'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'mepr_clinic_lat' AND meta_value != ''
        ");

        $stats['secondary_coordinates'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'mepr_clinic_lat_2' AND meta_value != ''
        ");

        $stats['third_coordinates'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'mepr_clinic_lat_3' AND meta_value != ''
        ");

        // Count geocoding providers
        $stats['google_geocoded'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('mepr_clinic_geo_provider', 'mepr_clinic_geo_provider_2', 'mepr_clinic_geo_provider_3') 
            AND meta_value = 'google'
        ");

        $stats['nominatim_geocoded'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('mepr_clinic_geo_provider', 'mepr_clinic_geo_provider_2', 'mepr_clinic_geo_provider_3') 
            AND meta_value = 'nominatim'
        ");

        return $stats;
    }
    /**
     * Bulk geocoding all users with addresses but no coordinates (with safety limits)
     */
    /**
     * Get addresses that haven't been geocoded yet
     */
    public function get_non_geocoded_addresses()
    {
        global $wpdb;

        $non_geocoded = array();
        $address_sets = array(
            '' => 'Primary',
            '_2' => 'Secondary',
            '_3' => 'Third'
        );

        foreach ($address_sets as $suffix => $label) {
            $street_key = 'mepr_clinic_street' . $suffix;
            $city_key = 'mepr_clinic_city' . $suffix;
            $province_key = 'mepr_clinic_province' . $suffix;
            $lat_key = 'mepr_clinic_lat' . $suffix;
            $failed_key = 'mepr_clinic_geocode_failed' . $suffix;

            // Get users with address data but no coordinates - using prepared statements
            $query = "
                SELECT DISTINCT u.ID, u.display_name
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
                WHERE (um.meta_key = %s OR um.meta_key = %s) 
                AND um.meta_value != ''
                AND u.ID NOT IN (
                    SELECT user_id FROM {$wpdb->usermeta} 
                    WHERE meta_key = %s AND meta_value != ''
                )
                AND u.ID NOT IN (
                    SELECT user_id FROM {$wpdb->usermeta} 
                    WHERE meta_key = %s AND meta_value != ''
                )
                LIMIT 1000
            ";
            
            $users = $wpdb->get_results($wpdb->prepare(
                $query,
                $street_key,
                $city_key,
                $lat_key,
                $failed_key
            ));

            foreach ($users as $user) {
                // Validate user ID
                $user_id = self::validate_user_id($user->ID);
                if (!$user_id) {
                    continue;
                }

                $street = get_user_meta($user_id, $street_key, true);
                $city = get_user_meta($user_id, $city_key, true);
                $province = get_user_meta($user_id, $province_key, true);
                $lat = get_user_meta($user_id, $lat_key, true);
                $lng = get_user_meta($user_id, 'mepr_clinic_lng' . $suffix, true);

                // Only include addresses with street or city data (exclude province-only)
                if (!empty($street) || !empty($city)) {
                    // Use the same logic as our background geocoding to determine if coordinates are needed
                    $needs_geocoding = false;

                    if (empty($lat) || empty($lng) || floatval($lat) == 0 || floatval($lng) == 0) {
                        // No coordinates or invalid coordinates
                        $needs_geocoding = true;
                    } elseif (MapGeocoder::is_province_level_coordinate($lat, $suffix, $user_id)) {
                        // Province-level coordinates that need improvement
                        $needs_geocoding = true;
                    }

                    if ($needs_geocoding) {
                        $non_geocoded[] = array(
                            'user_id' => $user_id,
                            'user_name' => sanitize_text_field($user->display_name),
                            'type' => $label,
                            'street' => sanitize_text_field($street),
                            'city' => sanitize_text_field($city),
                            'province' => sanitize_text_field($province)
                        );
                    }
                }
            }
        }

        return $non_geocoded;
    }

    /**
     * Clear all geocoding metadata from all users
     */
    public function clear_all_geocoding_data()
    {
        global $wpdb;

        MapGeocoder::log_message("Starting to clear all geocoding data");

        // Define all geocoding meta keys to remove
        $geocoding_keys = array(
            'mepr_clinic_lat',
            'mepr_clinic_lng',
            'mepr_clinic_geocoded_at',
            'mepr_clinic_geo_confidence',
            'mepr_clinic_geo_fallback',
            'mepr_clinic_geo_provider',
            'mepr_clinic_lat_2',
            'mepr_clinic_lng_2',
            'mepr_clinic_geocoded_at_2',
            'mepr_clinic_geo_confidence_2',
            'mepr_clinic_geo_fallback_2',
            'mepr_clinic_geo_provider_2',
            'mepr_clinic_lat_3',
            'mepr_clinic_lng_3',
            'mepr_clinic_geocoded_at_3',
            'mepr_clinic_geo_confidence_3',
            'mepr_clinic_geo_fallback_3',
            'mepr_clinic_geo_provider_3'
        );

        $total_deleted = 0;

        foreach ($geocoding_keys as $key) {
            $deleted = $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->usermeta} 
                WHERE meta_key = %s
            ", $key));

            if ($deleted !== false) {
                $total_deleted += $deleted;
                MapGeocoder::log_message("Deleted {$deleted} entries for meta key: {$key}");
            }
        }

        $message = "Successfully deleted {$total_deleted} geocoding metadata entries from all users.";
        MapGeocoder::log_message($message);

        echo '<div class="notice notice-success"><p>' . $message . '</p></div>';
    }

    public function bulk_geocode_users()
    {
        global $wpdb;

        // Safety limits
        $max_requests = 1000; // Increased for more processing
        $start_time = time();

        MapGeocoder::log_message("Starting bulk geocoding batch (max {$max_requests} requests)");


        $address_sets = array(
            '' => array('street' => 'mepr_clinic_street', 'city' => 'mepr_clinic_city', 'province' => 'mepr_clinic_province'),
            '_2' => array('street' => 'mepr_clinic_street_2', 'city' => 'mepr_clinic_city_2', 'province' => 'mepr_clinic_province_2'),
            '_3' => array('street' => 'mepr_clinic_street_3', 'city' => 'mepr_clinic_city_3', 'province' => 'mepr_clinic_province_3')
        );

        $total_processed = 0;
        $total_success = 0;
        $requests_made = 0;

        foreach ($address_sets as $suffix => $fields) {
            // Check request limit only
            if ($requests_made >= $max_requests) {
                MapGeocoder::log_message("Debug: Breaking due to request limit");
                break;
            }

            MapGeocoder::log_message("Debug: Checking address set with suffix '{$suffix}'");

            // Use a much simpler query - just get users with any street address for this suffix
            global $wpdb;
            $users_with_addresses = $wpdb->get_results($wpdb->prepare("
                SELECT DISTINCT user_id 
                FROM {$wpdb->usermeta} 
                WHERE meta_key = %s 
                AND meta_value != '' 
                LIMIT 100
            ", $fields['street']));

            MapGeocoder::log_message("Debug: Found " . count($users_with_addresses) . " users with street address for suffix '{$suffix}' (limited to 5 for testing)");

            if (empty($users_with_addresses)) {
                MapGeocoder::log_message("Debug: No users found with street address for suffix '{$suffix}', skipping");
                continue;
            }

            // Check which ones don't have coordinates yet
            foreach ($users_with_addresses as $user_row) {
                $user_id = $user_row->user_id;
                $existing_lat = get_user_meta($user_id, 'mepr_clinic_lat' . $suffix, true);

                // Check if coordinates are missing or invalid (0, empty, or province-level only)
                if (empty($existing_lat) || floatval($existing_lat) == 0 || MapGeocoder::is_province_level_coordinate($existing_lat, $suffix, $user_id)) {
                    MapGeocoder::log_message("Debug: User {$user_id} needs geocoding for suffix '{$suffix}'");

                    $street = get_user_meta($user_id, $fields['street'], true);
                    $city = get_user_meta($user_id, $fields['city'], true);
                    $province = get_user_meta($user_id, $fields['province'], true);

                    MapGeocoder::log_message("Debug: User {$user_id} address: street='{$street}', city='{$city}', province='{$province}'");

                    // Only geocode if we have street or city data (exclude province-only addresses)
                    if (!empty($street) || !empty($city)) {
                        MapGeocoder::log_message("Debug: Processing user {$user_id} for geocoding...");
                        $coordinates = MapGeocoder::geocode_address($street, $city, $province);
                        $requests_made++;

                        if ($coordinates) {
                            MapGeocoder::save_coordinates($user_id, $coordinates, $suffix);
                            MapGeocoder::log_message("Bulk geocoding: Successfully geocoded address for user {$user_id} (suffix: {$suffix})");
                            $total_success++;
                        } else {
                            MapGeocoder::log_message("Bulk geocoding: Failed to geocode address for user {$user_id} (suffix: {$suffix})");
                        }

                        $total_processed++;
                    } else {
                        MapGeocoder::log_message("Debug: User {$user_id} has province-only address, skipping geocoding");
                    }
                } else {
                    MapGeocoder::log_message("Debug: User {$user_id} already has coordinates for suffix '{$suffix}', skipping");
                }

                // Check request limit after each user
                if ($requests_made >= $max_requests) {
                    MapGeocoder::log_message("Debug: Breaking due to request limit after user {$user_id}");
                    break 2;
                }
            }
        }

        $time_taken = time() - $start_time;
        $message = "Bulk geocoding batch completed! Processed {$total_processed} addresses, successfully geocoded {$total_success}. Time taken: {$time_taken} seconds.";

        if ($requests_made >= $max_requests) {
            $message .= " <strong>Batch limit reached. Run again to process more addresses.</strong>";
        }

        MapGeocoder::log_message("Bulk geocoding completed: {$total_processed} processed, {$total_success} successful, {$time_taken}s");

        echo '<div class="notice notice-success"><p>' . $message . '</p></div>';
    }

    /**
     * Create geocoding cache database table
     */
    private function create_geocoding_cache_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'geocoded_addresses';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            original_address text NOT NULL,
            parsed_address text,
            latitude decimal(10,6),
            longitude decimal(10,6),
            provider varchar(50),
            confidence_score int(3),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY original_address_idx (original_address(100)),
            KEY provider_idx (provider),
            KEY created_at_idx (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        MapGeocoder::log_message("Geocoding cache table created/updated: $table_name");
    }

    /**
     * AJAX handler to start bulk geocoding
     */
    public function ajax_start_bulk_geocoding()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bulk_geocoding_control')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Rate limiting - prevent rapid fire requests
        $last_request = get_transient('bulk_geocoding_last_request_' . get_current_user_id());
        if ($last_request && (time() - $last_request) < 5) {
            wp_send_json_error('Please wait before starting another geocoding operation');
            return;
        }
        set_transient('bulk_geocoding_last_request_' . get_current_user_id(), time(), 60);

        // Check if already running
        $status = get_transient('bulk_geocoding_status');
        if ($status && $status['running']) {
            wp_send_json_error('Bulk geocoding is already running');
            return;
        }

        // Start the process
        $this->start_background_geocoding();

        wp_send_json_success('Bulk geocoding started');
    }

    /**
     * AJAX handler to stop bulk geocoding
     */
    public function ajax_stop_bulk_geocoding()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bulk_geocoding_control')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Stop the process
        $this->stop_background_geocoding();

        wp_send_json_success('Bulk geocoding stopped');
    }

    /**
     * AJAX handler to get bulk geocoding status
     */
    public function ajax_get_bulk_geocoding_status()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bulk_geocoding_control')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Get current status
        $status = $this->get_bulk_geocoding_status();

        wp_send_json_success($status);
    }

    /**
     * Start background geocoding process
     */
    private function start_background_geocoding()
    {
        // Check for stale processes and clean them up
        $status = get_transient('bulk_geocoding_status');
        if ($status && $status['running']) {
            // Check if it's been running too long without updates (over 5 minutes)
            $last_update = isset($status['started_at']) ? $status['started_at'] : 0;
            if (time() - $last_update > 300) {
                MapGeocoder::log_message("Cleaning up stale geocoding process");
                wp_clear_scheduled_hook('process_geocoding_batch');
            } else {
                MapGeocoder::log_message("Background geocoding is already running");
                return;
            }
        }

        // Initialize status
        $status = array(
            'running' => true,
            'started_at' => time(),
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'current_user_id' => 0,
            'progress_message' => 'Starting bulk geocoding...'
        );

        set_transient('bulk_geocoding_status', $status, 12 * HOUR_IN_SECONDS);

        // Schedule the first batch
        wp_schedule_single_event(time(), 'process_geocoding_batch');

        MapGeocoder::log_message("Background bulk geocoding started");
    }

    /**
     * Stop background geocoding process
     */
    private function stop_background_geocoding()
    {
        // Update status to stopped
        $status = get_transient('bulk_geocoding_status');
        if ($status) {
            $status['running'] = false;
            $status['stopped_at'] = time();
            $status['progress_message'] = 'Geocoding stopped by user';
            set_transient('bulk_geocoding_status', $status, 12 * HOUR_IN_SECONDS);
        }

        // Clear any scheduled events
        wp_clear_scheduled_hook('process_geocoding_batch');

        MapGeocoder::log_message("Background bulk geocoding stopped by user");
    }

    /**
     * Process a batch of geocoding in the background
     */
    public function process_geocoding_batch()
    {
        // Check if we should continue
        $status = get_transient('bulk_geocoding_status');
        if (!$status || !$status['running']) {
            MapGeocoder::log_message("Background geocoding process stopped");
            return;
        }

        MapGeocoder::log_message("Processing geocoding batch...");

        // Get users that need geocoding
        $users_to_process = $this->get_users_needing_geocoding(10); // Process 10 at a time

        if (empty($users_to_process)) {
            // No more users to process, complete the job
            $status['running'] = false;
            $status['completed_at'] = time();
            $status['progress_message'] = 'Bulk geocoding completed successfully';
            set_transient('bulk_geocoding_status', $status, 12 * HOUR_IN_SECONDS);

            MapGeocoder::log_message("Background bulk geocoding completed: {$status['total_processed']} processed, {$status['total_success']} successful");
            return;
        }

        // Process each user
        foreach ($users_to_process as $user_info) {
            // Check if we should stop
            $current_status = get_transient('bulk_geocoding_status');
            if (!$current_status || !$current_status['running']) {
                break;
            }

            $user_id = $user_info['user_id'];
            $suffix = $user_info['suffix'];
            $fields = $user_info['fields'];

            $status['current_user_id'] = $user_id;
            $status['progress_message'] = "Processing user {$user_id} (suffix: {$suffix})";
            set_transient('bulk_geocoding_status', $status, 12 * HOUR_IN_SECONDS);

            $street = get_user_meta($user_id, $fields['street'], true);
            $city = get_user_meta($user_id, $fields['city'], true);
            $province = get_user_meta($user_id, $fields['province'], true);

            // Check if coordinates already exist
            $existing_lat = get_user_meta($user_id, 'mepr_clinic_lat' . $suffix, true);
            $existing_lng = get_user_meta($user_id, 'mepr_clinic_lng' . $suffix, true);

            if (!empty($existing_lat) && !empty($existing_lng) && floatval($existing_lat) != 0 && floatval($existing_lng) != 0) {
                // Skip if coordinates already exist and aren't province-level
                if (!MapGeocoder::is_province_level_coordinate($existing_lat, $suffix, $user_id)) {
                    MapGeocoder::log_message("Background processing: User {$user_id} already has valid coordinates for suffix '{$suffix}', skipping");
                    $status['total_processed']++;
                    continue;
                }
            }

            MapGeocoder::log_message("Background processing user {$user_id}: street='{$street}', city='{$city}', province='{$province}'");

            // Only geocode if we have street or city data
            if (!empty($street) || !empty($city)) {
                $coordinates = $this->geocode_address_with_improved_fallback($street, $city, $province);

                if ($coordinates) {
                    MapGeocoder::save_coordinates($user_id, $coordinates, $suffix);
                    $status['total_success']++;
                    MapGeocoder::log_message("Background geocoding: Successfully geocoded address for user {$user_id} (suffix: {$suffix})");
                } else {
                    // Mark as failed attempt to prevent re-processing
                    $this->mark_geocoding_failed($user_id, $suffix);
                    $status['total_failed']++;
                    MapGeocoder::log_message("Background geocoding: Failed to geocode address for user {$user_id} (suffix: {$suffix}) - marked as failed");
                }
            } else {
                // Mark province-only addresses as processed to skip them
                $this->mark_geocoding_failed($user_id, $suffix, 'province_only');
                MapGeocoder::log_message("Background geocoding: User {$user_id} has province-only address, marking as skipped");
            }

            $status['total_processed']++;
            set_transient('bulk_geocoding_status', $status, 12 * HOUR_IN_SECONDS);

            // Small delay to respect rate limits
            sleep(1);
        }

        // Schedule next batch if still running
        $final_status = get_transient('bulk_geocoding_status');
        if ($final_status && $final_status['running']) {
            wp_schedule_single_event(time() + 2, 'process_geocoding_batch');
        }
    }

    /**
     * Get users that need geocoding with improved fallback handling
     */
    private function get_users_needing_geocoding($limit = 10)
    {
        global $wpdb;

        $address_sets = array(
            '' => array('street' => 'mepr_clinic_street', 'city' => 'mepr_clinic_city', 'province' => 'mepr_clinic_province'),
            '_2' => array('street' => 'mepr_clinic_street_2', 'city' => 'mepr_clinic_city_2', 'province' => 'mepr_clinic_province_2'),
            '_3' => array('street' => 'mepr_clinic_street_3', 'city' => 'mepr_clinic_city_3', 'province' => 'mepr_clinic_province_3')
        );

        $users_needing_geocoding = array();


        foreach ($address_sets as $suffix => $fields) {
            if (count($users_needing_geocoding) >= $limit) {
                break;
            }

            $lat_key = 'mepr_clinic_lat' . $suffix;
            $street_key = $fields['street'];
            $city_key = $fields['city'];

            // Get users with addresses but no coordinates AND no failed markers
            $users_without_coords = $wpdb->get_results($wpdb->prepare("
                SELECT DISTINCT s.user_id 
                FROM {$wpdb->usermeta} s
                LEFT JOIN {$wpdb->usermeta} lat ON (s.user_id = lat.user_id AND lat.meta_key = %s)
                LEFT JOIN {$wpdb->usermeta} failed ON (s.user_id = failed.user_id AND failed.meta_key = %s)
                WHERE s.meta_key IN (%s, %s)
                AND s.meta_value != '' 
                AND (lat.meta_value IS NULL OR lat.meta_value = '' OR lat.meta_value = '0')
                AND failed.meta_value IS NULL
                ORDER BY s.user_id ASC
                LIMIT %d
            ", $lat_key, 'mepr_clinic_geocode_failed' . $suffix, $street_key, $city_key, $limit - count($users_needing_geocoding)));

            foreach ($users_without_coords as $user_row) {
                if (count($users_needing_geocoding) >= $limit) {
                    break;
                }

                $user_id = $user_row->user_id;

                // Double-check that this user actually has address data AND doesn't have valid coordinates
                $street = get_user_meta($user_id, $street_key, true);
                $city = get_user_meta($user_id, $city_key, true);

                // Also double-check coordinates aren't already present
                $existing_lat = get_user_meta($user_id, $lat_key, true);
                $existing_lng = get_user_meta($user_id, 'mepr_clinic_lng' . $suffix, true);

                // Only include if they have street or city data (not just province) AND no valid coordinates
                if ((!empty($street) || !empty($city)) &&
                    (empty($existing_lat) || empty($existing_lng) || floatval($existing_lat) == 0 || floatval($existing_lng) == 0 ||
                        MapGeocoder::is_province_level_coordinate($existing_lat, $suffix, $user_id))
                ) {
                    $users_needing_geocoding[] = array(
                        'user_id' => $user_id,
                        'suffix' => $suffix,
                        'fields' => $fields
                    );

                    MapGeocoder::log_message("Added user {$user_id} (suffix: {$suffix}) to geocoding queue - no coordinates found");
                } else {
                    MapGeocoder::log_message("Skipped user {$user_id} (suffix: {$suffix}) - already has valid coordinates or no address data");
                }
            }

            // If we still need more users, check for province-level coordinates that need improvement
            if (count($users_needing_geocoding) < $limit) {
                $users_with_poor_coords = $wpdb->get_results($wpdb->prepare("
                    SELECT DISTINCT s.user_id 
                    FROM {$wpdb->usermeta} s
                    INNER JOIN {$wpdb->usermeta} lat ON (s.user_id = lat.user_id AND lat.meta_key = %s)
                    LEFT JOIN {$wpdb->usermeta} failed ON (s.user_id = failed.user_id AND failed.meta_key = %s)
                    WHERE s.meta_key IN (%s, %s)
                    AND s.meta_value != '' 
                    AND lat.meta_value != '' 
                    AND lat.meta_value != '0'
                    AND failed.meta_value IS NULL
                    ORDER BY s.user_id ASC
                    LIMIT %d
                ", $lat_key, 'mepr_clinic_geocode_failed' . $suffix, $street_key, $city_key, $limit - count($users_needing_geocoding)));

                foreach ($users_with_poor_coords as $user_row) {
                    if (count($users_needing_geocoding) >= $limit) {
                        break;
                    }

                    $user_id = $user_row->user_id;
                    $existing_lat = get_user_meta($user_id, $lat_key, true);

                    // Check if coordinates are province-level (too general)
                    if (MapGeocoder::is_province_level_coordinate($existing_lat, $suffix, $user_id)) {
                        $users_needing_geocoding[] = array(
                            'user_id' => $user_id,
                            'suffix' => $suffix,
                            'fields' => $fields
                        );

                        MapGeocoder::log_message("Added user {$user_id} (suffix: {$suffix}) to geocoding queue - province-level coordinates need improvement");
                    }
                }
            }
        }

        MapGeocoder::log_message("Found " . count($users_needing_geocoding) . " users needing geocoding (limit: {$limit})");
        return $users_needing_geocoding;
    }

    /**
     * Geocode address with improved fallback handling for better results
     */
    private function geocode_address_with_improved_fallback($street, $city, $province)
    {
        // First try the original method
        $result = MapGeocoder::geocode_address($street, $city, $province);

        if ($result) {
            return $result;
        }

        // Enhanced fallback strategies for better address handling
        MapGeocoder::log_message("Trying enhanced fallback strategies for: street='{$street}', city='{$city}', province='{$province}'");

        // Strategy 1: Try city + province only if we have a city
        if (!empty($city) && !empty($province)) {
            $fallback_result = MapGeocoder::geocode_address('', $city, $province);
            if ($fallback_result && $fallback_result['latitude'] != 0) {
                // Mark as lower confidence since it's city-level
                $fallback_result['confidence_score'] = max(30, ($fallback_result['confidence_score'] ?? 0) - 30);
                $fallback_result['fallback_used'] = 'city_only';
                MapGeocoder::log_message("Fallback successful using city-only: {$city}, {$province}");
                return $fallback_result;
            }
        }

        // Strategy 2: Try just the city if no province match
        if (!empty($city)) {
            $fallback_result = MapGeocoder::geocode_address('', $city, '');
            if ($fallback_result && $fallback_result['latitude'] != 0) {
                // Mark as lower confidence
                $fallback_result['confidence_score'] = max(20, ($fallback_result['confidence_score'] ?? 0) - 40);
                $fallback_result['fallback_used'] = 'city_no_province';
                MapGeocoder::log_message("Fallback successful using city without province: {$city}");
                return $fallback_result;
            }
        }

        // Strategy 3: Try province only as last resort (but mark as very low confidence)
        if (!empty($province)) {
            $fallback_result = MapGeocoder::geocode_address('', '', $province);
            if ($fallback_result && $fallback_result['latitude'] != 0) {
                // Mark as very low confidence since it's province-level
                $fallback_result['confidence_score'] = 10;
                $fallback_result['fallback_used'] = 'province_only';
                MapGeocoder::log_message("Fallback successful using province-only: {$province} (low confidence)");
                return $fallback_result;
            }
        }

        MapGeocoder::log_message("All enhanced fallback strategies failed");
        return false;
    }

    /**
     * Get current bulk geocoding status
     */
    private function get_bulk_geocoding_status()
    {
        $status = get_transient('bulk_geocoding_status');

        if (!$status) {
            return array(
                'running' => false,
                'total_processed' => 0,
                'total_success' => 0,
                'total_failed' => 0,
                'progress_message' => 'Not running'
            );
        }

        // Check if process appears stuck (no updates for 5 minutes)
        if ($status['running']) {
            $last_update = isset($status['started_at']) ? $status['started_at'] : 0;
            if (time() - $last_update > 300) {
                $status['progress_message'] .= ' (Process may be stuck - click Stop and restart)';
            }
        }

        return $status;
    }

    /**
     * Geocoding tools admin page
     */
    public function geocoding_tools_page()
    {
        // Security check - ensure user has proper capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Whitelist of allowed files to prevent path traversal
        $allowed_files = array(
            'geocoding-test.php' => 'admin/partials/geocoding-test.php'
        );
        
        $file_key = 'geocoding-test.php';
        
        if (!isset($allowed_files[$file_key])) {
            wp_die(__('Invalid file access attempt.'));
        }
        
        $include_file = MAP_INTEGRATION_PLUGIN_PATH . $allowed_files[$file_key];
        
        // Multiple layers of path validation
        $real_file = realpath($include_file);
        $plugin_dir = realpath(MAP_INTEGRATION_PLUGIN_PATH);
        
        // Validate file exists, is within plugin directory, and has correct extension
        if (!$real_file || 
            !$plugin_dir || 
            strpos($real_file, $plugin_dir) !== 0 ||
            !file_exists($real_file) ||
            pathinfo($real_file, PATHINFO_EXTENSION) !== 'php' ||
            !is_readable($real_file)) {
            
            self::log_message("Security: Invalid file access attempt blocked: " . esc_html($include_file));
            wp_die(__('Security error: Invalid file access attempt.'));
        }
        
        // Additional security check - ensure file is in expected location
        $expected_path = $plugin_dir . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'geocoding-test.php';
        if ($real_file !== realpath($expected_path)) {
            self::log_message("Security: File path mismatch blocked");
            wp_die(__('Security error: File path validation failed.'));
        }
        
        include $real_file;
    }

    /**
     * Add comprehensive security headers for admin pages
     */
    public function add_security_headers() {
        if (is_admin()) {
            // Prevent MIME type sniffing
            header('X-Content-Type-Options: nosniff');
            
            // Prevent clickjacking
            header('X-Frame-Options: DENY');
            
            // Referrer policy for privacy
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            // XSS protection (for older browsers)
            header('X-XSS-Protection: 1; mode=block');
            
            // Content Security Policy for admin pages
            $csp_directives = array(
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com https://cdnjs.cloudflare.com https://maps.googleapis.com",
                "style-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com https://fonts.googleapis.com",
                "img-src 'self' data: https: http:",
                "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
                "connect-src 'self' https://nominatim.openstreetmap.org https://maps.googleapis.com",
                "frame-src 'none'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'"
            );
            
            header('Content-Security-Policy: ' . implode('; ', $csp_directives));
            
            // Prevent caching of sensitive admin pages
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }

    /**
     * Validate address input comprehensively
     * 
     * @param string $address Address to validate
     * @return string|false Validated address or false if invalid
     */
    public static function validate_address_input($address) {
        // Basic sanitization
        $address = sanitize_text_field($address);
        
        // Length validation
        if (strlen($address) > 255 || strlen($address) < 1) {
            return false;
        }
        
        // Enhanced pattern validation - stricter address format
        if (!preg_match('/^[a-zA-Z0-9\s,.\-#\'\(\)\/]+$/', $address)) {
            return false;
        }
        
        // Check for suspicious patterns that could indicate injection attempts
        $suspicious_patterns = array(
            '/script\s*[\/\>]/i',
            '/javascript\s*:/i',
            '/eval\s*\(/i',
            '/expression\s*\(/i',
            '/vbscript\s*:/i',
            '/on\w+\s*=/i',
            '/<\s*\w+/i',
            '/\{\s*\{/',
            '/exec\s*\(/i',
            '/system\s*\(/i',
            '/passthru\s*\(/i',
            '/shell_exec\s*\(/i',
            '/`[^`]*`/',
            '/\$\s*\{/',
            '/\<\?\s*(php)?\s/i'
        );
        
        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $address)) {
                self::log_message("Security: Suspicious address pattern blocked: " . substr($address, 0, 50));
                return false;
            }
        }
        
        // Check for directory traversal attempts
        $traversal_patterns = array(
            '/\.\.\//',
            '/\.\.\\\\/',
            '/\.\.\%2f/i',
            '/\.\.\%5c/i',
            '/\%2e\%2e\%2f/i',
            '/\%2e\%2e\%5c/i'
        );
        
        foreach ($traversal_patterns as $pattern) {
            if (preg_match($pattern, $address)) {
                self::log_message("Security: Directory traversal attempt blocked in address input");
                return false;
            }
        }
        
        // Additional validation - check for common XSS vectors
        $xss_patterns = array(
            '/alert\s*\(/i',
            '/confirm\s*\(/i',
            '/prompt\s*\(/i',
            '/document\s*\./i',
            '/window\s*\./i',
            '/location\s*\./i'
        );
        
        foreach ($xss_patterns as $pattern) {
            if (preg_match($pattern, $address)) {
                self::log_message("Security: XSS attempt blocked in address input");
                return false;
            }
        }
        
        return $address;
    }

    /**
     * Validate and sanitize user ID input
     * 
     * @param mixed $user_id User ID to validate
     * @return int|false Valid user ID or false if invalid
     */
    public static function validate_user_id($user_id) {
        // Convert to integer
        $user_id = intval($user_id);
        
        // Basic validation
        if ($user_id <= 0) {
            return false;
        }
        
        // Check if user exists
        if (!get_userdata($user_id)) {
            return false;
        }
        
        return $user_id;
    }

    /**
     * Validate coordinates input
     * 
     * @param mixed $lat Latitude
     * @param mixed $lng Longitude
     * @return array|false Valid coordinates or false if invalid
     */
    public static function validate_coordinates($lat, $lng) {
        $lat = floatval($lat);
        $lng = floatval($lng);
        
        // Check valid ranges
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return false;
        }
        
        // Reject null island coordinates (likely invalid)
        if ($lat == 0 && $lng == 0) {
            return false;
        }
        
        return array('lat' => $lat, 'lng' => $lng);
    }

    /**
     * Display chiropractor directory shortcode with search functionality
     */
    public function display_chiropractor_directory($atts)
    {
        $atts = shortcode_atts(array(
            'user_role' => 'subscriber',
            'show_search' => 'true',
            'show_map_links' => 'true',
            'include_map' => 'false',
            'map_width' => '100%',
            'map_height' => '400px',
            'center_lat' => '44.6488',
            'center_lng' => '-63.5752',
            'zoom' => '7',
            'show_avatar' => 'false',
            'show_contact' => 'true',
            'sort_by' => 'last_name', // last_name, city
            'sort_order' => 'asc' // asc, desc
        ), $atts);

        // Get all chiropractor data
        $chiropractors = $this->get_chiropractor_directory_data($atts['user_role']);

        if (empty($chiropractors)) {
            return '<div class="chiro-directory-container"><p>No chiropractors found.</p></div>';
        }

        // Sort chiropractors
        $chiropractors = $this->sort_chiropractors($chiropractors, $atts['sort_by'], $atts['sort_order']);

        // Generate unique ID for this directory instance
        $directory_id = 'chiro-directory-' . uniqid();

        // Start building output
        $output = '<div class="chiro-directory-container" id="' . esc_attr($directory_id) . '">';

        // Add search if enabled
        if ($atts['show_search'] === 'true') {
            $output .= $this->render_directory_search();
        }

        // Include map if requested
        if ($atts['include_map'] === 'true') {
            $map_atts = array(
                'width' => $atts['map_width'],
                'height' => $atts['map_height'],
                'center_lat' => $atts['center_lat'],
                'center_lng' => $atts['center_lng'],
                'zoom' => $atts['zoom'],
                'user_role' => $atts['user_role']
            );
            $output .= '<div class="directory-map-section">';
            $output .= $this->display_clinic_map($map_atts);
            $output .= '</div>';
        }

        // Results count placeholder (will be populated by JavaScript)
        $output .= '<div class="search-results-count" style="display: none;"></div>';

        // Display total count of chiropractors
        $total_count = count($chiropractors);
        $output .= '<div class="chiro-directory-count">';
        $output .= '<p class="chiropractor-tally">' . $total_count . ' chiropractor' . ($total_count !== 1 ? 's' : '') . ' found</p>';
        $output .= '</div>';

        // Listings container
        $output .= '<div class="chiro-listings-grid">';

        foreach ($chiropractors as $chiropractor) {
            $output .= $this->render_chiropractor_listing($chiropractor, $atts);
        }

        $output .= '</div>'; // Close listings grid
        $output .= '</div>'; // Close directory container

        return $output;
    }

    /**
     * Get comprehensive chiropractor data for directory display
     */
    private function get_chiropractor_directory_data($user_role = 'subscriber')
    {
        $chiropractors = array();

        // Get users with the specified role
        $users = get_users(array(
            'role' => $user_role,
            'fields' => 'all',
        ));

        foreach ($users as $user) {
            // Filter out users who don't have pw_user_status equal to "approved"
            $user_status = get_user_meta($user->ID, 'pw_user_status', true);
            if ($user_status !== 'approved') {
                continue;
            }

            // Filter out users who have "Student Membership" subscription type
            if (class_exists('MeprUser')) {
                $mepr_user = new MeprUser($user->ID);
                $active_memberships = $mepr_user->active_product_subscriptions('products');
                $has_student_membership = false;
                foreach ($active_memberships as $membership) {
                    if (isset($membership->post_title) && $membership->post_title === 'Student Membership') {
                        $has_student_membership = true;
                        break;
                    }
                }
                if ($has_student_membership) {
                    continue;
                }
            }

            $chiropractor_data = array(
                'user_id' => $user->ID,
                'display_name' => $user->display_name,
                'first_name' => get_user_meta($user->ID, 'first_name', true),
                'last_name' => get_user_meta($user->ID, 'last_name', true),
                'user_email' => $user->user_email,
                'date_registered' => $user->user_registered,
                'locations' => array(),
                'bio' => get_user_meta($user->ID, 'description', true),
                'website' => $user->user_url
            );

            // Define address sets for primary, secondary, and third addresses
            $address_sets = array(
                '' => 'Primary',
                '_2' => 'Secondary',
                '_3' => 'Third'
            );

            $has_locations = false;

            foreach ($address_sets as $suffix => $label) {
                $location_data = $this->get_single_location_data($user->ID, $suffix, $label);

                if ($location_data && $this->location_has_data($location_data)) {
                    $chiropractor_data['locations'][] = $location_data;
                    $has_locations = true;
                }
            }

            // Only include chiropractors who have at least one location with meaningful data
            if ($has_locations) {
                $chiropractors[] = $chiropractor_data;
            }
        }

        return $chiropractors;
    }

    /**
     * Get data for a single location
     */
    private function get_single_location_data($user_id, $suffix, $label)
    {
        $lat_key = 'mepr_clinic_lat' . $suffix;
        $lng_key = 'mepr_clinic_lng' . $suffix;
        $street_key = 'mepr_clinic_street' . $suffix;
        $city_key = 'mepr_clinic_city' . $suffix;
        $province_key = 'mepr_clinic_province' . $suffix;
        $name_key = 'mepr_clinic_name' . $suffix;
        $phone_key = 'mepr_clinic_phone' . $suffix;
        $email_key = 'mepr_clinic_email_address' . $suffix;
        $website_key = 'mepr_clinic_website' . $suffix;

        $lat = get_user_meta($user_id, $lat_key, true);
        $lng = get_user_meta($user_id, $lng_key, true);
        $street = get_user_meta($user_id, $street_key, true);
        $city = get_user_meta($user_id, $city_key, true);
        $province = get_user_meta($user_id, $province_key, true);
        $name = get_user_meta($user_id, $name_key, true);
        $phone = get_user_meta($user_id, $phone_key, true);
        $email = get_user_meta($user_id, $email_key, true);
        $website = get_user_meta($user_id, $website_key, true);

        $address_parts = array_filter(array($street, $city, $province));
        $address = implode(', ', $address_parts);

        return array(
            'suffix' => $suffix,
            'label' => $label,
            'name' => $name,
            'lat' => $lat ? floatval($lat) : null,
            'lng' => $lng ? floatval($lng) : null,
            'address' => $address,
            'street' => $street,
            'city' => $city,
            'province' => $province,
            'phone' => $phone,
            'email' => $email,
            'website' => $website,
            'has_coordinates' => !empty($lat) && !empty($lng)
        );
    }

    /**
     * Check if location has meaningful data
     */
    private function location_has_data($location)
    {
        return !empty($location['street']) || !empty($location['city']) ||
            !empty($location['phone']) || !empty($location['email']);
    }

    /**
     * Sort chiropractors based on criteria
     */
    private function sort_chiropractors($chiropractors, $sort_by, $sort_order)
    {
        usort($chiropractors, function ($a, $b) use ($sort_by, $sort_order) {
            $result = 0;

            switch ($sort_by) {
                case 'last_name':
                    // Sort by last name, fall back to first name if last names are equal
                    $result = strcasecmp($a['last_name'], $b['last_name']);
                    if ($result === 0) {
                        $result = strcasecmp($a['first_name'], $b['first_name']);
                    }
                    break;
                case 'city':
                    // Sort by city of first location, fall back to display name if cities are equal
                    $city_a = !empty($a['locations']) ? $a['locations'][0]['city'] : '';
                    $city_b = !empty($b['locations']) ? $b['locations'][0]['city'] : '';
                    $result = strcasecmp($city_a, $city_b);
                    if ($result === 0) {
                        $result = strcasecmp($a['display_name'], $b['display_name']);
                    }
                    break;
                default:
                    // Default to last name sorting
                    $result = strcasecmp($a['last_name'], $b['last_name']);
                    if ($result === 0) {
                        $result = strcasecmp($a['first_name'], $b['first_name']);
                    }
            }

            return $sort_order === 'desc' ? -$result : $result;
        });

        return $chiropractors;
    }

    /**
     * Render the search interface
     */
    private function render_directory_search()
    {
        $output = '<div class="chiro-directory-search">';
        $output .= '<h3>Search Chiropractors</h3>';
        $output .= '<form id="chiro-search-form" role="search">';
        $output .= '<div id="chiro-search-wrapper">';
        $output .= '<input type="search" id="chiro-search-input" placeholder="Search by name, location, or contact info..." autocomplete="off" enterkeyhint="search">';
        $output .= '<button type="button" id="chiro-search-clear" aria-label="Clear search" style="display: none;">';
        $output .= '&times;';
        $output .= '</button>';
        $output .= '</div>';

        // Add sort toggle section
        $output .= '<div class="chiro-sort-controls">';
        $output .= '<label class="sort-controls-label">Sort by:</label>';
        $output .= '<div class="sort-buttons-container">';
        $output .= '<button type="button" class="sort-button active" data-sort="last_name" data-order="asc">Last Name (A-Z)</button>';
        $output .= '<button type="button" class="sort-button" data-sort="city" data-order="asc">City (A-Z)</button>';
        $output .= '</div>';
        $output .= '</div>';

        $output .= '</form>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render a single chiropractor listing
     */
    private function render_chiropractor_listing($chiropractor, $atts)
    {
        // Add data attributes for sorting
        $first_city = !empty($chiropractor['locations']) ? $chiropractor['locations'][0]['city'] : '';

        $output = '<div class="chiro-listing" data-user-id="' . esc_attr($chiropractor['user_id']) . '"';
        $output .= ' data-last-name="' . esc_attr($chiropractor['last_name']) . '"';
        $output .= ' data-first-name="' . esc_attr($chiropractor['first_name']) . '"';
        $output .= ' data-city="' . esc_attr($first_city) . '"';
        $output .= '>';

        // Header with basic info
        $output .= '<div class="chiro-header">';

        // Basic info
        $output .= '<div class="chiro-info">';
        $output .= '<h3 class="chiro-name">';

        // Make chiropractor name clickable to center on first location with coordinates
        $first_location_with_coords = null;
        foreach ($chiropractor['locations'] as $location) {
            if ($location['has_coordinates']) {
                $first_location_with_coords = $location;
                break;
            }
        }

        if ($first_location_with_coords && $atts['show_map_links'] === 'true') {
            // Generate the clinic name exactly as it appears on the map
            $user = get_user_by('ID', $chiropractor['user_id']);
            $map_clinic_name = $first_location_with_coords['name'] ?: ($user->display_name . ' (' . $first_location_with_coords['label'] . ')');
            $output .= '<a href="#" class="map-clickable" onclick="centerMapOnClinic(\'' . esc_js($map_clinic_name) . '\'); return false;">' . esc_html($chiropractor['display_name']) . '</a>';
        } else {
            $output .= esc_html($chiropractor['display_name']);
        }

        $output .= '</h3>';

        if (!empty($chiropractor['bio'])) {
            $output .= '<div class="chiro-summary">' . esc_html(wp_trim_words($chiropractor['bio'], 20)) . '</div>';
        }

        $output .= '</div>'; // Close chiro-info
        $output .= '</div>'; // Close chiro-header

        // Locations
        if (!empty($chiropractor['locations'])) {
            $location_count = count($chiropractor['locations']);
            $output .= '<div class="chiro-locations">';
            $output .= '<div class="chiro-locations-header">';
            $output .= $location_count === 1 ? 'Clinic Location' : 'Clinic Locations (' . $location_count . ')';
            $output .= '</div>';

            foreach ($chiropractor['locations'] as $location) {
                $output .= $this->render_location_item($location, $chiropractor['display_name'], $atts, $chiropractor['user_id']);
            }

            $output .= '</div>'; // Close chiro-locations
        }

        $output .= '</div>'; // Close chiro-listing

        return $output;
    }

    /**
     * Render a single location item
     */
    private function render_location_item($location, $chiropractor_name, $atts, $user_id)
    {
        // Generate the clinic name exactly as it appears on the map for locations with coordinates
        $map_clinic_name = '';
        $is_clickable = false;
        if ($atts['show_map_links'] === 'true' && $location['has_coordinates']) {
            $user = get_user_by('ID', $user_id);
            $map_clinic_name = $location['name'] ?: ($user->display_name . ' (' . $location['label'] . ')');
            $is_clickable = true;
        }

        // Start location item with clickable wrapper if applicable
        if ($is_clickable) {
            $output = '<div class="location-item location-clickable" onclick="centerMapOnClinic(\'' . esc_js($map_clinic_name) . '\'); return false;" style="cursor: pointer;">';
        } else {
            $output = '<div class="location-item">';
        }

        // Location name 
        $location_display_name = !empty($location['name']) ?
            $location['name'] : ($chiropractor_name . ' - ' . $location['label'] . ' Location');

        $output .= '<div class="location-name">';
        $output .= esc_html($location_display_name);
        $output .= '</div>';

        // Address
        if (!empty($location['address'])) {
            $output .= '<div class="location-address">' . esc_html($location['address']) . '</div>';
        }

        // Contact information
        if ($atts['show_contact'] === 'true') {
            $contact_items = array();

            if (!empty($location['phone'])) {
                $contact_items[] = '<span class="location-phone">Phone: <a href="tel:' . esc_attr($location['phone']) . '" onclick="event.stopPropagation();">' . esc_html($location['phone']) . '</a></span>';
            }

            if (!empty($location['email'])) {
                $contact_items[] = '<span class="location-email">Email: <a href="mailto:' . esc_attr($location['email']) . '" onclick="event.stopPropagation();">' . esc_html($location['email']) . '</a></span>';
            }

            if (!empty($location['website'])) {
                $website_url = $location['website'];
                if (!preg_match('/^https?:\/\//', $website_url)) {
                    $website_url = 'http://' . $website_url;
                }
                $contact_items[] = '<span class="location-website"><a href="' . esc_url($website_url) . '" target="_blank" onclick="event.stopPropagation();">Visit Website</a></span>';
            }

            if (!empty($contact_items)) {
                $output .= '<div class="location-contact">' . implode('', $contact_items) . '</div>';
            }
        }

        $output .= '</div>'; // Close location-item

        return $output;
    }

    /**
     * Mark a user's address as failed geocoding attempt
     */
    private function mark_geocoding_failed($user_id, $suffix, $reason = 'failed')
    {
        $failed_key = 'mepr_clinic_geocode_failed' . $suffix;
        $failed_at_key = 'mepr_clinic_geocode_failed_at' . $suffix;

        update_user_meta($user_id, $failed_key, $reason);
        update_user_meta($user_id, $failed_at_key, current_time('mysql'));

        MapGeocoder::log_message("Marked user {$user_id} (suffix: {$suffix}) as failed geocoding: {$reason}");
    }

    /**
     * Clear failed geocoding markers for a user
     */
    private function clear_geocoding_failed($user_id, $suffix)
    {
        $failed_key = 'mepr_clinic_geocode_failed' . $suffix;
        $failed_at_key = 'mepr_clinic_geocode_failed_at' . $suffix;

        delete_user_meta($user_id, $failed_key);
        delete_user_meta($user_id, $failed_at_key);
    }

    /**
     * Clear all failed geocoding markers from all users
     */
    public function clear_all_failed_markers()
    {
        global $wpdb;

        MapGeocoder::log_message("Starting to clear all failed geocoding markers");

        // Define all failed marker keys to remove
        $failed_marker_keys = array(
            'mepr_clinic_geocode_failed',
            'mepr_clinic_geocode_failed_at',
            'mepr_clinic_geocode_failed_2',
            'mepr_clinic_geocode_failed_at_2',
            'mepr_clinic_geocode_failed_3',
            'mepr_clinic_geocode_failed_at_3'
        );

        $total_deleted = 0;

        foreach ($failed_marker_keys as $key) {
            $deleted = $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->usermeta} 
                WHERE meta_key = %s
            ", $key));

            if ($deleted !== false) {
                $total_deleted += $deleted;
                MapGeocoder::log_message("Deleted {$deleted} failed markers for meta key: {$key}");
            }
        }

        $message = "Successfully cleared {$total_deleted} failed geocoding markers from all users. Previously failed addresses can now be retried.";
        MapGeocoder::log_message($message);

        echo '<div class="notice notice-success"><p>' . $message . '</p></div>';
    }
}

// Initialize the plugin
new MapIntegration();

/**
 * Security Configuration and Additional Safeguards
 */

// Hook to add additional security measures on plugin activation
register_activation_hook(__FILE__, function() {
    // Set default security options
    add_option('map_integration_security_headers', true);
    add_option('map_integration_file_logging_enabled', false);
    add_option('map_integration_rate_limiting_enabled', true);
    
    // Create secure log directory structure
    $upload_dir = wp_upload_dir();
    if (!is_wp_error($upload_dir)) {
        $log_dir = $upload_dir['basedir'] . '/map-integration-logs/';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // Create comprehensive .htaccess for security
            $htaccess_content = "# Map Integration Security Configuration\n" .
                               "# Deny all access to this directory\n" .
                               "Order Deny,Allow\n" . 
                               "Deny from all\n" .
                               "<Files \"*.log\">\n" .
                               "    Deny from all\n" .
                               "</Files>\n" .
                               "<Files \"*.txt\">\n" .
                               "    Deny from all\n" .
                               "</Files>\n" .
                               "# Prevent directory browsing\n" .
                               "Options -Indexes\n" .
                               "# Prevent script execution\n" .
                               "php_flag engine off\n";
            
            file_put_contents($log_dir . '.htaccess', $htaccess_content, LOCK_EX);
            
            // Create index.php to prevent directory listing
            file_put_contents($log_dir . 'index.php', "<?php\n// Silence is golden\n", LOCK_EX);
        }
    }
});

// Security headers for frontend (not just admin)
add_action('wp_head', function() {
    if (get_option('map_integration_security_headers', true)) {
        echo '<meta http-equiv="X-Content-Type-Options" content="nosniff">' . "\n";
        echo '<meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">' . "\n";
    }
});

// Clean up on plugin deactivation
register_deactivation_hook(__FILE__, function() {
    // Clear any sensitive transients
    delete_transient('bulk_geocoding_status');
    
    // Clear rate limiting data
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bulk_geocoding_last_request_%'");
});

// Add security audit logging
add_action('wp_login_failed', function($username) {
    if (class_exists('MapGeocoder')) {
        MapGeocoder::log_message("Security: Failed login attempt for user: " . sanitize_user($username));
    }
});

// Monitor for suspicious activity
add_action('wp_loaded', function() {
    // Check for potential security issues in requests
    if (isset($_REQUEST['map_integration_debug']) && !defined('WP_DEBUG')) {
        if (class_exists('MapGeocoder')) {
            MapGeocoder::log_message("Security: Debug parameter detected in non-debug environment");
        }
    }
});

// Cleanup old log files periodically
add_action('wp_scheduled_delete', function() {
    $upload_dir = wp_upload_dir();
    if (!is_wp_error($upload_dir)) {
        $log_dir = $upload_dir['basedir'] . '/map-integration-logs/';
        if (is_dir($log_dir)) {
            $files = glob($log_dir . '*.log');
            $cutoff_time = time() - (90 * DAY_IN_SECONDS); // 90 days
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoff_time) {
                    unlink($file);
                }
            }
        }
    }
});
