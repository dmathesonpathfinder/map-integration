<?php

/**
 * Geocoding Service Class
 * 
 * Supports multiple geocoding providers (Google Maps API and OpenStreetMap/Nominatim),
 * implements fallback strategies, caches results, and returns coordinates with confidence scores.
 * 
 * @package MapIntegration
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Map_Integration_Geocoding_Service
{
    /**
     * Available geocoding providers
     */
    const PROVIDER_NOMINATIM = 'nominatim';
    const PROVIDER_GOOGLE = 'google';
    
    /**
     * Default provider order for fallback
     * 
     * @var array
     */
    private static $default_provider_order = array(
        self::PROVIDER_NOMINATIM,
        self::PROVIDER_GOOGLE
    );
    
    /**
     * Cache table name
     * 
     * @var string
     */
    private static $cache_table = 'geocoded_addresses';
    
    /**
     * Rate limiting settings
     * 
     * @var array
     */
    private static $rate_limits = array(
        self::PROVIDER_NOMINATIM => array(
            'requests_per_second' => 1,
            'last_request_time' => 0
        ),
        self::PROVIDER_GOOGLE => array(
            'requests_per_second' => 10,
            'last_request_time' => 0
        )
    );

    /**
     * Geocode an address using multiple providers with fallback
     * 
     * @param string $address The address to geocode
     * @param array $options Geocoding options
     * @return array|false Geocoding result or false on failure
     */
    public static function geocode_address($address, $options = array())
    {
        // Set default options
        $options = wp_parse_args($options, array(
            'providers' => self::$default_provider_order,
            'use_cache' => true,
            'cache_ttl' => 30 * DAY_IN_SECONDS, // 30 days
            'country_code' => 'ca',
            'timeout' => 10
        ));

        if (empty($address)) {
            return false;
        }

        // Normalize address for consistent caching
        $normalized_address = self::normalize_address_for_cache($address);
        
        // Check cache first if enabled
        if ($options['use_cache']) {
            $cached_result = self::get_cached_result($normalized_address);
            if ($cached_result) {
                self::log_message("Cache hit for address: {$address}");
                return $cached_result;
            }
        }

        // Try each provider in order
        foreach ($options['providers'] as $provider) {
            if (!self::is_provider_available($provider)) {
                self::log_message("Provider {$provider} not available, skipping");
                continue;
            }

            self::log_message("Trying provider {$provider} for: {$address}");
            
            // Respect rate limits
            self::enforce_rate_limit($provider);
            
            // Attempt geocoding
            $result = self::geocode_with_provider($provider, $address, $options);
            
            if ($result && !empty($result['latitude']) && !empty($result['longitude'])) {
                // Add provider information
                $result['provider'] = $provider;
                $result['cached_at'] = current_time('mysql');
                
                // Cache the result
                if ($options['use_cache']) {
                    self::cache_result($normalized_address, $result, $options['cache_ttl']);
                }
                
                self::log_message("Successfully geocoded with {$provider}: lat={$result['latitude']}, lng={$result['longitude']}, confidence={$result['confidence_score']}");
                return $result;
            } else {
                self::log_message("Provider {$provider} failed for: {$address}");
            }
        }

        self::log_message("All providers failed for address: {$address}");
        return false;
    }

    /**
     * Geocode with specific provider
     * 
     * @param string $provider Provider name
     * @param string $address Address to geocode
     * @param array $options Geocoding options
     * @return array|false Geocoding result or false on failure
     */
    private static function geocode_with_provider($provider, $address, $options)
    {
        switch ($provider) {
            case self::PROVIDER_NOMINATIM:
                return self::geocode_with_nominatim($address, $options);
                
            case self::PROVIDER_GOOGLE:
                return self::geocode_with_google($address, $options);
                
            default:
                self::log_message("Unknown provider: {$provider}");
                return false;
        }
    }

    /**
     * Geocode with Nominatim/OpenStreetMap
     * 
     * @param string $address Address to geocode
     * @param array $options Geocoding options
     * @return array|false Geocoding result or false on failure
     */
    private static function geocode_with_nominatim($address, $options)
    {
        // Build query URL
        $query_params = array(
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 1,
            'extratags' => 1
        );
        
        if (!empty($options['country_code'])) {
            $query_params['countrycodes'] = $options['country_code'];
        }
        
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($query_params);
        
        // Make API request
        $response = self::make_http_request($url, $options['timeout']);
        
        if (!$response) {
            return false;
        }
        
        $data = json_decode($response['body'], true);
        
        if (empty($data) || !is_array($data)) {
            return false;
        }
        
        $result = $data[0];
        
        // Extract coordinates
        $latitude = floatval($result['lat']);
        $longitude = floatval($result['lon']);
        
        if ($latitude == 0 && $longitude == 0) {
            return false;
        }
        
        // Calculate confidence score based on result quality
        $confidence_score = self::calculate_nominatim_confidence($result);
        
        return array(
            'latitude' => $latitude,
            'longitude' => $longitude,
            'confidence_score' => $confidence_score,
            'display_name' => isset($result['display_name']) ? $result['display_name'] : '',
            'place_type' => isset($result['type']) ? $result['type'] : '',
            'place_class' => isset($result['class']) ? $result['class'] : '',
            'address_components' => isset($result['address']) ? $result['address'] : array(),
            'boundingbox' => isset($result['boundingbox']) ? $result['boundingbox'] : array(),
            'raw_response' => $result
        );
    }

    /**
     * Geocode with Google Maps Geocoding API
     * 
     * @param string $address Address to geocode
     * @param array $options Geocoding options
     * @return array|false Geocoding result or false on failure
     */
    private static function geocode_with_google($address, $options)
    {
        // Get Google API key from WordPress options
        $api_key = get_option('map_integration_google_api_key', '');
        
        if (empty($api_key)) {
            self::log_message("Google API key not configured");
            return false;
        }
        
        // Build query URL
        $query_params = array(
            'address' => $address,
            'key' => $api_key
        );
        
        if (!empty($options['country_code'])) {
            $query_params['region'] = $options['country_code'];
        }
        
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($query_params);
        
        // Make API request
        $response = self::make_http_request($url, $options['timeout']);
        
        if (!$response) {
            return false;
        }
        
        $data = json_decode($response['body'], true);
        
        if (empty($data) || $data['status'] !== 'OK' || empty($data['results'])) {
            self::log_message("Google API error: " . (isset($data['status']) ? $data['status'] : 'Unknown'));
            return false;
        }
        
        $result = $data['results'][0];
        
        // Extract coordinates
        $latitude = floatval($result['geometry']['location']['lat']);
        $longitude = floatval($result['geometry']['location']['lng']);
        
        if ($latitude == 0 && $longitude == 0) {
            return false;
        }
        
        // Calculate confidence score based on result quality
        $confidence_score = self::calculate_google_confidence($result);
        
        return array(
            'latitude' => $latitude,
            'longitude' => $longitude,
            'confidence_score' => $confidence_score,
            'display_name' => isset($result['formatted_address']) ? $result['formatted_address'] : '',
            'place_type' => self::extract_google_place_type($result),
            'place_class' => isset($result['geometry']['location_type']) ? $result['geometry']['location_type'] : '',
            'address_components' => isset($result['address_components']) ? $result['address_components'] : array(),
            'viewport' => isset($result['geometry']['viewport']) ? $result['geometry']['viewport'] : array(),
            'raw_response' => $result
        );
    }

    /**
     * Calculate confidence score for Nominatim results
     * 
     * @param array $result Nominatim result
     * @return int Confidence score (0-100)
     */
    private static function calculate_nominatim_confidence($result)
    {
        $score = 0;
        
        // Base score from importance
        if (isset($result['importance'])) {
            $score += intval($result['importance'] * 50);
        } else {
            $score += 30; // Default base score
        }
        
        // Boost for specific place types
        if (isset($result['type'])) {
            $type = $result['type'];
            if (in_array($type, array('house', 'building', 'commercial'))) {
                $score += 30;
            } elseif (in_array($type, array('residential', 'industrial'))) {
                $score += 20;
            } elseif (in_array($type, array('road', 'street'))) {
                $score += 15;
            } else {
                $score += 10;
            }
        }
        
        // Reduce score for very general results
        if (isset($result['class'])) {
            $class = $result['class'];
            if (in_array($class, array('boundary', 'place'))) {
                $score -= 20;
            }
        }
        
        // Boost for having detailed address components
        if (isset($result['address']) && is_array($result['address'])) {
            $address_components = count($result['address']);
            if ($address_components >= 5) {
                $score += 10;
            } elseif ($address_components >= 3) {
                $score += 5;
            }
        }
        
        return max(0, min(100, $score));
    }

    /**
     * Calculate confidence score for Google results
     * 
     * @param array $result Google result
     * @return int Confidence score (0-100)
     */
    private static function calculate_google_confidence($result)
    {
        $score = 50; // Base score
        
        // Score based on geometry location type
        if (isset($result['geometry']['location_type'])) {
            $location_type = $result['geometry']['location_type'];
            switch ($location_type) {
                case 'ROOFTOP':
                    $score += 40;
                    break;
                case 'RANGE_INTERPOLATED':
                    $score += 30;
                    break;
                case 'GEOMETRIC_CENTER':
                    $score += 20;
                    break;
                case 'APPROXIMATE':
                    $score += 10;
                    break;
            }
        }
        
        // Boost for partial matches
        if (isset($result['partial_match']) && $result['partial_match']) {
            $score -= 15;
        }
        
        // Score based on address component count and types
        if (isset($result['address_components']) && is_array($result['address_components'])) {
            $component_count = count($result['address_components']);
            if ($component_count >= 6) {
                $score += 10;
            } elseif ($component_count >= 4) {
                $score += 5;
            }
        }
        
        return max(0, min(100, $score));
    }

    /**
     * Extract primary place type from Google result
     * 
     * @param array $result Google result
     * @return string Primary place type
     */
    private static function extract_google_place_type($result)
    {
        if (empty($result['types']) || !is_array($result['types'])) {
            return '';
        }
        
        // Priority order for place types
        $priority_types = array(
            'street_address', 'premise', 'subpremise', 'route',
            'intersection', 'political', 'colloquial_area',
            'administrative_area_level_1', 'administrative_area_level_2',
            'locality', 'sublocality'
        );
        
        foreach ($priority_types as $priority_type) {
            if (in_array($priority_type, $result['types'])) {
                return $priority_type;
            }
        }
        
        // Return first type if no priority match
        return $result['types'][0];
    }

    /**
     * Check if provider is available
     * 
     * @param string $provider Provider name
     * @return bool Whether provider is available
     */
    private static function is_provider_available($provider)
    {
        switch ($provider) {
            case self::PROVIDER_NOMINATIM:
                return true; // Always available
                
            case self::PROVIDER_GOOGLE:
                $api_key = get_option('map_integration_google_api_key', '');
                return !empty($api_key);
                
            default:
                return false;
        }
    }

    /**
     * Enforce rate limiting for provider
     * 
     * @param string $provider Provider name
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
            usleep($sleep_time * 1000000); // Convert to microseconds
        }
        
        self::$rate_limits[$provider]['last_request_time'] = microtime(true);
    }

    /**
     * Make HTTP request with error handling
     * 
     * @param string $url Request URL
     * @param int $timeout Timeout in seconds
     * @return array|false Response array or false on failure
     */
    private static function make_http_request($url, $timeout = 10)
    {
        self::log_message("Making API request to: {$url}");
        
        $args = array(
            'timeout' => $timeout,
            'user-agent' => 'MapIntegration/1.0.0 WordPress Plugin',
            'headers' => array(
                'Accept' => 'application/json'
            )
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            self::log_message("HTTP request error: " . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        self::log_message("API Response: Code {$status_code}");
        
        if ($status_code !== 200) {
            self::log_message("API error response: {$body}");
            return false;
        }
        
        if (empty($body)) {
            self::log_message("Empty API response");
            return false;
        }
        
        return array(
            'body' => $body,
            'status_code' => $status_code
        );
    }

    /**
     * Normalize address for consistent caching
     * 
     * @param string $address Raw address
     * @return string Normalized address for cache key
     */
    private static function normalize_address_for_cache($address)
    {
        // Convert to lowercase
        $address = strtolower(trim($address));
        
        // Remove extra whitespace
        $address = preg_replace('/\s+/', ' ', $address);
        
        // Remove common punctuation
        $address = str_replace(array(',', '.', ';', ':'), '', $address);
        
        return $address;
    }

    /**
     * Get cached geocoding result
     * 
     * @param string $address Normalized address
     * @return array|false Cached result or false if not found
     */
    private static function get_cached_result($address)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$cache_table;
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE original_address = %s ORDER BY created_at DESC LIMIT 1",
            $address
        ), ARRAY_A);
        
        if (!$result) {
            return false;
        }
        
        // Check if cache is still valid
        $cache_age = time() - strtotime($result['created_at']);
        $max_age = get_option('map_integration_cache_ttl', 30 * DAY_IN_SECONDS);
        
        if ($cache_age > $max_age) {
            // Cache expired, remove it
            self::delete_cached_result($result['id']);
            return false;
        }
        
        return array(
            'latitude' => floatval($result['latitude']),
            'longitude' => floatval($result['longitude']),
            'confidence_score' => intval($result['confidence_score']),
            'provider' => $result['provider'],
            'cached_at' => $result['created_at'],
            'display_name' => $result['parsed_address']
        );
    }

    /**
     * Cache geocoding result
     * 
     * @param string $address Normalized address
     * @param array $result Geocoding result
     * @param int $ttl Time to live in seconds
     */
    private static function cache_result($address, $result, $ttl)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$cache_table;
        
        $wpdb->insert(
            $table_name,
            array(
                'original_address' => $address,
                'parsed_address' => $result['display_name'],
                'latitude' => $result['latitude'],
                'longitude' => $result['longitude'],
                'provider' => $result['provider'],
                'confidence_score' => $result['confidence_score'],
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%f', '%f', '%s', '%d', '%s')
        );
        
        if ($wpdb->last_error) {
            self::log_message("Cache insert error: " . $wpdb->last_error);
        }
    }

    /**
     * Delete cached result by ID
     * 
     * @param int $id Cache entry ID
     */
    private static function delete_cached_result($id)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$cache_table;
        
        $wpdb->delete(
            $table_name,
            array('id' => $id),
            array('%d')
        );
    }

    /**
     * Clear geocoding cache
     * 
     * @param array $options Clear options
     * @return int Number of entries cleared
     */
    public static function clear_cache($options = array())
    {
        global $wpdb;
        
        $options = wp_parse_args($options, array(
            'older_than' => null, // Clear entries older than X seconds
            'provider' => null    // Clear entries from specific provider
        ));
        
        $table_name = $wpdb->prefix . self::$cache_table;
        
        $where = array();
        $where_values = array();
        
        if (!empty($options['older_than'])) {
            $cutoff_date = date('Y-m-d H:i:s', time() - $options['older_than']);
            $where[] = 'created_at < %s';
            $where_values[] = $cutoff_date;
        }
        
        if (!empty($options['provider'])) {
            $where[] = 'provider = %s';
            $where_values[] = $options['provider'];
        }
        
        $query = "DELETE FROM {$table_name}";
        if (!empty($where)) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }
        
        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }
        
        $deleted = $wpdb->query($query);
        
        self::log_message("Cleared {$deleted} geocoding cache entries");
        
        return intval($deleted);
    }

    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public static function get_cache_stats()
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$cache_table;
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            return array(
                'total_entries' => 0,
                'providers' => array(),
                'oldest_entry' => null,
                'newest_entry' => null
            );
        }
        
        $stats = array();
        
        // Total entries
        $stats['total_entries'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"));
        
        // Entries by provider
        $provider_stats = $wpdb->get_results(
            "SELECT provider, COUNT(*) as count FROM {$table_name} GROUP BY provider",
            ARRAY_A
        );
        
        $stats['providers'] = array();
        foreach ($provider_stats as $stat) {
            $stats['providers'][$stat['provider']] = intval($stat['count']);
        }
        
        // Date range
        $stats['oldest_entry'] = $wpdb->get_var("SELECT MIN(created_at) FROM {$table_name}");
        $stats['newest_entry'] = $wpdb->get_var("SELECT MAX(created_at) FROM {$table_name}");
        
        return $stats;
    }

    /**
     * Log message (uses existing MapGeocoder logging if available, otherwise error_log)
     * 
     * @param string $message Message to log
     */
    private static function log_message($message)
    {
        if (class_exists('MapGeocoder') && method_exists('MapGeocoder', 'log_message')) {
            MapGeocoder::log_message($message);
        } else {
            error_log("[Map Integration Geocoding] " . $message);
        }
    }
}