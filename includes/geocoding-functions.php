<?php

/**
 * Geocoding Utility Functions
 * 
 * Provides easy-to-use functions for integration with the geocoding system.
 * Includes geocode_address(), parse_street_address(), and clear_geocoding_cache().
 * 
 * @package MapIntegration
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Geocode an address using the integrated geocoding service
 * 
 * @param string $address The address to geocode
 * @param array $options Optional geocoding options
 * @return array|false Geocoding result with lat/lng or false on failure
 */
function geocode_address($address, $options = array()) {
    // Ensure geocoding service is loaded
    if (!class_exists('Map_Integration_Geocoding_Service')) {
        return false;
    }
    
    // Set default options
    $default_options = array(
        'providers' => array('nominatim', 'google'),
        'use_cache' => true,
        'country_code' => 'ca'
    );
    
    $options = wp_parse_args($options, $default_options);
    
    // Call geocoding service
    $result = Map_Integration_Geocoding_Service::geocode_address($address, $options);
    
    if (!$result) {
        return false;
    }
    
    // Return simplified result for backward compatibility
    return array(
        'lat' => $result['latitude'],
        'lng' => $result['longitude'],
        'latitude' => $result['latitude'],
        'longitude' => $result['longitude'],
        'confidence_score' => $result['confidence_score'],
        'provider' => $result['provider'],
        'display_name' => isset($result['display_name']) ? $result['display_name'] : '',
        'full_result' => $result
    );
}

/**
 * Parse a street address into components
 * 
 * @param string $address The street address to parse
 * @return array Parsed address components
 */
function parse_street_address($address) {
    // Ensure street parser is loaded
    if (!class_exists('Map_Integration_Street_Parser')) {
        return array();
    }
    
    return Map_Integration_Street_Parser::parse_address($address);
}

/**
 * Clear the geocoding cache
 * 
 * @param array $options Optional clear options
 * @return int Number of cache entries cleared
 */
function clear_geocoding_cache($options = array()) {
    // Ensure geocoding service is loaded
    if (!class_exists('Map_Integration_Geocoding_Service')) {
        return 0;
    }
    
    return Map_Integration_Geocoding_Service::clear_cache($options);
}

/**
 * Get geocoding cache statistics
 * 
 * @return array Cache statistics
 */
function get_geocoding_cache_stats() {
    // Ensure geocoding service is loaded
    if (!class_exists('Map_Integration_Geocoding_Service')) {
        return array();
    }
    
    return Map_Integration_Geocoding_Service::get_cache_stats();
}

/**
 * Validate and normalize an address before geocoding
 * 
 * @param string $street Street address
 * @param string $city City
 * @param string $province Province/state
 * @param string $country Country (optional)
 * @return string Formatted address
 */
function format_address_for_geocoding($street, $city, $province, $country = 'Canada') {
    $parts = array();
    
    if (!empty($street)) {
        $parts[] = trim($street);
    }
    
    if (!empty($city)) {
        $parts[] = trim($city);
    }
    
    if (!empty($province)) {
        // Normalize province codes
        $province = trim($province);
        if (strtolower($province) === 'ns') {
            $province = 'Nova Scotia';
        } elseif (strtolower($province) === 'on') {
            $province = 'Ontario';
        } elseif (strtolower($province) === 'bc') {
            $province = 'British Columbia';
        } elseif (strtolower($province) === 'ab') {
            $province = 'Alberta';
        } elseif (strtolower($province) === 'mb') {
            $province = 'Manitoba';
        } elseif (strtolower($province) === 'sk') {
            $province = 'Saskatchewan';
        } elseif (strtolower($province) === 'qc') {
            $province = 'Quebec';
        } elseif (strtolower($province) === 'nb') {
            $province = 'New Brunswick';
        } elseif (strtolower($province) === 'pe') {
            $province = 'Prince Edward Island';
        } elseif (strtolower($province) === 'nl') {
            $province = 'Newfoundland and Labrador';
        } elseif (strtolower($province) === 'nt') {
            $province = 'Northwest Territories';
        } elseif (strtolower($province) === 'nu') {
            $province = 'Nunavut';
        } elseif (strtolower($province) === 'yt') {
            $province = 'Yukon';
        }
        
        $parts[] = $province;
    }
    
    if (!empty($country)) {
        $parts[] = trim($country);
    }
    
    return implode(', ', $parts);
}

/**
 * Get the distance between two coordinates in kilometers
 * 
 * @param float $lat1 Latitude of first point
 * @param float $lng1 Longitude of first point
 * @param float $lat2 Latitude of second point
 * @param float $lng2 Longitude of second point
 * @return float Distance in kilometers
 */
function calculate_distance($lat1, $lng1, $lat2, $lng2) {
    $earth_radius = 6371; // Earth's radius in kilometers
    
    $lat1_rad = deg2rad($lat1);
    $lng1_rad = deg2rad($lng1);
    $lat2_rad = deg2rad($lat2);
    $lng2_rad = deg2rad($lng2);
    
    $delta_lat = $lat2_rad - $lat1_rad;
    $delta_lng = $lng2_rad - $lng1_rad;
    
    $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
         cos($lat1_rad) * cos($lat2_rad) *
         sin($delta_lng / 2) * sin($delta_lng / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earth_radius * $c;
}

/**
 * Validate coordinates
 * 
 * @param float $latitude Latitude
 * @param float $longitude Longitude
 * @return bool Whether coordinates are valid
 */
function validate_coordinates($latitude, $longitude) {
    $lat = floatval($latitude);
    $lng = floatval($longitude);
    
    return ($lat >= -90 && $lat <= 90) && ($lng >= -180 && $lng <= 180) && !($lat == 0 && $lng == 0);
}

/**
 * Get coordinates for a user's address
 * 
 * @param int $user_id User ID
 * @param string $suffix Address suffix (e.g., '', '_2', '_3')
 * @return array|false Coordinates array or false if not found
 */
function get_user_coordinates($user_id, $suffix = '') {
    $lat_key = 'mepr_clinic_lat' . $suffix;
    $lng_key = 'mepr_clinic_lng' . $suffix;
    
    $lat = get_user_meta($user_id, $lat_key, true);
    $lng = get_user_meta($user_id, $lng_key, true);
    
    if (empty($lat) || empty($lng) || !validate_coordinates($lat, $lng)) {
        return false;
    }
    
    return array(
        'latitude' => floatval($lat),
        'longitude' => floatval($lng),
        'lat' => floatval($lat),
        'lng' => floatval($lng)
    );
}

/**
 * Save coordinates for a user's address
 * 
 * @param int $user_id User ID
 * @param array $coordinates Coordinates array with lat/lng
 * @param string $suffix Address suffix (e.g., '', '_2', '_3')
 * @return bool Success status
 */
function save_user_coordinates($user_id, $coordinates, $suffix = '') {
    if (empty($coordinates) || !isset($coordinates['lat']) || !isset($coordinates['lng'])) {
        return false;
    }
    
    $lat = floatval($coordinates['lat']);
    $lng = floatval($coordinates['lng']);
    
    if (!validate_coordinates($lat, $lng)) {
        return false;
    }
    
    $lat_key = 'mepr_clinic_lat' . $suffix;
    $lng_key = 'mepr_clinic_lng' . $suffix;
    $time_key = 'mepr_clinic_geocoded_at' . $suffix;
    
    update_user_meta($user_id, $lat_key, $lat);
    update_user_meta($user_id, $lng_key, $lng);
    update_user_meta($user_id, $time_key, current_time('mysql'));
    
    return true;
}

/**
 * Get address string for a user
 * 
 * @param int $user_id User ID
 * @param string $suffix Address suffix (e.g., '', '_2', '_3')
 * @return string Formatted address
 */
function get_user_address($user_id, $suffix = '') {
    $street_key = 'mepr_clinic_street' . $suffix;
    $city_key = 'mepr_clinic_city' . $suffix;
    $province_key = 'mepr_clinic_province' . $suffix;
    
    $street = get_user_meta($user_id, $street_key, true);
    $city = get_user_meta($user_id, $city_key, true);
    $province = get_user_meta($user_id, $province_key, true);
    
    return format_address_for_geocoding($street, $city, $province);
}

/**
 * Bulk geocode multiple addresses
 * 
 * @param array $addresses Array of addresses to geocode
 * @param array $options Geocoding options
 * @return array Results array with success/failure counts
 */
function bulk_geocode_addresses($addresses, $options = array()) {
    $results = array(
        'total' => count($addresses),
        'success' => 0,
        'failed' => 0,
        'results' => array()
    );
    
    $default_options = array(
        'delay' => 1, // Delay between requests in seconds
        'providers' => array('nominatim', 'google'),
        'use_cache' => true
    );
    
    $options = wp_parse_args($options, $default_options);
    
    foreach ($addresses as $index => $address) {
        if (!empty($address)) {
            $result = geocode_address($address, $options);
            
            if ($result) {
                $results['success']++;
                $results['results'][$index] = $result;
            } else {
                $results['failed']++;
                $results['results'][$index] = false;
            }
            
            // Respect rate limiting
            if ($options['delay'] > 0 && $index < count($addresses) - 1) {
                sleep($options['delay']);
            }
        } else {
            $results['results'][$index] = false;
        }
    }
    
    return $results;
}

/**
 * Check if an address needs geocoding
 * 
 * @param int $user_id User ID
 * @param string $suffix Address suffix
 * @return bool Whether geocoding is needed
 */
function address_needs_geocoding($user_id, $suffix = '') {
    // Check if coordinates exist
    $coordinates = get_user_coordinates($user_id, $suffix);
    if ($coordinates) {
        // Check if coordinates are too general (province-level)
        if (class_exists('MapGeocoder') && method_exists('MapGeocoder', 'is_province_level_coordinate')) {
            return MapGeocoder::is_province_level_coordinate($coordinates['lat'], $suffix, $user_id);
        }
        return false;
    }
    
    // Check if address exists
    $address = get_user_address($user_id, $suffix);
    return !empty($address);
}

/**
 * Get all users who need geocoding
 * 
 * @param int $limit Optional limit on results
 * @return array Array of user IDs and address suffixes that need geocoding
 */
function get_users_needing_geocoding($limit = 100) {
    global $wpdb;
    
    $users_needing_geocoding = array();
    
    // Address sets to check
    $address_sets = array(
        '' => array('street' => 'mepr_clinic_street', 'city' => 'mepr_clinic_city', 'province' => 'mepr_clinic_province'),
        '_2' => array('street' => 'mepr_clinic_street_2', 'city' => 'mepr_clinic_city_2', 'province' => 'mepr_clinic_province_2'),
        '_3' => array('street' => 'mepr_clinic_street_3', 'city' => 'mepr_clinic_city_3', 'province' => 'mepr_clinic_province_3')
    );
    
    foreach ($address_sets as $suffix => $fields) {
        // Find users with addresses but missing coordinates
        // Use separate queries to avoid SQL injection issues with IN clause
        $users_with_street = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT user_id 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = %s 
            AND meta_value != ''
            LIMIT %d
        ", $fields['street'], $limit), ARRAY_A);
        
        $users_with_city = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT user_id 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = %s 
            AND meta_value != ''
            LIMIT %d
        ", $fields['city'], $limit), ARRAY_A);
        
        // Merge and deduplicate user IDs
        $all_user_ids = array();
        foreach ($users_with_street as $user) {
            $all_user_ids[$user['user_id']] = $user;
        }
        foreach ($users_with_city as $user) {
            $all_user_ids[$user['user_id']] = $user;
        }
        $users_with_addresses = array_values($all_user_ids);
        
        foreach ($users_with_addresses as $user_row) {
            $user_id = $user_row['user_id'];
            
            if (address_needs_geocoding($user_id, $suffix)) {
                $users_needing_geocoding[] = array(
                    'user_id' => $user_id,
                    'suffix' => $suffix,
                    'address' => get_user_address($user_id, $suffix)
                );
                
                if (count($users_needing_geocoding) >= $limit) {
                    break 2;
                }
            }
        }
    }
    
    return $users_needing_geocoding;
}