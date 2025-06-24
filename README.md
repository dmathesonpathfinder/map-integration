# Map Integration Plugin

A comprehensive WordPress plugin for integrating interactive maps with MemberPress clinic addresses, featuring advanced street parsing and multi-provider geocoding.

## Features

- **Advanced Street Parsing**: Parse and normalize complex address formats with confidence scoring
- **Multi-Provider Geocoding**: Support for OpenStreetMap/Nominatim and Google Maps API with automatic fallback
- **Intelligent Caching**: Database-backed geocoding cache with configurable TTL for improved performance
- **Automatic Geocoding**: Automatically geocodes clinic addresses when MemberPress address fields are updated
- **Bulk Geocoding**: Process existing addresses in batches with safety limits  
- **Interactive Maps**: Display clinic locations on Leaflet.js maps using OpenStreetMap
- **Multiple Address Sets**: Supports primary, secondary, and third clinic addresses
- **Rate Limiting**: Respects API rate limits (1 request per second for Nominatim)
- **Admin Tools**: Comprehensive testing and management interface
- **Custom Logging**: Detailed logging to `geocodelogs.txt`

## Usage

### Shortcodes

Display all clinic locations on an interactive map:
```
[map_integration]
```

Custom map size:
```
[map_integration width="800px" height="500px"]
```

Custom center point and zoom level:
```
[map_integration center_lat="44.6488" center_lng="-63.5752" zoom="8"]
```

Legacy placeholder map:
```
[map_integration show_clinics="false" location="Custom Location"]
```

### Shortcode Parameters

- `width` - Map width (default: 100%)
- `height` - Map height (default: 400px)  
- `show_clinics` - Show clinic markers (default: true)
- `center_lat` - Map center latitude (default: 44.6488 - Nova Scotia)
- `center_lng` - Map center longitude (default: -63.5752 - Nova Scotia)
- `zoom` - Initial zoom level (default: 7)
- `location` - Legacy placeholder text

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings at **Settings → Map Integration**
4. (Optional) Add Google Maps API key for enhanced geocoding accuracy
5. Access testing tools at **Settings → Geocoding Tools**

## New Geocoding API

### Basic Usage

```php
// Geocode an address
$result = geocode_address('123 Main Street, Halifax, NS');
if ($result) {
    echo "Coordinates: " . $result['lat'] . ", " . $result['lng'];
    echo "Provider: " . $result['provider'];
    echo "Confidence: " . $result['confidence_score'] . "%";
}

// Parse a street address
$parsed = parse_street_address('123 N Main St, Apt 4B');
echo "House: " . $parsed['house_number'];
echo "Street: " . $parsed['street_name'];
echo "Unit: " . $parsed['unit_designator'] . " " . $parsed['unit_number'];

// Clear old cache entries
$cleared = clear_geocoding_cache(['older_than' => 30 * DAY_IN_SECONDS]);
```

### Advanced Options

```php
// Custom geocoding options
$result = geocode_address($address, [
    'providers' => ['google', 'nominatim'], // Provider preference order
    'use_cache' => true,                    // Enable/disable caching
    'country_code' => 'ca',                 // Country restriction
    'timeout' => 15                         // Request timeout
]);

// Bulk geocoding
$addresses = ['123 Main St', '456 Oak Ave', '789 Pine Rd'];
$results = bulk_geocode_addresses($addresses, [
    'delay' => 1,           // Delay between requests
    'providers' => ['nominatim']
]);
```

## Configuration

### Google Maps API Key (Optional)
1. Go to **Settings → Map Integration**
2. Enter your Google Maps Geocoding API key
3. This enables Google as a fallback provider for higher accuracy

### Cache Management
- Access **Settings → Geocoding Tools** for cache management
- Default cache TTL: 30 days
- Clear cache by age or provider
- View cache statistics and system status

## Database Schema

The plugin creates a `{prefix}_geocoded_addresses` table:

```sql
CREATE TABLE wp_geocoded_addresses (
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
);
```

## Backward Compatibility

All existing functionality is preserved:
- `MapGeocoder` class methods unchanged
- Existing user meta fields continue to work
- Current bulk geocoding process maintained
- No breaking changes to existing API
