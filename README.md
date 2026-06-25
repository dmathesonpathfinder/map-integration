# Map Integration

A WordPress plugin that renders interactive maps and a searchable member directory with automated geocoding. Coordinates are resolved from MemberPress profile address fields using Google Maps (primary) or OpenStreetMap Nominatim (fallback) and cached in the database.

## Requirements

- WordPress 5.0+
- MemberPress (member address data sourced from `mepr_*` user meta)
- Google Maps API key (optional — enables higher-accuracy geocoding)

## Installation

1. Upload the `map-integration` folder to `/wp-content/plugins/`
2. Activate **Map Integration** in **Plugins → Installed Plugins**
3. Go to **Settings → Map Integration** to configure your Google Maps API key

## Shortcodes

### `[map_integration]`
Embeds an interactive Leaflet map showing member locations.

### `[chiropractor_directory]`
Displays a searchable member directory with map pins.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `show_search` | `true` | Show or hide the search bar (`"false"` to hide) |

```
[chiropractor_directory]
[chiropractor_directory show_search="false"]
```

## Admin Tools

**Settings → Map Integration**
- Enter and save a Google Maps API key
- Run a test geocode against any address
- Parse a street address into its components
- View geocoding cache statistics
- Clear all cached geocoding results, or only entries older than N days

## Geocoding

Addresses are geocoded from MemberPress user meta fields (street, city, province). The resolved coordinates are stored back as user meta and reused on subsequent page loads.

### Provider Order
1. **Google Maps Geocoding API** — used when an API key is configured; returns a confidence score based on `location_type` (ROOFTOP → 100, RANGE_INTERPOLATED → 90, GEOMETRIC_CENTER → 70, APPROXIMATE → 50)
2. **Nominatim (OpenStreetMap)** — free fallback; confidence scored by result class/type (house/building → 95, highway/place → 80, other → 60–70)

### Address Variations
When geocoding fails on the first attempt, the plugin retries with progressively simplified address strings:
1. `{street}, {city}, {province}, Canada`
2. `{street}, {city}, {province}`
3. Street cleaned of suite/unit/PO Box/floor references, then the same two forms

### Rate Limiting
- Nominatim: 1 request per second
- Google: 0.1 second minimum between requests

### Cache
Results are stored in a custom `geocoded_addresses` database table (created on activation). Cache TTL defaults to 30 days. Entries can be cleared from the admin tools page.

## Coordinate Storage (User Meta)

Geocoded results are written to the following user meta keys (suffix `''` for primary location, `_2` etc. for additional locations):

| Key | Description |
|-----|-------------|
| `mepr_clinic_lat` | Latitude |
| `mepr_clinic_lng` | Longitude |
| `mepr_clinic_geocoded_at` | Timestamp of last geocode |
| `mepr_clinic_geo_confidence` | Confidence score (0–100) |
| `mepr_clinic_geo_provider` | `google` or `nominatim` |
| `mepr_clinic_geo_fallback` | Whether a fallback strategy was used |

Coordinates with a confidence score ≥ 80 or sourced from Google are not re-geocoded on subsequent requests.

## PHP API

Three functions are available for use in themes or other plugins:

```php
// Geocode an address string, returns array with lat/lng or false
$result = geocode_address('123 Main St, Halifax, Nova Scotia', [
    'providers'    => ['nominatim', 'google'],
    'use_cache'    => true,
    'country_code' => 'ca',
]);
// $result['lat'], $result['lng'], $result['confidence_score'], $result['provider']

// Parse a raw street address into components
$parts = parse_street_address('123 Main St Suite 4, Halifax');
// Returns array of parsed components

// Clear the geocoding cache
$count = clear_geocoding_cache();                              // clear all
$count = clear_geocoding_cache(['older_than' => 30 * 86400]); // older than 30 days
```

## File Structure

```
├── map-integration.php                # Main plugin file (MapGeocoder + MapIntegration classes)
├── includes/
│   ├── class-geocoding-service.php    # Multi-provider geocoding service with cache
│   ├── class-street-parser.php        # Street address parser and normaliser
│   └── geocoding-functions.php        # Public helper functions (geocode_address, etc.)
├── admin/
│   └── partials/
│       └── geocoding-test.php         # Admin test/cache management UI
└── assets/
    ├── chiropractor-directory.css
    ├── chiropractor-directory.js
    ├── leaflet.css                    # Leaflet map library styles
    └── style.css
```

## Security (v1.0.1)

- All database queries use prepared statements
- User input validated and sanitised before geocoding or storage
- API key masked in all log output
- External HTTP requests restricted to whitelisted hosts (Google Maps, Nominatim)
- SSL enforced for all outbound API calls
- Log files stored in the uploads directory behind `.htaccess` deny rules, with 5 MB size cap and 90-day automatic rotation
- File logging disabled in production environments unless `MAP_INTEGRATION_ALLOW_FILE_LOGGING` is defined
- Nonce verification on all admin form submissions
- `manage_options` capability required for all admin operations

### Configuration Constants

| Constant | Default | Description |
|----------|---------|-------------|
| `MAP_INTEGRATION_MAX_LOG_SIZE` | `5242880` (5 MB) | Maximum log file size |
| `MAP_INTEGRATION_MAX_CACHE_ENTRIES` | `10000` | Maximum geocoding cache entries |
| `MAP_INTEGRATION_RATE_LIMIT_WINDOW` | `60` | Rate limit window in seconds |
| `MAP_INTEGRATION_ALLOW_FILE_LOGGING` | _(unset)_ | Define to enable file logging in production |

## License

GPL-2.0+
