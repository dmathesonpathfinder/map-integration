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

            this.updateResultsCount(visibleCount, query);
            this.handleNoResults(visibleCount);
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
