<?php

/**
 * Geocoding Test Admin Partial
 * 
 * Provides an interface for testing street parsing and geocoding,
 * viewing results, and clearing cache.
 * 
 * @package MapIntegration
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Handle form submissions
$test_result = null;
$parse_result = null;
$error_message = '';
$success_message = '';

if (isset($_POST['test_geocoding']) && wp_verify_nonce($_POST['_wpnonce'], 'test_geocoding')) {
    $test_address = sanitize_text_field($_POST['test_address']);
    
    if (!empty($test_address)) {
        $test_result = geocode_address($test_address);
        if (!$test_result) {
            $error_message = 'Geocoding failed for the provided address.';
        }
    } else {
        $error_message = 'Please enter an address to test.';
    }
}

if (isset($_POST['test_parsing']) && wp_verify_nonce($_POST['_wpnonce'], 'test_parsing')) {
    $parse_address = sanitize_text_field($_POST['parse_address']);
    
    if (!empty($parse_address)) {
        $parse_result = parse_street_address($parse_address);
    } else {
        $error_message = 'Please enter an address to parse.';
    }
}

if (isset($_POST['clear_cache']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_cache')) {
    $cleared_count = clear_geocoding_cache();
    $success_message = "Cleared {$cleared_count} geocoding cache entries.";
}

if (isset($_POST['clear_old_cache']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_old_cache')) {
    $days = intval($_POST['cache_days']);
    if ($days > 0) {
        $cleared_count = clear_geocoding_cache(array('older_than' => $days * DAY_IN_SECONDS));
        $success_message = "Cleared {$cleared_count} geocoding cache entries older than {$days} days.";
    } else {
        $error_message = 'Please enter a valid number of days.';
    }
}

// Get cache statistics
$cache_stats = get_geocoding_cache_stats();

?>

<div class="wrap">
    <h1>Geocoding Test & Management</h1>
    
    <?php if (!empty($error_message)): ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($error_message); ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success_message)): ?>
        <div class="notice notice-success">
            <p><?php echo esc_html($success_message); ?></p>
        </div>
    <?php endif; ?>

    <div class="postbox-container" style="width: 100%;">
        
        <!-- Street Address Parsing Test -->
        <div class="postbox">
            <h2 class="hndle"><span>Street Address Parsing Test</span></h2>
            <div class="inside">
                <form method="post">
                    <?php wp_nonce_field('test_parsing'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Address to Parse</th>
                            <td>
                                <input type="text" name="parse_address" class="regular-text" 
                                       value="<?php echo isset($_POST['parse_address']) ? esc_attr($_POST['parse_address']) : ''; ?>" 
                                       placeholder="e.g., 123 Main St, Suite 4B" />
                                <p class="description">Enter a street address to parse into components.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="test_parsing" class="button-primary" value="Parse Address" />
                    </p>
                </form>
                
                <?php if ($parse_result): ?>
                    <h3>Parsing Results</h3>
                    <table class="widefat">
                        <tbody>
                            <tr>
                                <td><strong>Original Address</strong></td>
                                <td><?php echo esc_html($parse_result['original']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Normalized Address</strong></td>
                                <td><?php echo esc_html($parse_result['normalized']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Parsed Address</strong></td>
                                <td><?php echo esc_html($parse_result['parsed_address']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>House Number</strong></td>
                                <td><?php echo esc_html($parse_result['house_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Pre-Directional</strong></td>
                                <td><?php echo esc_html($parse_result['pre_directional']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Street Name</strong></td>
                                <td><?php echo esc_html($parse_result['street_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Street Type</strong></td>
                                <td><?php echo esc_html($parse_result['street_type']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Post-Directional</strong></td>
                                <td><?php echo esc_html($parse_result['post_directional']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Unit Designator</strong></td>
                                <td><?php echo esc_html($parse_result['unit_designator']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Unit Number</strong></td>
                                <td><?php echo esc_html($parse_result['unit_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Confidence Score</strong></td>
                                <td><?php echo esc_html($parse_result['confidence_score']); ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Geocoding Test -->
        <div class="postbox">
            <h2 class="hndle"><span>Geocoding Test</span></h2>
            <div class="inside">
                <form method="post">
                    <?php wp_nonce_field('test_geocoding'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Address to Geocode</th>
                            <td>
                                <input type="text" name="test_address" class="regular-text" 
                                       value="<?php echo isset($_POST['test_address']) ? esc_attr($_POST['test_address']) : ''; ?>" 
                                       placeholder="e.g., 1234 Main Street, Halifax, NS" />
                                <p class="description">Enter a complete address to geocode.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="test_geocoding" class="button-primary" value="Test Geocoding" />
                    </p>
                </form>
                
                <?php if ($test_result): ?>
                    <h3>Geocoding Results</h3>
                    <table class="widefat">
                        <tbody>
                            <tr>
                                <td><strong>Latitude</strong></td>
                                <td><?php echo esc_html($test_result['latitude']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Longitude</strong></td>
                                <td><?php echo esc_html($test_result['longitude']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Confidence Score</strong></td>
                                <td><?php echo esc_html($test_result['confidence_score']); ?>%</td>
                            </tr>
                            <tr>
                                <td><strong>Provider</strong></td>
                                <td><?php echo esc_html(ucfirst($test_result['provider'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Display Name</strong></td>
                                <td><?php echo esc_html($test_result['display_name']); ?></td>
                            </tr>
                            <?php if (isset($test_result['full_result']['place_type'])): ?>
                            <tr>
                                <td><strong>Place Type</strong></td>
                                <td><?php echo esc_html($test_result['full_result']['place_type']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <!-- Map Preview -->
                    <h3>Location Preview</h3>
                    <div id="test-map" style="width: 100%; height: 300px; border: 1px solid #ccc;"></div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        if (typeof L !== 'undefined') {
                            var map = L.map('test-map').setView([<?php echo floatval($test_result['latitude']); ?>, <?php echo floatval($test_result['longitude']); ?>], 15);
                            
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '© OpenStreetMap contributors'
                            }).addTo(map);
                            
                            L.marker([<?php echo floatval($test_result['latitude']); ?>, <?php echo floatval($test_result['longitude']); ?>])
                                .addTo(map)
                                .bindPopup(<?php echo wp_json_encode(esc_html($test_result['display_name'])); ?>)
                                .openPopup();
                        } else {
                            $('#test-map').html('<p>Map preview requires Leaflet.js to be loaded.</p>');
                        }
                    });
                    </script>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cache Management -->
        <div class="postbox">
            <h2 class="hndle"><span>Cache Management</span></h2>
            <div class="inside">
                <h3>Cache Statistics</h3>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong>Total Entries</strong></td>
                            <td><?php echo esc_html(number_format($cache_stats['total_entries'])); ?></td>
                        </tr>
                        <?php if (!empty($cache_stats['providers'])): ?>
                            <?php foreach ($cache_stats['providers'] as $provider => $count): ?>
                            <tr>
                                <td><strong><?php echo esc_html(ucfirst($provider)); ?> Entries</strong></td>
                                <td><?php echo esc_html(number_format($count)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Oldest Entry</strong></td>
                            <td><?php echo $cache_stats['oldest_entry'] ? esc_html($cache_stats['oldest_entry']) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Newest Entry</strong></td>
                            <td><?php echo $cache_stats['newest_entry'] ? esc_html($cache_stats['newest_entry']) : 'N/A'; ?></td>
                        </tr>
                    </tbody>
                </table>

                <h3>Clear Cache</h3>
                <div style="margin-bottom: 20px;">
                    <form method="post" style="display: inline-block; margin-right: 20px;">
                        <?php wp_nonce_field('clear_cache'); ?>
                        <input type="submit" name="clear_cache" class="button button-secondary" 
                               value="Clear All Cache" 
                               onclick="return confirm('Are you sure you want to clear all geocoding cache entries?');" />
                    </form>
                    
                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('clear_old_cache'); ?>
                        <input type="number" name="cache_days" value="30" min="1" max="365" style="width: 60px;" />
                        <input type="submit" name="clear_old_cache" class="button button-secondary" 
                               value="Clear Cache Older Than X Days" />
                    </form>
                </div>
                
                <p class="description">
                    Clearing the cache will force all addresses to be re-geocoded the next time they are requested. 
                    Use this if you suspect there are outdated or incorrect geocoding results.
                </p>
            </div>
        </div>

        <!-- System Information -->
        <div class="postbox">
            <h2 class="hndle"><span>System Information</span></h2>
            <div class="inside">
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong>Street Parser Class</strong></td>
                            <td><?php echo class_exists('Map_Integration_Street_Parser') ? '✓ Loaded' : '✗ Not Found'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Geocoding Service Class</strong></td>
                            <td><?php echo class_exists('Map_Integration_Geocoding_Service') ? '✓ Loaded' : '✗ Not Found'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Utility Functions</strong></td>
                            <td><?php echo function_exists('geocode_address') ? '✓ Loaded' : '✗ Not Found'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Nominatim Provider</strong></td>
                            <td>✓ Available</td>
                        </tr>
                        <tr>
                            <td><strong>Google Maps Provider</strong></td>
                            <td>
                                <?php 
                                $google_key = get_option('map_integration_google_api_key', '');
                                echo !empty($google_key) ? '✓ Available (API Key Set)' : '✗ Not Available (No API Key)';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Cache Table</strong></td>
                            <td>
                                <?php 
                                global $wpdb;
                                $cache_table = $wpdb->prefix . 'geocoded_addresses';
                                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$cache_table}'") == $cache_table;
                                echo $table_exists ? '✓ Created' : '✗ Not Found';
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php if (empty(get_option('map_integration_google_api_key', ''))): ?>
                <div class="notice notice-info inline">
                    <p><strong>Note:</strong> To enable Google Maps geocoding, set your API key in the main plugin settings.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<style>
.postbox {
    margin-bottom: 20px;
}

.postbox .inside {
    margin: 0;
    padding: 20px;
}

.widefat td {
    padding: 10px;
}

.widefat td:first-child {
    width: 200px;
    font-weight: 600;
}

#test-map {
    margin-top: 10px;
}

.notice.inline {
    margin: 15px 0;
    padding: 10px;
}
</style>