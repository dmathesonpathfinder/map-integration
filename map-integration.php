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
        
        // Add shortcode for displaying chiropractor locations list
        add_shortcode('chiropractor_locations', array($this, 'display_chiropractor_list_shortcode'));

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
        // Handle Google API key setting
        if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'save_settings_action')) {
            $google_api_key = sanitize_text_field($_POST['google_api_key']);
            update_option('map_integration_google_api_key', $google_api_key);
            echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
        }
        
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

            <h2>Geocoding Settings</h2>
            <form method="post">
                <?php wp_nonce_field('save_settings_action'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Google Maps API Key</th>
                        <td>
                            <input type="text" name="google_api_key" class="regular-text" 
                                   value="<?php echo esc_attr(get_option('map_integration_google_api_key', '')); ?>" />
                            <p class="description">Optional: Enter your Google Maps API key to enable Google geocoding as a fallback provider.</p>
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

            <h3>Chiropractor List Shortcode</h3>
            <p>Use this shortcode to display a list of all chiropractor locations:</p>
            <ul>
                <li><code>[chiropractor_locations]</code> - Display all chiropractor locations with full details, search, and sort</li>
                <li><code>[chiropractor_locations show_search="false"]</code> - Hide the search functionality</li>
                <li><code>[chiropractor_locations show_sort="false"]</code> - Hide the sort dropdown</li>
                <li><code>[chiropractor_locations default_sort="city"]</code> - Set default sort order (name, clinic, city, province, map_status)</li>
                <li><code>[chiropractor_locations show_phone="false"]</code> - Hide phone numbers</li>
                <li><code>[chiropractor_locations show_email="false"]</code> - Hide email addresses</li>
                <li><code>[chiropractor_locations show_website="false"]</code> - Hide website links</li>
                <li><code>[chiropractor_locations show_coordinates="true"]</code> - Show latitude/longitude coordinates</li>
                <li><code>[chiropractor_locations user_role="subscriber"]</code> - Filter by user role</li>
            </ul>
            <p><strong>Search Features:</strong> The list includes intelligent fuzzy search that matches chiropractor names, clinic names, cities, addresses, and contact information. It handles typos and partial matches automatically.</p>
            <p><strong>Sort Options:</strong> Choose from multiple sort orders including chiropractor name, clinic name, city, province, or map status (locations with/without coordinates). Both ascending and descending orders are available.</p>

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
        $output .= "<script type=\"text/javascript\">\n        document.addEventListener(\"DOMContentLoaded\", function() {\n            function initializeMap() {\n                if (typeof L === \"undefined\" || typeof L.Control === \"undefined\") {\n                    setTimeout(initializeMap, 100);\n                    return;\n                }\n                try {\n                    var map = L.map(\"" . esc_js($map_id) . "\").setView([" . floatval($atts['center_lat']) . ", " . floatval($atts['center_lng']) . "], " . intval($atts['zoom']) . ");\n                    \n                    // Store map reference globally for search functionality\n                    window.currentMap = map;\n                    \n                    var tileLayer = L.tileLayer(\"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png\", {\n                        attribution: \"&copy; <a href=\\\"https://www.openstreetmap.org/copyright\\\">OpenStreetMap</a> contributors\",\n                        maxZoom: 18\n                    });\n                    tileLayer.addTo(map);\n                    var clinics = " . json_encode($clinic_data) . ";\n                    var bounds = [];\n                    var markers = [];\n                    \n                    console.log('Creating', clinics.length, 'markers');\n                    \n                    clinics.forEach(function(clinic, index) {\n                        if (clinic.lat && clinic.lng) {\n                            var marker = L.marker([clinic.lat, clinic.lng]).addTo(map);\n                            marker.bindPopup((clinic.name ? '<h4>' + clinic.name + '</h4>' : '') +\n                                (clinic.address ? '<p>' + clinic.address + '</p>' : '') +\n                                (clinic.phone ? '<p>Phone: ' + clinic.phone + '</p>' : '') +\n                                (clinic.email ? '<p>Email: ' + clinic.email + '</p>' : '') +\n                                (clinic.website ? '<p><a href=\"' + clinic.website + '\" target=\"_blank\">Website</a></p>' : ''));\n                            marker.clinicName = clinic.name || '';\n                            markers.push(marker);\n                            bounds.push([clinic.lat, clinic.lng]);\n                        }\n                    });\n                    \n                    console.log('Created', markers.length, 'markers with clinic names:', markers.map(function(m) { return m.clinicName; }));\n                    \n                    // Store markers globally for immediate access\n                    window.allMapMarkersForSync = markers;\n                    \n                    // Register markers with search functionality (call immediately and set up for later calls)\n                    if (typeof window.registerMapMarkers === 'function') {\n                        console.log('Registering markers immediately');\n                        window.registerMapMarkers(markers);\n                    } else {\n                        console.log('registerMapMarkers function not available yet, storing markers for later');\n                        // Set up a timer to try registration later\n                        var registrationAttempts = 0;\n                        var registrationTimer = setInterval(function() {\n                            if (typeof window.registerMapMarkers === 'function') {\n                                console.log('Registering markers after delay');\n                                window.registerMapMarkers(markers);\n                                clearInterval(registrationTimer);\n                            } else {\n                                registrationAttempts++;\n                                if (registrationAttempts > 10) {\n                                    console.log('Failed to register markers after 10 attempts');\n                                    clearInterval(registrationTimer);\n                                }\n                            }\n                        }, 500);\n                    }\n                    \n                    if (bounds.length > 0) {\n                        map.fitBounds(bounds, {padding: [20, 20]});\n                    }\n                    if (typeof L.Control.Search !== \"undefined\") {\n                        var markerLayer = L.layerGroup(markers);\n                        var searchControl = new L.Control.Search({\n                            layer: markerLayer,\n                            propertyName: 'clinicName',\n                            marker: false,\n                            moveToLocation: function(latlng, title, map) {\n                                map.setView(latlng, 14);\n                            }\n                        });\n                        searchControl.on('search:locationfound', function(e) {\n                            if (e.layer._popup) e.layer.openPopup();\n                        });\n                        map.addControl(searchControl);\n                    }\n                    setTimeout(function() { if (map) { map.invalidateSize(); } }, 250);\n\n                    // Add global function to center on a clinic by name\n                    window.centerMapOnClinic = function(clinicName) {\n                        var found = false;\n                        markers.forEach(function(marker) {\n                            if (marker.clinicName && marker.clinicName.toLowerCase() === clinicName.toLowerCase()) {\n                                // First scroll to the map\n                                var mapElement = document.getElementById(\"" . esc_js($map_id) . "\");\n                                if (mapElement) {\n                                    mapElement.scrollIntoView({ \n                                        behavior: 'smooth', \n                                        block: 'center' \n                                    });\n                                }\n                                \n                                // Then center the map on the clinic\n                                setTimeout(function() {\n                                    map.setView(marker.getLatLng(), 16, {\n                                        animate: true,\n                                        duration: 1.5\n                                    });\n                                    setTimeout(function() {\n                                        marker.openPopup();\n                                    }, 1600);\n                                }, 500); // Wait for scroll to complete\n                                found = true;\n                                return false;\n                            }\n                        });\n                        if (!found) {\n                            console.log('Available clinics:', markers.map(function(m) { return m.clinicName; }));\n                            alert('Clinic \\\"' + clinicName + '\\\" not found on map.');\n                        }\n                    };\n                } catch (error) {\n                    console.error(\"Map initialization error:\", error);\n                    document.getElementById(\"" . esc_js($map_id) . "\").innerHTML = \"<div style=\\\"padding: 20px; text-align: center; color: #666;\\\">Error loading map. Please refresh the page.</div>\";\n                }\n            }\n            initializeMap();\n        });\n        </script>";

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
     * Get all chiropractor locations with full details for listing
     */
    public function get_all_chiropractor_locations($user_role = 'subscriber')
    {
        $locations = array();

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

            // Modified query to get all users with addresses (not just those with coordinates)
            $args = array(
                'role'    => $user_role,
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => $street_key,
                        'value'   => '',
                        'compare' => '!='
                    ),
                    array(
                        'key'     => $city_key,
                        'value'   => '',
                        'compare' => '!='
                    )
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
                $clinic_name = get_user_meta($user->ID, $name_key, true);
                $phone = get_user_meta($user->ID, $phone_key, true);
                $email = get_user_meta($user->ID, $email_key, true);
                $website = get_user_meta($user->ID, $website_key, true);

                // Skip if no address information at all
                if (empty($street) && empty($city) && empty($province)) {
                    continue;
                }

                // Get additional user details
                $first_name = get_user_meta($user->ID, 'first_name', true);
                $last_name = get_user_meta($user->ID, 'last_name', true);
                $chiropractor_name = trim($first_name . ' ' . $last_name);
                if (empty($chiropractor_name)) {
                    $chiropractor_name = $user->display_name;
                }

                // Build full address
                $address_parts = array_filter(array($street, $city, $province));
                $full_address = implode(', ', $address_parts);

                // Determine if this location has valid coordinates
                $has_coordinates = !empty($lat) && !empty($lng) && floatval($lat) != 0 && floatval($lng) != 0;

                $locations[] = array(
                    'user_id' => $user->ID,
                    'chiropractor_name' => $chiropractor_name,
                    'clinic_name' => $clinic_name ?: 'Clinic',
                    'location_type' => $label,
                    'full_address' => $full_address,
                    'street' => $street,
                    'city' => $city,
                    'province' => $province,
                    'phone' => $phone,
                    'email' => $email,
                    'website' => $website,
                    'latitude' => $has_coordinates ? floatval($lat) : null,
                    'longitude' => $has_coordinates ? floatval($lng) : null,
                    'has_coordinates' => $has_coordinates,
                    'suffix' => $suffix
                );
            }
        }

        // Sort by chiropractor name, then by clinic name
        usort($locations, function($a, $b) {
            $cmp = strcmp($a['chiropractor_name'], $b['chiropractor_name']);
            if ($cmp === 0) {
                return strcmp($a['clinic_name'], $b['clinic_name']);
            }
            return $cmp;
        });

        return $locations;
    }

    /**
     * Display chiropractor locations list shortcode
     */
    public function display_chiropractor_list_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'user_role' => 'subscriber',
            'show_phone' => 'true',
            'show_email' => 'true', 
            'show_website' => 'true',
            'show_coordinates' => 'false',
            'show_search' => 'true',
            'show_sort' => 'true',
            'default_sort' => 'name'
        ), $atts);

        $locations = $this->get_all_chiropractor_locations($atts['user_role']);

        if (empty($locations)) {
            return '<p>No chiropractor locations found.</p>';
        }

        // Generate unique ID for this instance
        $list_id = 'chiropractor-list-' . uniqid();

        $output = '<div class="chiropractor-locations-list" id="' . esc_attr($list_id) . '">';
        
        // Add search functionality if enabled
        if ($atts['show_search'] === 'true') {
            $output .= '<div class="search-container" style="margin-bottom: 20px;">';
            $output .= '<input type="text" id="' . esc_attr($list_id) . '-search" placeholder="Search chiropractors, clinics, cities..." style="width: 100%; max-width: 400px; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px;">';
            $output .= '<p style="margin: 5px 0 0 0; color: #666; font-size: 14px;">Search by name, clinic, city, or address</p>';
            $output .= '</div>';
        }
        
        // Add sort functionality if enabled
        if ($atts['show_sort'] === 'true') {
            $output .= '<div class="sort-container" style="margin-bottom: 20px;">';
            $output .= '<label for="' . esc_attr($list_id) . '-sort" style="margin-right: 10px; font-weight: bold;">Sort by:</label>';
            $output .= '<select id="' . esc_attr($list_id) . '-sort" style="padding: 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;">';
            $output .= '<option value="name"' . ($atts['default_sort'] === 'name' ? ' selected' : '') . '>Chiropractor Name (A-Z)</option>';
            $output .= '<option value="name_desc"' . ($atts['default_sort'] === 'name_desc' ? ' selected' : '') . '>Chiropractor Name (Z-A)</option>';
            $output .= '<option value="clinic"' . ($atts['default_sort'] === 'clinic' ? ' selected' : '') . '>Clinic Name (A-Z)</option>';
            $output .= '<option value="clinic_desc"' . ($atts['default_sort'] === 'clinic_desc' ? ' selected' : '') . '>Clinic Name (Z-A)</option>';
            $output .= '<option value="city"' . ($atts['default_sort'] === 'city' ? ' selected' : '') . '>City (A-Z)</option>';
            $output .= '<option value="city_desc"' . ($atts['default_sort'] === 'city_desc' ? ' selected' : '') . '>City (Z-A)</option>';
            $output .= '<option value="province"' . ($atts['default_sort'] === 'province' ? ' selected' : '') . '>Province (A-Z)</option>';
            $output .= '<option value="province_desc"' . ($atts['default_sort'] === 'province_desc' ? ' selected' : '') . '>Province (Z-A)</option>';
            $output .= '<option value="map_status"' . ($atts['default_sort'] === 'map_status' ? ' selected' : '') . '>Map Status (On Map First)</option>';
            $output .= '<option value="map_status_desc"' . ($atts['default_sort'] === 'map_status_desc' ? ' selected' : '') . '>Map Status (Needs Geocoding First)</option>';
            $output .= '</select>';
            $output .= '</div>';
        }
        
        $output .= '<div class="results-header">';
        $output .= '<h3>Chiropractor Locations (<span id="' . esc_attr($list_id) . '-count">' . count($locations) . '</span> found)</h3>';
        $output .= '</div>';
        
        $output .= '<div class="locations-container">';
        
        foreach ($locations as $index => $location) {
            // Create searchable text for this location
            $searchable_text = strtolower(implode(' ', array(
                $location['chiropractor_name'],
                $location['clinic_name'],
                $location['full_address'],
                $location['street'],
                $location['city'],
                $location['province'],
                $location['phone'],
                $location['email']
            )));
            
            // Add visual styling based on whether location has coordinates
            $border_style = $location['has_coordinates'] ? 'border: 1px solid #ddd;' : 'border: 1px solid #ff9800; background-color: #fff3cd;';
            
            $output .= '<div class="location-item" 
                data-search="' . esc_attr($searchable_text) . '"
                data-name="' . esc_attr(strtolower($location['chiropractor_name'])) . '"
                data-clinic="' . esc_attr(strtolower($location['clinic_name'])) . '"
                data-city="' . esc_attr(strtolower($location['city'])) . '"
                data-province="' . esc_attr(strtolower($location['province'])) . '"
                data-map-status="' . esc_attr($location['has_coordinates'] ? '1' : '0') . '"
                style="margin-bottom: 20px; padding: 15px; ' . $border_style . ' border-radius: 5px;">';
            
            // Clinic name and chiropractor name with status indicator
            $status_indicator = $location['has_coordinates'] ? 
                '<span style="color: #28a745; font-size: 12px; margin-left: 10px;">📍 On Map</span>' : 
                '<span style="color: #dc3545; font-size: 12px; margin-left: 10px;">⚠️ Address Needs Geocoding</span>';
            
            $output .= '<h4 style="margin: 0 0 10px 0; color: #2c5aa0;">' . esc_html($location['clinic_name']) . $status_indicator . '</h4>';
            $output .= '<p style="margin: 0 0 5px 0;"><strong>Chiropractor:</strong> ' . esc_html($location['chiropractor_name']) . '</p>';
            
            // Location type if not primary
            if ($location['location_type'] !== 'Primary') {
                $output .= '<p style="margin: 0 0 5px 0;"><strong>Location Type:</strong> ' . esc_html($location['location_type']) . '</p>';
            }
            
            // Address
            if (!empty($location['full_address'])) {
                $output .= '<p style="margin: 0 0 5px 0;"><strong>Address:</strong> ' . esc_html($location['full_address']) . '</p>';
            }
            
            // Phone
            if ($atts['show_phone'] === 'true' && !empty($location['phone'])) {
                $output .= '<p style="margin: 0 0 5px 0;"><strong>Phone:</strong> <a href="tel:' . esc_attr($location['phone']) . '">' . esc_html($location['phone']) . '</a></p>';
            }
            
            // Email
            if ($atts['show_email'] === 'true' && !empty($location['email'])) {
                $output .= '<p style="margin: 0 0 5px 0;"><strong>Email:</strong> <a href="mailto:' . esc_attr($location['email']) . '">' . esc_html($location['email']) . '</a></p>';
            }
            
            // Website
            if ($atts['show_website'] === 'true' && !empty($location['website'])) {
                $website_url = $location['website'];
                if (!preg_match('/^https?:\/\//', $website_url)) {
                    $website_url = 'http://' . $website_url;
                }
                $output .= '<p style="margin: 0 0 5px 0;"><strong>Website:</strong> <a href="' . esc_url($website_url) . '" target="_blank" rel="noopener">' . esc_html($location['website']) . '</a></p>';
            }
            
            // Coordinates (if requested)
            if ($atts['show_coordinates'] === 'true') {
                if ($location['has_coordinates']) {
                    $output .= '<p style="margin: 0 0 5px 0;"><strong>Coordinates:</strong> ' . esc_html($location['latitude']) . ', ' . esc_html($location['longitude']) . '</p>';
                } else {
                    $output .= '<p style="margin: 0 0 5px 0; color: #dc3545;"><strong>Coordinates:</strong> Not available</p>';
                }
            }
            
            // Show map button or message based on coordinates availability
            if ($location['has_coordinates']) {
                $output .= '<p style="margin: 10px 0 0 0;"><button onclick="if(typeof centerMapOnClinic === \'function\') { centerMapOnClinic(\'' . esc_js($location['clinic_name']) . '\'); } else { alert(\'Map not available on this page.\'); }" style="background: #2c5aa0; color: white; border: none; padding: 8px 15px; border-radius: 3px; cursor: pointer;">Show on Map</button></p>';
            } else {
                $output .= '<p style="margin: 10px 0 0 0; color: #6c757d; font-style: italic;">This location is not available on the map. Address needs to be geocoded.</p>';
            }
            
            $output .= '</div>';
        }
        
        $output .= '</div>'; // Close locations-container
        
        // Add JavaScript for search and sort functionality
        if ($atts['show_search'] === 'true' || $atts['show_sort'] === 'true') {
            $output .= $this->get_search_and_sort_script($list_id, $atts['show_search'] === 'true', $atts['show_sort'] === 'true');
        }
        
        $output .= '</div>'; // Close chiropractor-locations-list
        
        return $output;
    }

    /**
     * Generate search and sort JavaScript
     */
    private function get_search_and_sort_script($list_id, $show_search = true, $show_sort = true)
    {
        return '<script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            var searchInput = ' . ($show_search ? 'document.getElementById("' . esc_js($list_id) . '-search")' : 'null') . ';
            var locationItems = document.querySelectorAll("#' . esc_js($list_id) . ' .location-item");
            var countElement = document.getElementById("' . esc_js($list_id) . '-count");
            
            if (!locationItems.length) return;
            
            // Store reference to map markers for filtering
            var allMapMarkers = [];
            
            // Function to collect map markers (called after map initialization)
            window.registerMapMarkers = function(markers) {
                allMapMarkers = markers;
                console.log("Registered", markers.length, "markers for filtering");
            };
            
            // Fuzzy search function - calculates similarity between two strings
            function fuzzyScore(needle, haystack) {
                needle = needle.toLowerCase();
                haystack = haystack.toLowerCase();
                
                // Exact match gets highest score
                if (haystack.indexOf(needle) !== -1) {
                    return 100;
                }
                
                // Calculate Levenshtein distance-based score
                var needleLen = needle.length;
                var haystackLen = haystack.length;
                
                if (needleLen === 0) return haystackLen;
                if (haystackLen === 0) return needleLen;
                
                // Create distance matrix
                var matrix = [];
                for (var i = 0; i <= haystackLen; i++) {
                    matrix[i] = [i];
                }
                for (var j = 0; j <= needleLen; j++) {
                    matrix[0][j] = j;
                }
                
                // Calculate distances
                for (i = 1; i <= haystackLen; i++) {
                    for (j = 1; j <= needleLen; j++) {
                        if (haystack.charAt(i - 1) === needle.charAt(j - 1)) {
                            matrix[i][j] = matrix[i - 1][j - 1];
                        } else {
                            matrix[i][j] = Math.min(
                                matrix[i - 1][j - 1] + 1, // substitution
                                matrix[i][j - 1] + 1,     // insertion
                                matrix[i - 1][j] + 1      // deletion
                            );
                        }
                    }
                }
                
                var distance = matrix[haystackLen][needleLen];
                var maxLen = Math.max(needleLen, haystackLen);
                
                // Convert distance to similarity score (0-100)
                return Math.max(0, 100 - (distance / maxLen * 100));
            }
            
            // Search function
            function performSearch() {
                if (!searchInput) return;
                
                var query = searchInput.value.trim();
                var visibleCount = 0;
                var visibleClinicNames = [];
                
                console.log("Performing search for:", query);
                
                locationItems.forEach(function(item) {
                    var searchText = item.getAttribute("data-search") || "";
                    var clinicName = item.querySelector("h4") ? item.querySelector("h4").textContent.trim() : "";
                    
                    if (query === "") {
                        // Show all items when search is empty
                        item.style.display = "block";
                        visibleCount++;
                        if (clinicName) visibleClinicNames.push(clinicName.toLowerCase());
                    } else {
                        // Calculate fuzzy match score
                        var score = fuzzyScore(query, searchText);
                        
                        // Show items with score above threshold (60% similarity)
                        if (score >= 60) {
                            item.style.display = "block";
                            visibleCount++;
                            if (clinicName) visibleClinicNames.push(clinicName.toLowerCase());
                        } else {
                            item.style.display = "none";
                        }
                    }
                });
                
                console.log("Visible clinics:", visibleClinicNames);
                
                // Update count
                if (countElement) {
                    countElement.textContent = visibleCount;
                }
                
                // Filter map markers to match visible list items
                filterMapMarkers(visibleClinicNames);
            }
            
            // Function to filter map markers based on visible clinic names
            function filterMapMarkers(visibleClinicNames) {
                if (allMapMarkers.length === 0) {
                    console.log("No map markers available for filtering");
                    return;
                }
                
                console.log("Filtering", allMapMarkers.length, "markers. Visible clinics:", visibleClinicNames);
                
                var visibleMarkers = [];
                
                allMapMarkers.forEach(function(marker) {
                    var markerClinicName = marker.clinicName ? marker.clinicName.toLowerCase() : "";
                    
                    if (visibleClinicNames.length === 0 || visibleClinicNames.includes(markerClinicName)) {
                        // Show marker
                        if (!marker._map && window.currentMap) {
                            marker.addTo(window.currentMap);
                        }
                        visibleMarkers.push(marker);
                        console.log("Showing marker:", markerClinicName);
                    } else {
                        // Hide marker
                        if (marker._map) {
                            marker.remove();
                        }
                        console.log("Hiding marker:", markerClinicName);
                    }
                });
                
                // Adjust map bounds to fit visible markers
                if (window.currentMap && visibleMarkers.length > 0) {
                    try {
                        var group = new L.featureGroup(visibleMarkers);
                        window.currentMap.fitBounds(group.getBounds().pad(0.1));
                        console.log("Adjusted map bounds for", visibleMarkers.length, "visible markers");
                    } catch (error) {
                        console.log("Error adjusting map bounds:", error);
                    }
                } else if (window.currentMap && visibleMarkers.length === 0) {
                    console.log("No visible markers, keeping current map view");
                }
            }
            
            // Sort function
            function sortLocationItems(sortBy) {
                console.log("Sort function called with:", sortBy);
                
                var container = document.querySelector("#' . esc_js($list_id) . ' .locations-container");
                if (!container) {
                    console.log("Container not found");
                    return;
                }
                
                var items = Array.from(container.querySelectorAll(".location-item"));
                console.log("Found", items.length, "items to sort");
                
                items.sort(function(a, b) {
                    var aVal, bVal;
                    
                    switch(sortBy) {
                        case "name":
                            aVal = a.getAttribute("data-name") || "";
                            bVal = b.getAttribute("data-name") || "";
                            return aVal.localeCompare(bVal);
                            
                        case "name_desc":
                            aVal = a.getAttribute("data-name") || "";
                            bVal = b.getAttribute("data-name") || "";
                            return bVal.localeCompare(aVal);
                            
                        case "clinic":
                            aVal = a.getAttribute("data-clinic") || "";
                            bVal = b.getAttribute("data-clinic") || "";
                            return aVal.localeCompare(bVal);
                            
                        case "clinic_desc":
                            aVal = a.getAttribute("data-clinic") || "";
                            bVal = b.getAttribute("data-clinic") || "";
                            return bVal.localeCompare(aVal);
                            
                        case "city":
                            aVal = a.getAttribute("data-city") || "";
                            bVal = b.getAttribute("data-city") || "";
                            return aVal.localeCompare(bVal);
                            
                        case "city_desc":
                            aVal = a.getAttribute("data-city") || "";
                            bVal = b.getAttribute("data-city") || "";
                            return bVal.localeCompare(aVal);
                            
                        case "province":
                            aVal = a.getAttribute("data-province") || "";
                            bVal = b.getAttribute("data-province") || "";
                            return aVal.localeCompare(bVal);
                            
                        case "province_desc":
                            aVal = a.getAttribute("data-province") || "";
                            bVal = b.getAttribute("data-province") || "";
                            return bVal.localeCompare(aVal);
                            
                        case "map_status":
                            aVal = parseInt(a.getAttribute("data-map-status")) || 0;
                            bVal = parseInt(b.getAttribute("data-map-status")) || 0;
                            // Sort by map status (1 = on map first), then by name
                            if (bVal !== aVal) {
                                return bVal - aVal;
                            }
                            return (a.getAttribute("data-name") || "").localeCompare(b.getAttribute("data-name") || "");
                            
                        case "map_status_desc":
                            aVal = parseInt(a.getAttribute("data-map-status")) || 0;
                            bVal = parseInt(b.getAttribute("data-map-status")) || 0;
                            // Sort by map status (0 = needs geocoding first), then by name
                            if (aVal !== bVal) {
                                return aVal - bVal;
                            }
                            return (a.getAttribute("data-name") || "").localeCompare(b.getAttribute("data-name") || "");
                            
                        default:
                            console.log("Unknown sort option:", sortBy);
                            return 0;
                    }
                });
                
                // Re-append sorted items to container
                items.forEach(function(item) {
                    container.appendChild(item);
                });
                
                console.log("Sorted locations by:", sortBy);
            }
            
            // Add search event listeners with debouncing
            var searchTimeout;
            if (searchInput) {
                searchInput.addEventListener("input", function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(performSearch, 150);
                });
                
                // Also search on paste
                searchInput.addEventListener("paste", function() {
                    setTimeout(performSearch, 10);
                });
            }
            
            // Add sort event listener
            var sortSelect = document.getElementById("' . esc_js($list_id) . '-sort");
            console.log("Sort select element:", sortSelect);
            if (sortSelect) {
                console.log("Adding change event listener to sort select");
                sortSelect.addEventListener("change", function() {
                    console.log("Sort dropdown changed to:", this.value);
                    sortLocationItems(this.value);
                });
                
                // Apply initial sort
                var initialSort = sortSelect.value;
                console.log("Applying initial sort:", initialSort);
                sortLocationItems(initialSort);
            } else {
                console.log("Sort select element not found for ID:", "' . esc_js($list_id) . '-sort");
            }
            
            // Initial call to set up the markers if they are already registered
            if (typeof window.registerMapMarkers === "function" && window.allMapMarkersForSync) {
                window.registerMapMarkers(window.allMapMarkersForSync);
            }
        });
        </script>';
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
     * Geocoding tools admin page
     */
    public function geocoding_tools_page()
    {
        // Include the geocoding test partial
        include MAP_INTEGRATION_PLUGIN_PATH . 'admin/partials/geocoding-test.php';
    }
}

// Initialize the plugin
new MapIntegration();
