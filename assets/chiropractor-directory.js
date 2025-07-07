/**
 * Chiropractor Directory Search Functionality
 * Provides fuzzy search capabilities for the chiropractor directory
 */

(function ($) {
    'use strict';

    // Main search functionality
    var ChiroDirectorySearch = {

        // Configuration
        config: {
            searchDelay: 300, // ms to wait after typing before searching
            minSearchLength: 3, // minimum characters before starting search
            highlightClass: 'search-highlight',
            noResultsClass: 'no-results',
            minMatchScore: 2, // minimum score required to show result
            exactMatchBonus: 3 // bonus points for exact matches
        },

        // State
        searchTimeout: null,
        allListings: [],
        currentFilter: '',

        // Initialize the search functionality
        init: function () {
            this.cacheElements();
            this.bindEvents();
            this.prepareData();
            this.initializeSortButtons();
        },

        // Initialize sort buttons functionality
        initializeSortButtons: function () {
            this.currentSort = 'name';
            this.currentOrder = 'asc';
        },

        // Handle sort button click
        handleSortClick: function (sortBy, sortOrder) {
            // Update active button
            $('.sort-button').removeClass('active');
            $('.sort-button[data-sort="' + sortBy + '"][data-order="' + sortOrder + '"]').addClass('active');

            this.currentSort = sortBy;
            this.currentOrder = sortOrder;

            this.sortListings(sortBy, sortOrder);
        },

        // Sort listings in the DOM
        sortListings: function (sortBy, sortOrder) {
            var self = this;
            var $listings = $('.chiro-listing');
            var listingsArray = $listings.toArray();

            // Remove any existing city headings
            $('.city-heading').remove();

            listingsArray.sort(function (a, b) {
                var $a = $(a);
                var $b = $(b);
                var result = 0;

                switch (sortBy) {

                    case 'last_name':
                        // Use data attributes for consistent sorting with PHP
                        var lastNameA = $a.attr('data-last-name') || '';
                        var lastNameB = $b.attr('data-last-name') || '';
                        result = lastNameA.localeCompare(lastNameB);
                        if (result === 0) {
                            // Fallback to first name
                            var firstNameA = $a.attr('data-first-name') || '';
                            var firstNameB = $b.attr('data-first-name') || '';
                            result = firstNameA.localeCompare(firstNameB);
                        }
                        break;

                    case 'city':
                        // Use data attributes for consistent sorting with PHP
                        var cityA = $a.attr('data-city') || '';
                        var cityB = $b.attr('data-city') || '';
                        result = cityA.localeCompare(cityB);
                        if (result === 0) {
                            // Fallback to last name, then first name
                            var lastNameA = $a.attr('data-last-name') || '';
                            var lastNameB = $b.attr('data-last-name') || '';
                            result = lastNameA.localeCompare(lastNameB);
                            if (result === 0) {
                                var firstNameA = $a.attr('data-first-name') || '';
                                var firstNameB = $b.attr('data-first-name') || '';
                                result = firstNameA.localeCompare(firstNameB);
                            }
                        }
                        break;

                    default:
                        // Default to last name sorting using data attributes
                        var lastNameA = $a.attr('data-last-name') || '';
                        var lastNameB = $b.attr('data-last-name') || '';
                        result = lastNameA.localeCompare(lastNameB);
                        if (result === 0) {
                            // Fallback to first name
                            var firstNameA = $a.attr('data-first-name') || '';
                            var firstNameB = $b.attr('data-first-name') || '';
                            result = firstNameA.localeCompare(firstNameB);
                        }
                }

                return sortOrder === 'desc' ? -result : result;
            });

            // Reorder the DOM elements with city headings if sorting by city
            var $container = $('.chiro-listings-grid');
            $container.empty();

            if (sortBy === 'city') {
                // Group by city and add headings using data attributes
                var currentCity = '';
                $.each(listingsArray, function (index, element) {
                    var $element = $(element);
                    var city = $element.attr('data-city') || '';

                    if (city && city !== currentCity) {
                        currentCity = city;
                        var cityHeading = $('<div class="city-heading"><h4>' + self.escapeHtml(city) + '</h4></div>');
                        $container.append(cityHeading);
                    }

                    $container.append(element);
                });
            } else {
                // Normal reordering without headings
                $.each(listingsArray, function (index, element) {
                    $container.append(element);
                });
            }

            // Update results count if search is active
            if (this.currentFilter) {
                this.updateResultsCount(this.getVisibleListingsCount(), this.currentFilter);
            }
        },

        // Escape HTML for safety
        escapeHtml: function (text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function (m) { return map[m]; });
        },

        // Get count of visible listings
        getVisibleListingsCount: function () {
            return $('.chiro-listing:visible').length;
        },

        // Cache DOM elements
        cacheElements: function () {
            this.$searchInput = $('#chiro-search-input');
            this.$searchClear = $('#chiro-search-clear');
            this.$listingsContainer = $('.chiro-listings-grid');
            this.$listings = $('.chiro-listing');
            this.$resultsCount = $('.search-results-count');
        },

        // Bind event handlers
        bindEvents: function () {
            var self = this;

            // Search input events
            this.$searchInput.on('input keyup', function (e) {
                clearTimeout(self.searchTimeout);
                self.searchTimeout = setTimeout(function () {
                    self.performSearch();
                }, self.config.searchDelay);
            });

            // Clear search button
            this.$searchClear.on('click', function (e) {
                e.preventDefault();
                self.clearSearch();
            });

            // Form submit prevention
            $('#chiro-search-form').on('submit', function (e) {
                e.preventDefault();
                self.performSearch();
            });

            // Enter key handling
            this.$searchInput.on('keydown', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.performSearch();
                }
            });

            // Sort button events
            $(document).on('click', '.sort-button', function (e) {
                e.preventDefault();
                var $button = $(this);
                var sortBy = $button.data('sort');
                var sortOrder = $button.data('order');
                self.handleSortClick(sortBy, sortOrder);
            });
        },

        // Prepare searchable data from listings
        prepareData: function () {
            var self = this;
            this.allListings = [];

            this.$listings.each(function (index) {
                var $listing = $(this);
                var searchableText = self.extractSearchableText($listing);

                self.allListings.push({
                    element: $listing,
                    searchText: searchableText.toLowerCase(),
                    originalText: searchableText
                });
            });
        },

        // Extract searchable text from a listing
        extractSearchableText: function ($listing) {
            var texts = [];

            // Chiropractor name
            var name = $listing.find('.chiro-name').text().trim();
            if (name) texts.push(name);

            // Summary/description
            var summary = $listing.find('.chiro-summary').text().trim();
            if (summary) texts.push(summary);

            // Location names
            $listing.find('.location-name').each(function () {
                var locationName = $(this).text().trim();
                if (locationName) texts.push(locationName);
            });

            // Addresses
            $listing.find('.location-address').each(function () {
                var address = $(this).text().trim();
                if (address) texts.push(address);
            });

            // Phone numbers
            $listing.find('.location-phone').each(function () {
                var phone = $(this).text().trim();
                if (phone) texts.push(phone);
            });

            // Email addresses
            $listing.find('.location-email').each(function () {
                var email = $(this).text().trim();
                if (email) texts.push(email);
            });

            return texts.join(' ');
        },

        // Perform the search
        performSearch: function () {
            var query = this.$searchInput.val().trim();
            this.currentFilter = query;

            // Show/hide clear button
            if (query) {
                this.$searchClear.show();
            } else {
                this.$searchClear.hide();
            }

            if (query.length < this.config.minSearchLength && query.length > 0) {
                this.updateResultsCount('Type at least ' + this.config.minSearchLength + ' characters to search');
                return;
            }

            this.filterListings(query);
        },

        // Filter listings based on search query
        filterListings: function (query) {
            var self = this;
            var visibleCount = 0;
            var highlightTerms = this.prepareSearchTerms(query);

            if (!query) {
                // Show all listings if no query
                this.$listings.show();
                this.clearHighlights();
                this.updateResultsCount('');
                // Show all city headings when no search
                $('.city-heading').show();
                return;
            }

            this.allListings.forEach(function (item) {
                var matches = self.fuzzyMatch(item.searchText, query);

                if (matches) {
                    item.element.show();
                    self.highlightMatches(item.element, highlightTerms);
                    visibleCount++;
                } else {
                    item.element.hide();
                }
            });

            // Handle city headers visibility if sorting by city
            if (this.currentSort === 'city') {
                this.updateCityHeadersVisibility();
            }

            this.updateResultsCount(visibleCount, query);
            this.handleNoResults(visibleCount);
        },

        // Update city headers visibility based on search results
        updateCityHeadersVisibility: function () {
            var self = this;
            var $cityHeadings = $('.city-heading');

            $cityHeadings.each(function () {
                var $heading = $(this);
                var $nextElements = $heading.nextUntil('.city-heading, :not(.chiro-listing)');
                var $listings = $nextElements.filter('.chiro-listing');

                // Count visible listings after this city heading
                var visibleListings = 0;
                $listings.each(function () {
                    if ($(this).is(':visible')) {
                        visibleListings++;
                    }
                });

                // Show/hide the city heading based on visible listings
                if (visibleListings > 0) {
                    $heading.show();
                } else {
                    $heading.hide();
                }
            });
        },

        // Prepare search terms for highlighting
        prepareSearchTerms: function (query) {
            return query.toLowerCase().split(/\s+/).filter(function (term) {
                return term.length >= 3; // Only highlight terms with 3+ characters
            });
        },

        // Fuzzy matching algorithm - improved for better precision
        fuzzyMatch: function (text, query) {
            if (!query) return true;

            var queryTerms = query.toLowerCase().split(/\s+/).filter(function (term) {
                return term.length > 0;
            });

            var totalScore = 0;
            var requiredMatches = 0;

            queryTerms.forEach(function (term) {
                if (term.length === 0) return;

                var termScore = 0;
                var termMatched = false;

                // Exact word match gets highest priority
                var exactWordRegex = new RegExp('\\b' + this.escapeRegex(term) + '\\b', 'i');
                if (exactWordRegex.test(text)) {
                    termScore += this.config.exactMatchBonus;
                    termMatched = true;
                }
                // Exact substring match gets high priority
                else if (text.indexOf(term) !== -1) {
                    termScore += 2;
                    termMatched = true;
                }
                // Partial fuzzy match (but only for terms 3+ characters)
                else if (term.length >= 3 && this.partialFuzzyMatch(text, term)) {
                    // Only give partial credit and require longer match
                    var matchRatio = this.calculateMatchRatio(text, term);
                    if (matchRatio >= 0.7) { // Require 70% character match
                        termScore += 1;
                        termMatched = true;
                    }
                }

                if (termMatched) {
                    requiredMatches++;
                }

                totalScore += termScore;
            }.bind(this));

            // Require ALL terms to have at least some match AND minimum total score
            return requiredMatches === queryTerms.length && totalScore >= this.config.minMatchScore;
        },

        // Partial fuzzy matching for individual terms - more strict
        partialFuzzyMatch: function (text, term) {
            var termIndex = 0;
            var textIndex = 0;
            var matchedChars = 0;

            while (termIndex < term.length && textIndex < text.length) {
                if (term[termIndex] === text[textIndex]) {
                    termIndex++;
                    matchedChars++;
                }
                textIndex++;
            }

            // Require at least 80% of characters to match in sequence
            var matchRatio = matchedChars / term.length;
            return termIndex === term.length && matchRatio >= 0.8;
        },

        // Calculate how well the term matches within the text
        calculateMatchRatio: function (text, term) {
            var termIndex = 0;
            var matchedChars = 0;

            for (var i = 0; i < text.length && termIndex < term.length; i++) {
                if (text[i] === term[termIndex]) {
                    matchedChars++;
                    termIndex++;
                }
            }

            return matchedChars / term.length;
        },

        // Highlight matching terms in listings
        highlightMatches: function ($listing, terms) {
            var self = this;

            // Clear existing highlights first
            this.clearHighlights($listing);

            if (terms.length === 0) return;

            // Elements to search for highlighting
            var $searchableElements = $listing.find('.chiro-name, .chiro-summary, .location-name, .location-address');

            $searchableElements.each(function () {
                var $element = $(this);
                var text = $element.text();
                var highlightedText = text;

                terms.forEach(function (term) {
                    if (term.length >= 3) { // Only highlight terms with 3+ characters
                        // Try exact word match first
                        var wordRegex = new RegExp('(\\b' + self.escapeRegex(term) + '\\b)', 'gi');
                        if (wordRegex.test(text)) {
                            highlightedText = highlightedText.replace(wordRegex, '<span class="' + self.config.highlightClass + '">$1</span>');
                        } else {
                            // Fall back to substring match
                            var regex = new RegExp('(' + self.escapeRegex(term) + ')', 'gi');
                            highlightedText = highlightedText.replace(regex, '<span class="' + self.config.highlightClass + '">$1</span>');
                        }
                    }
                });

                if (highlightedText !== text) {
                    $element.html(highlightedText);
                }
            });
        },

        // Clear search highlights
        clearHighlights: function ($container) {
            var $target = $container || this.$listingsContainer;
            $target.find('.' + this.config.highlightClass).each(function () {
                var $this = $(this);
                $this.replaceWith($this.text());
            });
        },

        // Escape regex special characters
        escapeRegex: function (string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        },

        // Update results count display
        updateResultsCount: function (count, query) {
            if (!this.$resultsCount.length) {
                // Create results count element if it doesn't exist
                this.$resultsCount = $('<div class="search-results-count"></div>');
                this.$listingsContainer.before(this.$resultsCount);
            }

            if (typeof count === 'string') {
                // Display custom message
                this.$resultsCount.text(count).show();
            } else if (query) {
                var message = count === 1 ?
                    count + ' chiropractor found for "' + query + '"' :
                    count + ' chiropractors found for "' + query + '"';
                this.$resultsCount.text(message).show();
            } else {
                this.$resultsCount.hide();
            }
        },

        // Handle no search results
        handleNoResults: function (count) {
            var $noResults = $('.' + this.config.noResultsClass);

            if (count === 0 && this.currentFilter) {
                if ($noResults.length === 0) {
                    $noResults = $('<div class="' + this.config.noResultsClass + '">No chiropractors found matching your search criteria. Try different keywords or check your spelling.</div>');
                    this.$listingsContainer.after($noResults);
                }
                $noResults.show();
            } else {
                $noResults.hide();
            }
        },

        // Clear search and reset view
        clearSearch: function () {
            this.$searchInput.val('');
            this.$searchClear.hide();
            this.currentFilter = '';
            this.$listings.show();
            this.clearHighlights();
            this.updateResultsCount('');
            this.handleNoResults(this.$listings.length);
            // Show all city headings when clearing search
            $('.city-heading').show();
            this.$searchInput.focus();
        }
    };

    // Initialize when document is ready
    $(document).ready(function () {
        // Only initialize if the chiropractor directory exists
        if ($('.chiro-directory-container').length > 0) {
            ChiroDirectorySearch.init();
        }
    });

    // Make the search object globally available for debugging
    window.ChiroDirectorySearch = ChiroDirectorySearch;

})(jQuery);
