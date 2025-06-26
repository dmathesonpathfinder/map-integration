# Chiropractor Directory Sorting Options

The `[chiropractor_directory]` shortcode now supports the following sorting options:

## Sort By Options

- `name` - Sort by display name (default)
- `last_name` - Sort by last name, then first name
- `city` - Sort by city of first location, then display name
- `location_count` - Sort by number of locations
- `date_registered` - Sort by registration date

## Sort Order Options

- `asc` - Ascending order (default)
- `desc` - Descending order

## Usage Examples

### Sort by Last Name (A-Z)
```
[chiropractor_directory sort_by="last_name" sort_order="asc"]
```

### Sort by Last Name (Z-A)
```
[chiropractor_directory sort_by="last_name" sort_order="desc"]
```

### Sort by City (A-Z)
```
[chiropractor_directory sort_by="city" sort_order="asc"]
```

### Sort by City (Z-A)
```
[chiropractor_directory sort_by="city" sort_order="desc"]
```

### Sort by Number of Locations (most to least)
```
[chiropractor_directory sort_by="location_count" sort_order="desc"]
```

### Sort by Registration Date (newest first)
```
[chiropractor_directory sort_by="date_registered" sort_order="desc"]
```

## Notes

- When sorting by `last_name`, if two chiropractors have the same last name, they will be sub-sorted by first name
- When sorting by `city`, if two chiropractors are in the same city, they will be sub-sorted by display name
- The `city` sort uses the city from the chiropractor's first location
- If a chiropractor has no last name or first name data, they will be treated as having empty strings for sorting purposes
