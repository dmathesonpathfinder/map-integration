<?php

/**
 * Street Address Parser Class
 * 
 * Handles parsing and normalization of inconsistent street address formats,
 * extracts components, and handles edge cases such as abbreviations, 
 * directionals, units, and missing elements.
 * 
 * @package MapIntegration
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Map_Integration_Street_Parser
{
    /**
     * Common street type abbreviations and their full forms
     * 
     * @var array
     */
    private static $street_types = array(
        'st' => 'street',
        'st.' => 'street',
        'ave' => 'avenue',
        'ave.' => 'avenue',
        'av' => 'avenue',
        'rd' => 'road',
        'rd.' => 'road',
        'dr' => 'drive',
        'dr.' => 'drive',
        'blvd' => 'boulevard',
        'blvd.' => 'boulevard',
        'ln' => 'lane',
        'ln.' => 'lane',
        'ct' => 'court',
        'ct.' => 'court',
        'pl' => 'place',
        'pl.' => 'place',
        'way' => 'way',
        'pkwy' => 'parkway',
        'pkwy.' => 'parkway',
        'hwy' => 'highway',
        'hwy.' => 'highway',
        'cir' => 'circle',
        'cir.' => 'circle',
        'ter' => 'terrace',
        'ter.' => 'terrace',
        'trl' => 'trail',
        'trl.' => 'trail',
        'cres' => 'crescent',
        'cr' => 'crescent'
    );

    /**
     * Directional abbreviations
     * 
     * @var array
     */
    private static $directionals = array(
        'n' => 'north',
        'n.' => 'north',
        's' => 'south',
        's.' => 'south',
        'e' => 'east',
        'e.' => 'east',
        'w' => 'west',
        'w.' => 'west',
        'ne' => 'northeast',
        'n.e.' => 'northeast',
        'nw' => 'northwest',
        'n.w.' => 'northwest',
        'se' => 'southeast',
        's.e.' => 'southeast',
        'sw' => 'southwest',
        's.w.' => 'southwest'
    );

    /**
     * Unit designators
     * 
     * @var array
     */
    private static $unit_designators = array(
        'apt', 'apartment', 'unit', 'suite', 'ste', 'ste.', 'floor', 'fl', 'fl.',
        'room', 'rm', 'rm.', 'office', 'ofc', 'building', 'bldg', 'bldg.'
    );

    /**
     * Parse a complete street address into components
     * 
     * @param string $address The full address to parse
     * @return array Parsed address components
     */
    public static function parse_address($address)
    {
        if (empty($address)) {
            return self::get_empty_address_structure();
        }

        // Initialize result structure
        $result = self::get_empty_address_structure();
        $result['original'] = trim($address);

        // Clean and normalize the address
        $normalized = self::normalize_address($address);
        $result['normalized'] = $normalized;

        // Split address into components
        $components = self::split_address_components($normalized);
        
        // Extract house number
        $result['house_number'] = self::extract_house_number($components);
        
        // Extract street name and type
        $street_info = self::extract_street_info($components);
        $result['street_name'] = $street_info['name'];
        $result['street_type'] = $street_info['type'];
        
        // Extract directionals
        $directional_info = self::extract_directionals($components);
        $result['pre_directional'] = $directional_info['pre'];
        $result['post_directional'] = $directional_info['post'];
        
        // Extract unit information
        $unit_info = self::extract_unit_info($components);
        $result['unit_designator'] = $unit_info['designator'];
        $result['unit_number'] = $unit_info['number'];
        
        // Generate parsed full address
        $result['parsed_address'] = self::build_parsed_address($result);
        
        // Calculate confidence score
        $result['confidence_score'] = self::calculate_confidence_score($result);

        return $result;
    }

    /**
     * Normalize address string
     * 
     * @param string $address Raw address string
     * @return string Normalized address
     */
    private static function normalize_address($address)
    {
        // Remove extra whitespace
        $address = preg_replace('/\s+/', ' ', trim($address));
        
        // Convert to lowercase for processing
        $address = strtolower($address);
        
        // Remove common punctuation that can interfere with parsing
        $address = str_replace(array(',', ';'), ' ', $address);
        
        // Normalize hyphens and dashes
        $address = preg_replace('/[\-\–\—]/', '-', $address);
        
        return $address;
    }

    /**
     * Split address into word components
     * 
     * @param string $address Normalized address
     * @return array Array of address components
     */
    private static function split_address_components($address)
    {
        // Split by spaces while preserving hyphenated words
        $parts = preg_split('/\s+/', $address);
        
        // Filter out empty parts
        return array_filter($parts, function($part) {
            return !empty(trim($part));
        });
    }

    /**
     * Extract house number from address components
     * 
     * @param array $components Address components
     * @return string House number or empty string
     */
    private static function extract_house_number($components)
    {
        if (empty($components)) {
            return '';
        }

        $first_component = $components[0];
        
        // Check if first component is a number or starts with a number
        if (preg_match('/^(\d+[a-z]?)/i', $first_component, $matches)) {
            return $matches[1];
        }
        
        // Check for range format (e.g., "123-125")
        if (preg_match('/^(\d+-\d+)/i', $first_component, $matches)) {
            return $matches[1];
        }
        
        return '';
    }

    /**
     * Extract street name and type information
     * 
     * @param array $components Address components
     * @return array Street name and type
     */
    private static function extract_street_info($components)
    {
        $result = array('name' => '', 'type' => '');
        
        if (empty($components)) {
            return $result;
        }
        
        // Remove house number from consideration
        $street_components = $components;
        if (self::extract_house_number($components)) {
            array_shift($street_components);
        }
        
        if (empty($street_components)) {
            return $result;
        }
        
        // Look for street type at the end
        $last_component = end($street_components);
        $street_type = self::normalize_street_type($last_component);
        
        if ($street_type) {
            $result['type'] = $street_type;
            // Remove street type from name components
            array_pop($street_components);
        }
        
        // Remove unit information from street name
        $street_components = self::remove_unit_components($street_components);
        
        // Remove directionals to isolate street name
        $street_components = self::remove_directional_components($street_components);
        
        // Join remaining components as street name
        $result['name'] = implode(' ', $street_components);
        
        return $result;
    }

    /**
     * Extract directional information
     * 
     * @param array $components Address components
     * @return array Pre and post directionals
     */
    private static function extract_directionals($components)
    {
        $result = array('pre' => '', 'post' => '');
        
        if (empty($components)) {
            return $result;
        }
        
        // Check for pre-directional (after house number)
        if (count($components) > 1) {
            $second_component = $components[1];
            $directional = self::normalize_directional($second_component);
            if ($directional) {
                $result['pre'] = $directional;
            }
        }
        
        // Check for post-directional (before street type or at end)
        $last_few = array_slice($components, -3);
        foreach ($last_few as $component) {
            $directional = self::normalize_directional($component);
            if ($directional && !self::normalize_street_type($component)) {
                $result['post'] = $directional;
                break;
            }
        }
        
        return $result;
    }

    /**
     * Extract unit information
     * 
     * @param array $components Address components
     * @return array Unit designator and number
     */
    private static function extract_unit_info($components)
    {
        $result = array('designator' => '', 'number' => '');
        
        if (empty($components)) {
            return $result;
        }
        
        // Look for unit designators
        for ($i = 0; $i < count($components); $i++) {
            $component = $components[$i];
            
            if (in_array($component, self::$unit_designators)) {
                $result['designator'] = $component;
                
                // Check next component for unit number
                if (isset($components[$i + 1])) {
                    $next_component = $components[$i + 1];
                    if (preg_match('/^[a-z0-9]+$/i', $next_component)) {
                        $result['number'] = $next_component;
                    }
                }
                break;
            }
            
            // Check for unit number pattern (e.g., "apt 123", "unit a")
            if (preg_match('/^(apt|unit|suite|ste)\.?\s*([a-z0-9]+)$/i', $component, $matches)) {
                $result['designator'] = strtolower($matches[1]);
                $result['number'] = $matches[2];
                break;
            }
        }
        
        return $result;
    }

    /**
     * Normalize street type
     * 
     * @param string $type Street type to normalize
     * @return string|false Normalized street type or false if not found
     */
    private static function normalize_street_type($type)
    {
        $type = strtolower(trim($type));
        
        if (isset(self::$street_types[$type])) {
            return self::$street_types[$type];
        }
        
        // Check if it's already a full street type
        if (in_array($type, self::$street_types)) {
            return $type;
        }
        
        return false;
    }

    /**
     * Normalize directional
     * 
     * @param string $directional Directional to normalize
     * @return string|false Normalized directional or false if not found
     */
    private static function normalize_directional($directional)
    {
        $directional = strtolower(trim($directional));
        
        if (isset(self::$directionals[$directional])) {
            return self::$directionals[$directional];
        }
        
        // Check if it's already a full directional
        if (in_array($directional, self::$directionals)) {
            return $directional;
        }
        
        return false;
    }

    /**
     * Remove unit components from array
     * 
     * @param array $components Address components
     * @return array Components with unit info removed
     */
    private static function remove_unit_components($components)
    {
        $filtered = array();
        
        for ($i = 0; $i < count($components); $i++) {
            $component = $components[$i];
            
            // Skip unit designators and following numbers
            if (in_array($component, self::$unit_designators)) {
                if (isset($components[$i + 1])) {
                    $i++; // Skip next component too (unit number)
                }
                continue;
            }
            
            // Skip unit patterns
            if (preg_match('/^(apt|unit|suite|ste)\.?\s*[a-z0-9]+$/i', $component)) {
                continue;
            }
            
            $filtered[] = $component;
        }
        
        return $filtered;
    }

    /**
     * Remove directional components from array
     * 
     * @param array $components Address components
     * @return array Components with directionals removed
     */
    private static function remove_directional_components($components)
    {
        return array_filter($components, function($component) {
            return !self::normalize_directional($component);
        });
    }

    /**
     * Build parsed address from components
     * 
     * @param array $components Parsed address components
     * @return string Formatted parsed address
     */
    private static function build_parsed_address($components)
    {
        $parts = array();
        
        if (!empty($components['house_number'])) {
            $parts[] = $components['house_number'];
        }
        
        if (!empty($components['pre_directional'])) {
            $parts[] = ucfirst($components['pre_directional']);
        }
        
        if (!empty($components['street_name'])) {
            $parts[] = ucwords($components['street_name']);
        }
        
        if (!empty($components['street_type'])) {
            $parts[] = ucfirst($components['street_type']);
        }
        
        if (!empty($components['post_directional'])) {
            $parts[] = ucfirst($components['post_directional']);
        }
        
        $main_address = implode(' ', $parts);
        
        // Add unit information if present
        if (!empty($components['unit_designator']) && !empty($components['unit_number'])) {
            $main_address .= ', ' . ucfirst($components['unit_designator']) . ' ' . $components['unit_number'];
        }
        
        return $main_address;
    }

    /**
     * Calculate confidence score for parsed address
     * 
     * @param array $components Parsed address components
     * @return int Confidence score (0-100)
     */
    private static function calculate_confidence_score($components)
    {
        $score = 0;
        
        // House number present
        if (!empty($components['house_number'])) {
            $score += 30;
        }
        
        // Street name present
        if (!empty($components['street_name'])) {
            $score += 40;
        }
        
        // Street type present
        if (!empty($components['street_type'])) {
            $score += 20;
        }
        
        // Additional elements boost confidence
        if (!empty($components['pre_directional']) || !empty($components['post_directional'])) {
            $score += 5;
        }
        
        if (!empty($components['unit_designator']) && !empty($components['unit_number'])) {
            $score += 5;
        }
        
        return min(100, $score);
    }

    /**
     * Get empty address structure
     * 
     * @return array Empty address structure
     */
    private static function get_empty_address_structure()
    {
        return array(
            'original' => '',
            'normalized' => '',
            'parsed_address' => '',
            'house_number' => '',
            'pre_directional' => '',
            'street_name' => '',
            'street_type' => '',
            'post_directional' => '',
            'unit_designator' => '',
            'unit_number' => '',
            'confidence_score' => 0
        );
    }

    /**
     * Validate and clean parsed address components
     * 
     * @param array $components Address components to validate
     * @return array Validated components
     */
    public static function validate_components($components)
    {
        $validated = self::get_empty_address_structure();
        
        foreach ($validated as $key => $default) {
            if (isset($components[$key])) {
                $validated[$key] = self::sanitize_component($components[$key]);
            }
        }
        
        return $validated;
    }

    /**
     * Sanitize individual address component
     * 
     * @param string $component Component to sanitize
     * @return string Sanitized component
     */
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
}