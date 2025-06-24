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

/**
 * Geocoding Class for handling Nominatim API requests
 */
class MapGeocoder
{

    private static $last_request_time = 0;
    /**
     * Write to custom geocode log file
     */
    public static function log_message($message)
    {
        $log_file = MAP_INTEGRATION_PLUGIN_PATH . 'geocodelogs.txt';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Geocode an address using Nominatim with fallback strategies
     */
    public static function geocode_address($street, $city, $province)
    {
        // Clean and normalize inputs
        $street = self::clean_address_part($street);
        $city = self::clean_address_part($city);
        $province = self::clean_address_part($province);

        // Try multiple address combinations in order of specificity
        $address_variations = self::build_address_variations($street, $city, $province);

        foreach ($address_variations as $address) {
            if (empty($address)) continue;

            self::log_message("Trying geocode for: {$address}");
            $coordinates = self::try_geocode($address);

            if ($coordinates) {
                self::log_message("Successfully geocoded: {$address}");
                return $coordinates;
            }
        }

        self::log_message("All geocoding attempts failed for: street={$street}, city={$city}, province={$province}");
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
     * Try geocoding a single address string
     */
    private static function try_geocode($address)
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

        self::log_message("Making API request to: {$url}");

        // Log the exact GET request URL
        self::log_message("Geocoding GET request: {$url}");

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'WordPress Map Integration Plugin'
            )
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            self::log_message("API request failed with WP_Error: {$error_message}");
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $body = wp_remote_retrieve_body($response);

        self::log_message("API Response: Code {$response_code} - {$response_message}");

        if ($response_code !== 200) {
            self::log_message("API returned non-200 status. Response body: " . substr($body, 0, 500));
            return false;
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log_message("JSON decode error: " . json_last_error_msg() . ". Raw response: " . substr($body, 0, 500));
            return false;
        }

        self::log_message("API returned " . count($data) . " results");

        if (empty($data)) {
            self::log_message("No geocoding results found for address: {$address}");
            return false;
        }

        if (!isset($data[0]['lat']) || !isset($data[0]['lon'])) {
            self::log_message("Invalid result structure. First result: " . json_encode($data[0]));
            return false;
        }

        $result = array(
            'lat' => floatval($data[0]['lat']),
            'lng' => floatval($data[0]['lon'])
        );

        // Log additional details about the result
        $display_name = isset($data[0]['display_name']) ? $data[0]['display_name'] : 'N/A';
        $place_type = isset($data[0]['type']) ? $data[0]['type'] : 'N/A';
        self::log_message("Geocoding successful: lat={$result['lat']}, lng={$result['lng']}, display_name='{$display_name}', type='{$place_type}'");

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

        update_user_meta($user_id, $lat_key, $coordinates['lat']);
        update_user_meta($user_id, $lng_key, $coordinates['lng']);
        update_user_meta($user_id, $time_key, current_time('mysql'));

        return true;
    }

    /**
     * Get stored coordinates for a user
     */
    public static function get_coordinates($user_id, $suffix = '')
    {
        $lat_key = 'mepr_clinic_lat' . $suffix;
        $lng_key = 'mepr_clinic_lng' . $suffix;

        $lat = get_user_meta($user_id, $lat_key, true);
        $lng = get_user_meta($user_id, $lng_key, true);

        if (empty($lat) || empty($lng)) {
            return false;
        }

        return array(
            'lat' => floatval($lat),
            'lng' => floatval($lng)
        );
    }

    /**
     * Check if coordinates are province-level (too general)
     */
    public static function is_province_level_coordinate($lat, $suffix, $user_id)
    {
        $lng = get_user_meta($user_id, 'mepr_clinic_lng' . $suffix, true);

        // Nova Scotia province center coordinates (approximate)
        $ns_lat = 44.6820;
        $ns_lng = -63.7443;

        // If coordinates are very close to province center, consider them too general
        $lat_diff = abs(floatval($lat) - $ns_lat);
        $lng_diff = abs(floatval($lng) - $ns_lng);

        return ($lat_diff < 0.5 && $lng_diff < 0.5);
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

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Hook into user meta updates to trigger geocoding
        add_action('updated_user_meta', array($this, 'handle_user_meta_update'), 10, 4);
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        // Add any activation logic here
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
    }
    /**
     * Admin page content
     */
    public function admin_page()
    {
        // Handle bulk geocoding action
        if (isset($_POST['bulk_geocode']) && wp_verify_nonce($_POST['_wpnonce'], 'bulk_geocode_action')) {
            $this->bulk_geocode_users();
        }

        // Handle clear geocoding data action
        if (isset($_POST['clear_geocoding']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_geocoding_action')) {
            $this->clear_all_geocoding_data();
        }

        // Handle clear geocoding data action
        if (isset($_POST['clear_geocoding_data']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_geocoding_data_action')) {
            $this->clear_all_geocoding_data();
        }

        // Get geocoding statistics
        $stats = $this->get_geocoding_stats();

?>
        <div class="wrap">
            <h1>Map Integration Settings</h1>

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
            </table>

            <h2>Addresses Not Geocoded</h2>
            <?php
            $non_geocoded = $this->get_non_geocoded_addresses();
            if (!empty($non_geocoded)): ?>
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
                <p>All addresses have been geocoded.</p>
            <?php endif; ?>

            <h2>Bulk Geocoding</h2>
            <p>Geocode all existing clinic addresses that don't have coordinates yet.</p>
            <form method="post" action="">
                <?php wp_nonce_field('bulk_geocode_action'); ?>
                <input type="submit" name="bulk_geocode" class="button button-primary" value="Run Bulk Geocoding"
                    onclick="return confirm('This may take a while. Continue?');">
                <p><em>Note: This respects Nominatim's rate limits (1 request per second).</em></p>
            </form>

            <h2>Clear Geocoding Data</h2>
            <p>Remove all geocoding data (coordinates, timestamps) from all users.</p>
            <form method="post" action="">
                <?php wp_nonce_field('clear_geocoding_data_action'); ?>
                <input type="submit" name="clear_geocoding_data" class="button button-danger" value="Clear All Geocoding Data"
                    onclick="return confirm('Are you sure you want to clear all geocoding data from all users? This action cannot be undone.');">
            </form>

            <h2>Usage</h2>
            <p>The plugin automatically geocodes clinic addresses when they are updated.</p>

            <h3>Map Shortcodes</h3>
            <p>Use these shortcodes to display maps:</p>
            <ul>
                <li><code>[map_integration]</code> - Display all clinic locations on an interactive map</li>
                <li><code>[map_integration width="800px" height="500px"]</code> - Custom size map</li>
                <li><code>[map_integration center_lat="44.6488" center_lng="-63.5752" zoom="8"]</code> - Custom center and zoom</li>
                <li><code>[map_integration show_clinics="false" location="Custom Location"]</code> - Legacy placeholder map</li>
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
            'center_lat' => '44.6488', // Default to Nova Scotia
            'center_lng' => '-63.5752',
            'zoom' => '7',
            'user_role' => 'subscriber' // Default to subscribers only
        ), $atts);

        if ($atts['show_clinics'] === 'true') {
            return $this->display_clinic_map($atts);
        } else {
            // Legacy placeholder map
            $output = '<div class="map-integration-container" style="width: ' . esc_attr($atts['width']) . '; height: ' . esc_attr($atts['height']) . ';">';
            $output .= '<div class="map-placeholder">';
            $output .= '<p>Map Integration Placeholder</p>';
            $output .= '<p>Location: ' . esc_html($atts['location']) . '</p>';
            $output .= '<p><em>Connect your preferred map service API to display interactive maps here.</em></p>';
            $output .= '</div>';
            $output .= '</div>';
            return $output;
        }
    }
    /**
     * Display interactive clinic map with Leaflet.js
     */
    public function display_clinic_map($atts)
    {
        // Get all clinic locations with coordinates (filtered by user role)
        $clinic_data = $this->get_all_clinic_coordinates($atts['user_role']);

        // Output clinic data to browser console for debugging
        echo '<script>console.log("Clinic Data:", ' . json_encode($clinic_data) . ');</script>';

        // Generate unique map ID
        $map_id = 'map-integration-' . uniqid();

        // Create map HTML
        $output = '<div id="' . esc_attr($map_id) . '" class="map-integration-leaflet" style="width: ' . esc_attr($atts['width']) . '; height: ' . esc_attr($atts['height']) . ';"></div>';
        $output .= "<script type=\"text/javascript\">\n        document.addEventListener(\"DOMContentLoaded\", function() {\n            function initializeMap() {\n                if (typeof L === \"undefined\" || typeof L.Control === \"undefined\") {\n                    setTimeout(initializeMap, 100);\n                    return;\n                }\n                try {\n                    var map = L.map(\"" . esc_js($map_id) . "\").setView([" . floatval($atts['center_lat']) . ", " . floatval($atts['center_lng']) . "], " . intval($atts['zoom']) . ");\n                    var tileLayer = L.tileLayer(\"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png\", {\n                        attribution: \"&copy; <a href=\\\"https://www.openstreetmap.org/copyright\\\">OpenStreetMap</a> contributors\",\n                        maxZoom: 18\n                    });\n                    tileLayer.addTo(map);\n                    var clinics = " . json_encode($clinic_data) . ";\n                    var bounds = [];\n                    var markers = [];\n                    clinics.forEach(function(clinic, index) {\n                        if (clinic.lat && clinic.lng) {\n                            var marker = L.marker([clinic.lat, clinic.lng]).addTo(map);\n                            marker.bindPopup((clinic.name ? '<h4>' + clinic.name + '</h4>' : '') +\n                                (clinic.address ? '<p>' + clinic.address + '</p>' : '') +\n                                (clinic.phone ? '<p>Phone: ' + clinic.phone + '</p>' : '') +\n                                (clinic.email ? '<p>Email: ' + clinic.email + '</p>' : '') +\n                                (clinic.website ? '<p><a href=\"' + clinic.website + '\" target=\"_blank\">Website</a></p>' : ''));\n                            marker.clinicName = clinic.name || '';\n                            markers.push(marker);\n                            bounds.push([clinic.lat, clinic.lng]);\n                        }\n                    });\n                    if (bounds.length > 0) {\n                        map.fitBounds(bounds, {padding: [20, 20]});\n                    }\n                    if (typeof L.Control.Search !== \"undefined\") {\n                        var markerLayer = L.layerGroup(markers);\n                        var searchControl = new L.Control.Search({\n                            layer: markerLayer,\n                            propertyName: 'clinicName',\n                            marker: false,\n                            moveToLocation: function(latlng, title, map) {\n                                map.setView(latlng, 14);\n                            }\n                        });\n                        searchControl.on('search:locationfound', function(e) {\n                            if (e.layer._popup) e.layer.openPopup();\n                        });\n                        map.addControl(searchControl);\n                    }\n                    setTimeout(function() { if (map) { map.invalidateSize(); } }, 250);\n\n                    // Add global function to center on a clinic by name\n                    window.centerMapOnClinic = function(clinicName) {\n                        var found = false;\n                        markers.forEach(function(marker) {\n                            if (marker.clinicName && marker.clinicName.toLowerCase() === clinicName.toLowerCase()) {\n                                // First scroll to the map\n                                var mapElement = document.getElementById(\"" . esc_js($map_id) . "\");\n                                if (mapElement) {\n                                    mapElement.scrollIntoView({ \n                                        behavior: 'smooth', \n                                        block: 'center' \n                                    });\n                                }\n                                \n                                // Then center the map on the clinic\n                                setTimeout(function() {\n                                    map.setView(marker.getLatLng(), 16, {\n                                        animate: true,\n                                        duration: 1.5\n                                    });\n                                    setTimeout(function() {\n                                        marker.openPopup();\n                                    }, 1600);\n                                }, 500); // Wait for scroll to complete\n                                found = true;\n                                return false;\n                            }\n                        });\n                        if (!found) {\n                            console.log('Available clinics:', markers.map(function(m) { return m.clinicName; }));\n                            alert('Clinic \\\"' + clinicName + '\\\" not found on map.');\n                        }\n                    };\n                } catch (error) {\n                    console.error(\"Map initialization error:\", error);\n                    document.getElementById(\"" . esc_js($map_id) . "\").innerHTML = \"<div style=\\\"padding: 20px; text-align: center; color: #666;\\\">Error loading map. Please refresh the page.</div>\";\n                }\n            }\n            initializeMap();\n        });\n        </script>";

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

        // Geocode the address
        $coordinates = MapGeocoder::geocode_address($street, $city, $province);
        if ($coordinates) {
            MapGeocoder::save_coordinates($user_id, $coordinates, $address_suffix);
            MapGeocoder::log_message("Successfully geocoded address for user {$user_id} (suffix: {$address_suffix})");
        } else {
            MapGeocoder::log_message("Failed to geocode address for user {$user_id} (suffix: {$address_suffix})");
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

            // Get users with address but no coordinates
            $users = $wpdb->get_results($wpdb->prepare("
                SELECT u.ID, u.display_name
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
                WHERE um.meta_key = %s AND um.meta_value != ''
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->usermeta} um2 
                    WHERE um2.user_id = u.ID AND um2.meta_key = %s AND um2.meta_value != ''
                )
            ", $street_key, $lat_key));

            foreach ($users as $user) {
                $street = get_user_meta($user->ID, $street_key, true);
                $city = get_user_meta($user->ID, $city_key, true);
                $province = get_user_meta($user->ID, $province_key, true);

                // Only include addresses with street or city data (exclude province-only)
                if (!empty($street) || !empty($city)) {
                    $non_geocoded[] = array(
                        'user_id' => $user->ID,
                        'user_name' => $user->display_name,
                        'type' => $label,
                        'street' => $street,
                        'city' => $city,
                        'province' => $province
                    );
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
            'mepr_clinic_lat_2',
            'mepr_clinic_lng_2',
            'mepr_clinic_geocoded_at_2',
            'mepr_clinic_lat_3',
            'mepr_clinic_lng_3',
            'mepr_clinic_geocoded_at_3'
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
}

// Initialize the plugin
new MapIntegration();
