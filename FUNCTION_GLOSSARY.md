# Function Glossary - Map Integration Plugin

## Overview
This glossary identifies and categorizes all functions used in the Map Integration plugin, indicating whether they are built-in PHP functions, WordPress functions, or custom functions created for this plugin.

## Built-in PHP Functions

### String Manipulation
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `trim()` | Remove whitespace | Throughout | LOW |
| `strtolower()` | Convert to lowercase | Address parsing | LOW |
| `substr()` | String extraction | Various | LOW |
| `str_replace()` | String replacement | Address normalization | LOW |
| `implode()` | Array to string | Address building | LOW |
| `explode()` | String to array | Address parsing | LOW |
| `ucfirst()` | Capitalize first letter | Address formatting | LOW |
| `ucwords()` | Capitalize words | Address formatting | LOW |
| `strlen()` | String length | Validation | LOW |

### Pattern Matching & Regex
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `preg_replace()` | Pattern replacement | Address parsing | MEDIUM |
| `preg_match()` | Pattern matching | Address validation | MEDIUM |
| `preg_split()` | Pattern splitting | Address components | MEDIUM |

### Array Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `array_filter()` | Filter arrays | Data cleanup | LOW |
| `array_unique()` | Remove duplicates | Address variations | LOW |
| `array_shift()` | Remove first element | Address parsing | LOW |
| `array_pop()` | Remove last element | Address parsing | LOW |
| `array_slice()` | Extract portion | Component extraction | LOW |
| `count()` | Count elements | Validation | LOW |
| `in_array()` | Check membership | Validation | LOW |
| `array_merge()` | Combine arrays | Data processing | LOW |
| `end()` | Get last element | Address parsing | LOW |

### Type Conversion & Validation
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `floatval()` | Convert to float | Coordinate handling | LOW |
| `intval()` | Convert to integer | ID handling | LOW |
| `is_array()` | Type checking | Data validation | LOW |
| `empty()` | Check if empty | Validation | LOW |
| `isset()` | Check if set | Validation | LOW |

### JSON & Data Handling
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `json_decode()` | Parse JSON | API responses | LOW |
| `json_encode()` | Create JSON | Frontend data | LOW |
| `json_last_error()` | Check JSON errors | Error handling | LOW |
| `json_last_error_msg()` | Get JSON error message | Error handling | LOW |

### HTTP & URL Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `http_build_query()` | Build query strings | API requests | LOW |

### File & I/O Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `file_put_contents()` | Write to file | Logging | **HIGH** |

### Time Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `time()` | Current timestamp | Rate limiting | LOW |
| `microtime()` | Precise time | Rate limiting | LOW |
| `usleep()` | Microsecond delay | Rate limiting | LOW |
| `sleep()` | Second delay | Batch processing | LOW |
| `strtotime()` | Parse time string | Cache validation | LOW |
| `date()` | Format date | Logging | LOW |

### Math Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `sin()` | Trigonometry | Distance calculation | LOW |
| `cos()` | Trigonometry | Distance calculation | LOW |
| `sqrt()` | Square root | Distance calculation | LOW |
| `atan2()` | Arc tangent | Distance calculation | LOW |
| `deg2rad()` | Degrees to radians | Distance calculation | LOW |
| `max()` | Maximum value | Score calculation | LOW |
| `min()` | Minimum value | Score calculation | LOW |

## WordPress Built-in Functions

### Database Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `get_user_meta()` | Retrieve user data | Throughout | LOW |
| `update_user_meta()` | Store user data | Throughout | LOW |
| `delete_user_meta()` | Remove user data | Cleanup | LOW |
| `get_option()` | Get site options | Configuration | LOW |
| `update_option()` | Set site options | Configuration | LOW |
| `get_users()` | Query users | Bulk operations | LOW |

### Database Query Functions (via $wpdb)
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `$wpdb->prepare()` | Prepared statements | Database queries | LOW |
| `$wpdb->query()` | Execute query | Database operations | LOW |
| `$wpdb->get_results()` | Get query results | Data retrieval | LOW |
| `$wpdb->get_var()` | Get single value | Statistics | LOW |
| `$wpdb->get_row()` | Get single row | Cache lookup | LOW |
| `$wpdb->insert()` | Insert data | Cache storage | LOW |
| `$wpdb->delete()` | Delete data | Cache cleanup | LOW |

### HTTP Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `wp_remote_get()` | HTTP requests | API calls | LOW |
| `wp_remote_retrieve_response_code()` | Get HTTP status | Response handling | LOW |
| `wp_remote_retrieve_body()` | Get response body | API processing | LOW |
| `wp_remote_retrieve_response_message()` | Get status message | Error handling | LOW |
| `is_wp_error()` | Check for errors | Error handling | LOW |

### Security Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `wp_verify_nonce()` | Verify security token | Form validation | LOW |
| `wp_nonce_field()` | Create nonce field | Forms | LOW |
| `sanitize_text_field()` | Sanitize input | Input validation | LOW |
| `esc_html()` | Escape HTML | Output protection | LOW |
| `esc_attr()` | Escape attributes | Output protection | LOW |
| `esc_url()` | Escape URLs | URL output | LOW |
| `esc_js()` | Escape JavaScript | Script output | LOW |
| `current_user_can()` | Check permissions | Access control | LOW |

### Content Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `shortcode_atts()` | Parse shortcode attributes | Shortcode handling | LOW |
| `wp_parse_args()` | Merge arguments | Option handling | LOW |
| `wp_json_encode()` | Safe JSON encode | Data output | LOW |

### Asset Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `wp_enqueue_script()` | Load JavaScript | Asset management | LOW |
| `wp_enqueue_style()` | Load CSS | Asset management | LOW |
| `plugin_dir_url()` | Get plugin URL | Asset paths | LOW |
| `plugin_dir_path()` | Get plugin path | File paths | LOW |

### Hook Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `add_action()` | Register actions | WordPress integration | LOW |
| `add_filter()` | Register filters | WordPress integration | LOW |
| `add_shortcode()` | Register shortcodes | Content integration | LOW |

### Time Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `current_time()` | WordPress time | Timestamps | LOW |

### Upload Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `wp_upload_dir()` | Get upload directory | Log file storage | MEDIUM |

### Utility Functions
| Function | Usage | Location | Risk Level |
|----------|-------|----------|------------|
| `defined()` | Check if constant defined | Security checks | LOW |
| `number_format()` | Format numbers | Display | LOW |
| `error_log()` | Log errors | Error handling | LOW |

## Custom Functions Created for This Plugin

### Core Geocoding Functions
| Function | Purpose | Criticality | Risk Level |
|----------|---------|-------------|------------|
| `geocode_address()` | Main geocoding interface | ⭐⭐⭐⭐⭐ | MEDIUM |
| `parse_street_address()` | Parse address components | ⭐⭐⭐ | LOW |
| `format_address_for_geocoding()` | Format addresses | ⭐⭐⭐ | LOW |
| `validate_coordinates()` | Validate lat/lng | ⭐⭐ | LOW |
| `calculate_distance()` | Distance between points | ⭐⭐ | LOW |

### Cache Management Functions
| Function | Purpose | Criticality | Risk Level |
|----------|---------|-------------|------------|
| `clear_geocoding_cache()` | Clear cache entries | ⭐⭐ | LOW |
| `get_geocoding_cache_stats()` | Get cache statistics | ⭐ | LOW |

### User Data Functions
| Function | Purpose | Criticality | Risk Level |
|----------|---------|-------------|------------|
| `get_user_coordinates()` | Get user coordinates | ⭐⭐⭐ | LOW |
| `save_user_coordinates()` | Save user coordinates | ⭐⭐⭐ | LOW |
| `get_user_address()` | Get formatted address | ⭐⭐⭐ | LOW |
| `address_needs_geocoding()` | Check if geocoding needed | ⭐⭐ | LOW |

### Bulk Processing Functions
| Function | Purpose | Criticality | Risk Level |
|----------|---------|-------------|------------|
| `bulk_geocode_addresses()` | Process multiple addresses | ⭐⭐ | MEDIUM |
| `get_users_needing_geocoding()` | Find users to process | ⭐⭐ | LOW |

### Display Functions
| Function | Purpose | Criticality | Risk Level |
|----------|---------|-------------|------------|
| `display_map_shortcode()` | Render map shortcode | ⭐⭐⭐⭐ | MEDIUM |
| `display_chiropractor_directory()` | Render directory | ⭐⭐⭐⭐ | MEDIUM |
| `display_clinic_map()` | Render clinic map | ⭐⭐⭐ | MEDIUM |
| `display_clinic_listings()` | Render listings | ⭐⭐⭐ | LOW |

### MapGeocoder Class Methods (Legacy)
| Function | Purpose | Criticality | Risk Level |
|----------|---------|-------------|------------|
| `MapGeocoder::log_message()` | Logging | ⭐⭐ | HIGH |
| `MapGeocoder::geocode_user_address()` | Geocode user address | ⭐⭐⭐⭐ | MEDIUM |
| `MapGeocoder::normalize_address_part()` | Normalize address | ⭐⭐ | LOW |
| `MapGeocoder::is_province_level_coordinate()` | Check coordinate quality | ⭐⭐ | LOW |

### Map_Integration_Geocoding_Service Class Methods
| Function | Purpose | Criticality | Risk Level |
|----------|---------|-------------|------------|
| `geocode_address()` | Main geocoding method | ⭐⭐⭐⭐⭐ | MEDIUM |
| `geocode_with_provider()` | Provider-specific geocoding | ⭐⭐⭐⭐ | MEDIUM |
| `geocode_with_nominatim()` | Nominatim geocoding | ⭐⭐⭐⭐ | MEDIUM |
| `geocode_with_google()` | Google geocoding | ⭐⭐⭐⭐ | MEDIUM |
| `calculate_nominatim_confidence()` | Score Nominatim results | ⭐⭐ | LOW |
| `calculate_google_confidence()` | Score Google results | ⭐⭐ | LOW |
| `clear_cache()` | Cache management | ⭐⭐ | LOW |
| `get_cache_stats()` | Cache statistics | ⭐ | LOW |

### Map_Integration_Street_Parser Class Methods
| Function | Purpose | Criticality | Risk Level |
|----------|---------|-------------|------------|
| `parse_address()` | Main parsing method | ⭐⭐⭐ | LOW |
| `normalize_address()` | Address normalization | ⭐⭐ | LOW |
| `extract_house_number()` | Extract house number | ⭐⭐ | LOW |
| `extract_street_info()` | Extract street details | ⭐⭐ | LOW |
| `extract_directionals()` | Extract directions | ⭐⭐ | LOW |
| `extract_unit_info()` | Extract unit info | ⭐⭐ | LOW |
| `validate_components()` | Validate components | ⭐⭐ | LOW |

### Admin Interface Functions
| Function | Purpose | Criticality | Risk Level |
|----------|---------|-------------|------------|
| `display_admin_page()` | Admin interface | ⭐⭐ | LOW |
| `handle_admin_actions()` | Process admin forms | ⭐⭐ | MEDIUM |
| `bulk_geocode_users()` | Batch processing | ⭐⭐⭐ | MEDIUM |
| `get_geocoding_stats()` | Get statistics | ⭐ | LOW |

## Risk Assessment Summary

### HIGH RISK Functions
- `file_put_contents()` - Direct file write operations
- `MapGeocoder::log_message()` - Uses file_put_contents()

### MEDIUM RISK Functions
- Functions using `preg_replace()` without proper validation
- Bulk processing functions that could be abused
- Display functions that output user data
- Functions handling API keys and external requests

### LOW RISK Functions
- Most WordPress built-in functions (when used properly)
- Basic utility functions
- Data validation functions
- Math and string manipulation functions

## Recommendations

1. **Replace HIGH RISK functions** with WordPress alternatives
2. **Add input validation** to MEDIUM RISK functions
3. **Use WordPress sanitization** consistently for all output
4. **Implement proper error handling** for all external API calls
5. **Add rate limiting** to bulk processing functions
6. **Use prepared statements** for all database operations (already mostly implemented)

## Function Statistics

- **Total Built-in PHP Functions**: ~45
- **Total WordPress Functions**: ~35
- **Total Custom Functions**: ~40
- **HIGH RISK**: 2 functions
- **MEDIUM RISK**: ~15 functions
- **LOW RISK**: ~103 functions