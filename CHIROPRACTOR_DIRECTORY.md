# Chiropractor Directory Feature

## Overview
The Map Integration plugin now includes a comprehensive chiropractor directory feature that recreates and enhances the functionality of the Dynamic User Directory plugin, specifically tailored for chiropractor listings with map integration.

## Features

### 🔍 **Fuzzy Search**
- Real-time search as you type
- Searches across chiropractor names, clinic names, addresses, phone numbers, and email addresses
- Fuzzy matching algorithm finds results even with partial or slightly misspelled terms
- Search highlighting shows matching terms in results
- Minimum 2 characters required for search activation

### 🗺️ **Map Integration**
- Clickable links on chiropractor names and clinic locations
- "View on Map" buttons for each location with coordinates
- Automatically centers the map on selected locations
- Smooth scrolling and animation to map section
- Compatible with existing map shortcodes

### 🎨 **Enhanced Styling**
- Modern, responsive design similar to Dynamic User Directory
- Card-based layout for easy scanning
- Hover effects and smooth transitions
- Mobile-responsive design
- Customizable with CSS

### 👤 **User Profiles**
- Multiple clinic locations per chiropractor
- Contact information (phone, email, website)
- Bio/description excerpts
- User role filtering

## Shortcode Usage

### Basic Directory
```
[chiropractor_directory]
```

### Directory with Search Disabled
```
[chiropractor_directory show_search="false"]
```

### Directory with Integrated Map
```
[chiropractor_directory include_map="true"]
```

### Directory without Map Links
```
[chiropractor_directory show_map_links="false"]
```

### Directory without Contact Info
```
[chiropractor_directory show_contact="false"]
```

### Sorted by Location Count (Most locations first)
```
[chiropractor_directory sort_by="location_count" sort_order="desc"]
```

### Filter by User Role
```
[chiropractor_directory user_role="subscriber"]
```

## Shortcode Parameters

| Parameter | Default | Options | Description |
|-----------|---------|---------|-------------|
| `user_role` | `subscriber` | Any WordPress role | Filter users by role |
| `show_search` | `true` | `true`, `false` | Enable/disable search functionality |
| `show_map_links` | `true` | `true`, `false` | Show clickable map links |
| `include_map` | `false` | `true`, `false` | Include map above listings |
| `map_width` | `100%` | CSS width value | Map width when included |
| `map_height` | `400px` | CSS height value | Map height when included |
| `center_lat` | `44.6488` | Latitude | Default map center latitude |
| `center_lng` | `-63.5752` | Longitude | Default map center longitude |
| `zoom` | `7` | 1-18 | Default map zoom level |
| `show_contact` | `true` | `true`, `false` | Display contact information |
| `sort_by` | `name` | `name`, `location_count`, `date_registered` | Sort criteria |
| `sort_order` | `asc` | `asc`, `desc` | Sort direction |

## Technical Details

### Data Sources
The directory pulls data from WordPress user meta fields:
- Primary Location: `mepr_clinic_*`
- Secondary Location: `mepr_clinic_*_2`
- Third Location: `mepr_clinic_*_3`

### Search Algorithm
- **Exact Match**: Gets highest priority (2 points)
- **Fuzzy Match**: Character-by-character matching (1 point)
- **Minimum Score**: At least 1 point required to show result
- **Multiple Terms**: All terms must have at least one match

### Performance
- Client-side search for instant results
- Debounced input (300ms delay) to reduce processing
- Efficient DOM manipulation
- Lazy loading of search data

## Styling Customization

The directory uses these main CSS classes:

```css
.chiro-directory-container       /* Main container */
.chiro-directory-search         /* Search section */
.chiro-listings-grid           /* Listings container */
.chiro-listing                 /* Individual chiropractor */
.chiro-header                  /* Name and avatar section */
.chiro-locations               /* Locations container */
.location-item                 /* Individual location */
```

## JavaScript API

The search functionality is exposed globally as `ChiroDirectorySearch` for debugging and custom integration:

```javascript
// Clear search programmatically
ChiroDirectorySearch.clearSearch();

// Perform search programmatically
ChiroDirectorySearch.performSearch();

// Get current filter
console.log(ChiroDirectorySearch.currentFilter);
```

## Compatibility

- **WordPress**: 5.0+
- **PHP**: 7.4+
- **Browsers**: Modern browsers with ES5+ support
- **Mobile**: Fully responsive design
- **Map Integration**: Works with existing Leaflet.js implementation

## Future Enhancements

Potential future additions:
- Alphabetical navigation
- Advanced filtering (by location, specialties, etc.)
- Export functionality
- Print-friendly version
- Integration with booking systems
