# Bulk Geocoding Improvements

This document describes the enhanced bulk geocoding functionality added to address the requirements in issue #3.

## Overview

The bulk geocoding system has been completely redesigned to provide:
1. **Interruptible Process**: Start/stop control through AJAX interface
2. **Better Address Handling**: Enhanced fallback strategies for poorly formatted addresses
3. **No Time Limits**: Continuous processing that can run indefinitely until interrupted

## New Features

### Interactive Control Interface

The admin interface now includes:
- **Start/Stop buttons**: Real-time control over the geocoding process
- **Live status updates**: Progress updates every 3 seconds
- **Progress tracking**: Shows processed, successful, and failed counts
- **Current activity**: Displays which user is currently being processed

### Enhanced Address Fallback

When an exact address cannot be geocoded, the system now tries:

1. **Full Address**: `Street, City, Province, Country` (highest confidence)
2. **City + Province**: `City, Province, Country` (confidence -30)
3. **City Only**: `City, Country` (confidence -40)
4. **Province Only**: `Province, Country` (confidence 10 - very low)

Each fallback result is marked with:
- **Confidence score**: Numerical score indicating geocoding quality
- **Fallback type**: Which strategy was used to obtain the result

### Background Processing

The system uses WordPress cron to process geocoding in the background:
- **Batch processing**: Handles 10 addresses at a time
- **Rate limiting**: 1-second delay between requests
- **Automatic scheduling**: Self-schedules next batch until complete
- **Error resilience**: Continues processing even if individual addresses fail

### Improved Data Storage

Geocoded addresses now store additional metadata:
- `mepr_clinic_geo_confidence{suffix}`: Confidence score (0-100)
- `mepr_clinic_geo_fallback{suffix}`: Fallback strategy used
- Enhanced coordinate compatibility (supports both lat/lng and latitude/longitude)

## Usage

### Starting Bulk Geocoding

1. Navigate to Settings → Map Integration
2. Scroll to "Interactive Bulk Geocoding" section
3. Click "Start Bulk Geocoding"
4. Monitor progress in real-time
5. Click "Stop Geocoding" to interrupt if needed

### Understanding Results

- **High confidence (70-100)**: Exact address match
- **Medium confidence (30-69)**: City or street-level match
- **Low confidence (10-29)**: Province-level approximation
- **Fallback indicators**: Shows which strategy was used

### Monitoring Progress

The status panel shows:
- **Status**: Running/Not running
- **Processed**: Total addresses processed
- **Successful**: Successfully geocoded addresses
- **Failed**: Addresses that couldn't be geocoded
- **Current**: What the system is currently processing

## Technical Details

### AJAX Endpoints

- `wp_ajax_start_bulk_geocoding`: Start the background process
- `wp_ajax_stop_bulk_geocoding`: Stop the background process  
- `wp_ajax_get_bulk_geocoding_status`: Get current status

### WordPress Hooks

- `process_geocoding_batch`: Scheduled action for background processing
- Status stored in WordPress transients with 12-hour expiration

### Error Handling

- Network errors are logged and don't stop the process
- Individual address failures are tracked but don't halt batches
- Stale processes are automatically detected and cleaned up
- UI provides feedback for all error conditions

## Backwards Compatibility

- Legacy "Run Legacy Bulk Geocoding" button remains available
- Existing coordinate data format is maintained
- New metadata is optional and doesn't affect existing functionality

## Benefits

1. **No more timeouts**: Process can run indefinitely until complete
2. **Better success rate**: Fallback strategies find approximate coordinates for bad addresses
3. **User control**: Can start/stop process without page reloads
4. **Better data quality**: Confidence scores help identify quality of geocoding
5. **Robust error handling**: Process continues even when individual addresses fail