# Map Integration Plugin

A minimal WordPress plugin for integrating interactive maps with MemberPress clinic addresses.

## Features

- **Automatic Geocoding**: Automatically geocodes clinic addresses when MemberPress address fields are updated
- **Bulk Geocoding**: Process existing addresses in batches with safety limits  
- **Interactive Maps**: Display clinic locations on Leaflet.js maps using OpenStreetMap
- **Multiple Address Sets**: Supports primary, secondary, and third clinic addresses
- **Rate Limiting**: Respects Nominatim API rate limits (1 request per second)
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
