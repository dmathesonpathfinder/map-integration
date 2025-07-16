<?php
/**
 * Simple security test for Map Integration Plugin
 * 
 * This file contains basic tests to verify that security improvements are working.
 * Run this file in a WordPress environment to test the security functions.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Test the enhanced address validation function
 */
function test_address_validation() {
    echo "<h2>Testing Address Validation</h2>\n";
    
    $test_cases = array(
        // Valid addresses
        '123 Main Street' => true,
        '456 Oak Ave, Apt 2B' => true,
        '789 First St.' => true,
        
        // Invalid addresses (should be rejected)
        '<script>alert("xss")</script>' => false,
        'javascript:alert(1)' => false,
        '../../etc/passwd' => false,
        'eval(malicious_code)' => false,
        '${system("rm -rf /")}' => false,
        'SELECT * FROM users' => false,
        str_repeat('A', 300) => false, // Too long
    );
    
    foreach ($test_cases as $address => $expected) {
        $result = MapIntegration::validate_address_input($address);
        $status = ($result !== false) === $expected ? 'PASS' : 'FAIL';
        
        echo "<p><strong>{$status}</strong>: " . esc_html(substr($address, 0, 50)) . 
             ($result !== false ? " → Valid" : " → Blocked") . "</p>\n";
    }
}

/**
 * Test coordinate validation
 */
function test_coordinate_validation() {
    echo "<h2>Testing Coordinate Validation</h2>\n";
    
    $test_cases = array(
        // Valid coordinates - using numeric keys
        0 => array('coords' => array(44.6488, -63.5752), 'expected' => true),   // Halifax
        1 => array('coords' => array(0.1, 0.1), 'expected' => true),            // Near equator
        2 => array('coords' => array(-90, -180), 'expected' => true),           // Edge case
        3 => array('coords' => array(90, 180), 'expected' => true),             // Edge case
        
        // Invalid coordinates
        4 => array('coords' => array(91, 0), 'expected' => false),              // Lat out of range
        5 => array('coords' => array(0, 181), 'expected' => false),             // Lng out of range
        6 => array('coords' => array(0, 0), 'expected' => false),               // Null island
        7 => array('coords' => array('invalid', 'data'), 'expected' => false),  // Non-numeric
    );
    
    foreach ($test_cases as $test_case) {
        $coords = $test_case['coords'];
        $expected = $test_case['expected'];
        $result = MapIntegration::validate_coordinates($coords[0], $coords[1]);
        $status = ($result !== false) === $expected ? 'PASS' : 'FAIL';
        
        echo "<p><strong>{$status}</strong>: [{$coords[0]}, {$coords[1]}]" . 
             ($result !== false ? " → Valid" : " → Invalid") . "</p>\n";
    }
}

/**
 * Test log message sanitization
 */
function test_log_sanitization() {
    echo "<h2>Testing Log Message Sanitization</h2>\n";
    
    $test_messages = array(
        "Normal log message",
        "Message with\nnewline injection\r\nattempt",
        "Message with [brackets] and control chars\x00\x1F",
        str_repeat("Very long message ", 100), // Test length limit
    );
    
    foreach ($test_messages as $message) {
        // We can't directly call the private method, but we can test the logging function
        ob_start();
        MapGeocoder::log_message($message);
        ob_end_clean();
        
        echo "<p><strong>LOGGED</strong>: " . esc_html(substr($message, 0, 50)) . "...</p>\n";
    }
}

// Run tests if we're in a WordPress admin environment
if (is_admin() && current_user_can('manage_options')) {
    echo "<div style='margin: 20px; font-family: monospace;'>\n";
    echo "<h1>Map Integration Security Tests</h1>\n";
    
    test_address_validation();
    test_coordinate_validation();
    test_log_sanitization();
    
    echo "<h2>Security Test Complete</h2>\n";
    echo "<p>Check the results above to ensure all security validations are working correctly.</p>\n";
    echo "</div>\n";
}
?>