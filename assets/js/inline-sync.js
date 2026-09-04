/**
 * Inline Sync Controller
 *
 * Two-phase sync with visible progress:
 *
 * 1. FETCH — calls /fetch endpoint, which hits the external API (Stripe, etc.)
 *    and caches items server-side. Returns item count immediately.
 *
 * 2. PROCESS — calls /process endpoint repeatedly in small chunks (5 items).
 *    Each call returns per-item results. Progress bar and item names update
 *    after every chunk. Continues until page_done, then fetches next page
 *    if has_more is true.
 *
 * This ensures the user sees immediate feedback: a "Fetching..." state,
 * then the progress bar moving with each chunk, with product names rolling
 * through as items are processed.
 *
 * @package ArrayPress\InlineSync
 * @since   1.0.0
 */
(function ($) {
    'use strict';

    if (typeof window.InlineSyncConfig === 'undefined') {
        return;
    }

    /*
     * Bind once, however many copies of this file load.
     *
     * Strauss gives each prefixed copy its own script handle, so two plugins
     * bundling this library both enqueue it and the browser runs it twice.
     * Without this guard every button gets two click handlers and every sync
     * runs twice against the same cursor.
     */
    if (window.InlineSync && window.InlineSync.__bound) {
        return;
    }

    const syncs = window.InlineSyncConfig.syncs;

    /**
     * Everything a sync needs to talk to its own build.
     *
     * Each build has its own REST namespace, so the endpoint belongs to the
     * sync rather than to the page.
     *
     * @param {string} syncId Registered sync ID.
     *
     * @return {Object} The sync's configuration.
     */
    function configFor(syncId) {
        return syncs[syncId] || {};
    }

    /**
     * Track cancelled syncs.
     *
     * @type {Object<string, boolean>}
     */
    const cancelled = {};

    /**
     * Public API.
     */
    window.InlineSync = {

        /**
         * Set once this file has bound its handlers.
         *
         * Read by the guard at the top: a second copy of this script, loaded
         * under another plugin's prefixed handle, returns before rebinding.
         */
        __bound: true,

        /**
         * Start a sync by ID.
         *
         * @param {string} syncId Registered sync ID.
         */
        start: function (syncId) {
            const config = syncs[syncId];

            if (!config) {
                console.error('[InlineSync] Unknown sync:', syncId);
                return;
            }

            cancelled[syncId] = false;

            // Find container
            const $container = $(config.container);

            if (!$container.length) {
                console.error('[InlineSync] Container not found:', config.container);
                return;
            }

            // Disable trigger button
            const $trigger = $('.inline-sync-trigger[data-sync-id="' + syncId + '"]');
            // updating-message is core's own busy state for a button: the
            // spinning glyph, its colour and its reduced-motion handling are
            // already written, in common.css, on the class core puts on a
            // plugin's Update button.
            $trigger.addClass('is-syncing updating-message').prop('disabled', true);

            // Create or reset bar
            let $bar = $container.prev('.inline-sync-bar[data-sync-id="' + syncId + '"]');

            if (!$bar.length) {
                $bar = this._createBar(syncId);
                $container.before($bar);
            }

            this._resetBar($bar, config);

            // Start with fetch phase
            this._fetchPage($bar, syncId, '', {
                created: 0,
                updated: 0,
                skipped: 0,
                failed: 0,
                processed: 0,
                total: null,
                errors: []
            });
        },

        /**
         * Cancel a running sync.
         *
         * @param {string} syncId Sync ID.
         */
        cancel: function (syncId) {
            cancelled[syncId] = true;
        },

        // =====================================================================
        // Two-Phase Flow
        // =====================================================================

        /**
         * Phase 1: Fetch a page of items from the source.
         *
         * Calls /fetch, which hits the external API and caches items.
         * On success, switches to the process loop.
         *
         * @param {jQuery} $bar   Bar element.
         * @param {string} syncId Sync ID.
         * @param {string} cursor Pagination cursor.
         * @param {object} totals Running totals.
         * @private
         */
        _fetchPage: function ($bar, syncId, cursor, totals) {
            if (cancelled[syncId]) {
                this._showCancelled($bar, syncId);
                return;
            }

            // Show fetching state
            const config = syncs[syncId] || {};
            $bar.find('.inline-sync-title').text(config.title + ' — ' + config.i18n.fetching);

            $.ajax({
                url: config.restUrl + 'fetch',
                method: 'POST',
                headers: {'X-WP-Nonce': config.restNonce},
                contentType: 'application/json',
                data: JSON.stringify({
                    sync_id: syncId,
                    cursor: cursor
                })
            }).done((response) => {
                if (cancelled[syncId]) {
                    this._showCancelled($bar, syncId);
                    return;
                }

                // Track total if provided
                if (response.total) {
                    totals.total = response.total;
                }

                // Store pagination info for after processing
                const pageInfo = {
                    has_more: response.has_more,
                    cursor: response.cursor,
                    fetched: response.fetched
                };

                // Update title to syncing
                $bar.find('.inline-sync-title').text(config.title + ' — ' + config.i18n.syncing);

                if (response.fetched === 0) {
                    // Nothing fetched — done
                    this._showComplete($bar, syncId, totals);
                    return;
                }

                // If we don't have a total from the API, use fetched count
                // (accumulates across pages)
                if (!totals.total) {
                    totals._fetched = (totals._fetched || 0) + response.fetched;
                }

                // Start processing chunks
                this._processChunk($bar, syncId, totals, pageInfo);

            }).fail((xhr) => {
                const msg = xhr.responseJSON?.message || config.i18n.syncFailed;
                this._showError($bar, syncId, msg);
            });
        },

        /**
         * Phase 2: Process a chunk of cached items.
         *
         * Calls /process repeatedly until page_done, then either
         * fetches the next page or shows completion.
         *
         * @param {jQuery} $bar      Bar element.
         * @param {string} syncId    Sync ID.
         * @param {object} totals    Running totals.
         * @param {object} pageInfo  Pagination info from fetch.
         * @private
         */
        _processChunk: function ($bar, syncId, totals, pageInfo) {
            const config = configFor(syncId);
            if (cancelled[syncId]) {
                this._showCancelled($bar, syncId);
                return;
            }

            $.ajax({
                url: config.restUrl + 'process',
                method: 'POST',
                headers: {'X-WP-Nonce': config.restNonce},
                contentType: 'application/json',
                data: JSON.stringify({
                    sync_id: syncId
                })
            }).done((response) => {
                if (cancelled[syncId]) {
                    this._showCancelled($bar, syncId);
                    return;
                }

                // Accumulate results
                totals.created += response.created || 0;
                totals.updated += response.updated || 0;
                totals.skipped += response.skipped || 0;
                totals.failed += response.failed || 0;
                totals.processed += response.processed || 0;

                // Collect errors
                if (response.items) {
                    response.items.forEach(function (item) {
                        if (item.status === 'failed' && item.error) {
                            totals.errors.push(item.name + ': ' + item.error);
                        }
                    });
                }

                // Update progress bar
                this._updateProgress($bar, totals, response.items, syncId);

                if (!response.page_done) {
                    // More chunks in this page
                    this._processChunk($bar, syncId, totals, pageInfo);
                } else if (pageInfo.has_more) {
                    // This page done, but more pages available
                    this._fetchPage($bar, syncId, pageInfo.cursor, totals);
                } else {
                    // All done
                    this._showComplete($bar, syncId, totals);
                }

            }).fail((xhr) => {
                const msg = xhr.responseJSON?.message || config.i18n.syncFailed;
                this._showError($bar, syncId, msg);
            });
        },

        // =====================================================================
        // UI
        // =====================================================================

        /**
         * Create the progress bar element.
         *
         * @param {string} syncId Sync ID.
         * @returns {jQuery}
         * @private
         */
        _createBar: function (syncId) {
            const config = configFor(syncId);
            const self = this;

            return $([
                '<div class="inline-sync-bar" data-sync-id="' + this._esc(syncId) + '">',
                '  <div class="inline-sync-header">',
                '    <div class="inline-sync-title"></div>',
                '    <button type="button" class="inline-sync-cancel">' + config.i18n.cancel + '</button>',
                '  </div>',
                '  <div class="inline-sync-progress">',
                '    <div class="inline-sync-track">',
                '      <div class="inline-sync-fill"></div>',
                '    </div>',
                '  </div>',
                '  <div class="inline-sync-status">',
                '    <span class="inline-sync-count"></span>',
                '    <span class="inline-sync-current"></span>',
                '  </div>',
                '  <div class="inline-sync-result"></div>',
                '</div>'
            ].join('\n')).on('click', '.inline-sync-cancel', function () {
                if (confirm(config.i18n.confirmCancel)) {
                    self.cancel(syncId);
                }
            }).on('click', '.inline-sync-dismiss', function () {
                $('[data-sync-id="' + syncId + '"].inline-sync-bar').removeClass('is-active');
            });
        },

        /**
         * Reset bar to active syncing state.
         *
         * @param {jQuery} $bar   Bar element.
         * @param {object} config Sync config.
         * @private
         */
        _resetBar: function ($bar, config) {
            $bar.removeClass('is-complete is-error').addClass('is-active');
            $bar.find('.inline-sync-title').text(config.title + ' — ' + config.i18n.fetching);
            $bar.find('.inline-sync-fill').css('width', '0%');
            $bar.find('.inline-sync-count').text('');
            $bar.find('.inline-sync-current').text('');
            $bar.find('.inline-sync-result').empty().hide();
            $bar.find('.inline-sync-cancel').show();
            $bar.find('.inline-sync-progress').show();
            $bar.find('.inline-sync-status').show();
        },

        /**
         * Update progress bar and current item.
         *
         * @param {jQuery} $bar   Bar element.
         * @param {object} totals Running totals.
         * @param {array}  items  Items from current chunk.
         * @private
         */
        _updateProgress: function ($bar, totals, items, syncId) {
            const config = configFor(syncId || $bar.data('sync-id'));
            // Determine the best total we have
            const displayTotal = totals.total || totals._fetched || null;

            // Percentage
            let pct = 0;
            if (displayTotal && displayTotal > 0) {
                pct = Math.min(100, Math.round((totals.processed / displayTotal) * 100));
            }
            $bar.find('.inline-sync-fill').css('width', pct + '%');

            // Count text
            const countText = displayTotal
                ? totals.processed + ' ' + config.i18n.of + ' ' + displayTotal
                : totals.processed + ' ' + config.i18n.items;
            $bar.find('.inline-sync-count').text(countText);

            // Current item name (last item in chunk)
            if (items && items.length > 0) {
                const last = items[items.length - 1].name;
                if (last) {
                    $bar.find('.inline-sync-current').text(last);
                }
            }
        },

        /**
         * Show completion state.
         *
         * @param {jQuery} $bar   Bar element.
         * @param {string} syncId Sync ID.
         * @param {object} totals Final totals.
         * @private
         */
        _showComplete: function ($bar, syncId, totals) {
            $bar.addClass('is-complete');
            $bar.find('.inline-sync-fill').css('width', '100%');

            const config = syncs[syncId] || {};
            $bar.find('.inline-sync-title').text(config.title + ' — ' + config.i18n.complete);

            // Summary
            const total = totals.created + totals.updated + totals.skipped + totals.failed;
            const parts = [];

            if (totals.created > 0) parts.push(totals.created + ' ' + config.i18n.created);
            if (totals.updated > 0) parts.push(totals.updated + ' ' + config.i18n.updated);
            if (totals.skipped > 0) parts.push(totals.skipped + ' ' + config.i18n.skipped);

            let html = '<span>' + total + ' ' + config.i18n.items + ' ' + config.i18n.synced;
            if (parts.length > 0) {
                html += ' — ' + parts.join(', ');
            }
            html += '.</span>';

            // Errors
            if (totals.failed > 0) {
                html += '<div class="inline-sync-error-summary">';
                html += totals.failed + ' ' + config.i18n.failed + '.';

                const show = totals.errors.slice(0, 3);
                if (show.length > 0) {
                    html += ' ' + this._esc(show.join('; '));
                    if (totals.errors.length > 3) {
                        html += '...';
                    }
                }
                html += '</div>';
            }

            // Auto-reload message (only if reloading)
            if (config.reload_on_complete !== false) {
                html += '<span class="inline-sync-reloading">' + config.i18n.reloading + '</span>';
            }

            $bar.find('.inline-sync-result').html(html).show();

            this._enableTrigger(syncId);

            $(document).trigger('inline-sync:complete', [syncId, totals]);

            // Auto-reload after brief delay so user can read the summary
            if (config.reload_on_complete !== false) {
                setTimeout(function () {
                    window.location.reload();
                }, 1500);
            }
        },

        /**
         * Show cancelled state.
         *
         * @param {jQuery} $bar   Bar element.
         * @param {string} syncId Sync ID.
         * @private
         */
        _showCancelled: function ($bar, syncId) {
            $bar.addClass('is-error');

            const config = syncs[syncId] || {};
            $bar.find('.inline-sync-title').text(config.title);

            $bar.find('.inline-sync-result')
                .html(
                    '<span>' + config.i18n.cancelled + '</span>' +
                    '<button type="button" class="inline-sync-dismiss">' + config.i18n.dismiss + '</button>'
                )
                .show();

            this._enableTrigger(syncId);

            $(document).trigger('inline-sync:cancelled', [syncId]);
        },

        /**
         * Show error state.
         *
         * @param {jQuery} $bar    Bar element.
         * @param {string} syncId  Sync ID.
         * @param {string} message Error message.
         * @private
         */
        _showError: function ($bar, syncId, message) {
            $bar.addClass('is-error');

            const config = syncs[syncId] || {};
            $bar.find('.inline-sync-title').text(config.title);

            $bar.find('.inline-sync-result')
                .html(
                    '<span>' + this._esc(message) + '</span>' +
                    '<button type="button" class="inline-sync-dismiss">' + config.i18n.dismiss + '</button>'
                )
                .show();

            this._enableTrigger(syncId);

            $(document).trigger('inline-sync:error', [syncId, message]);
        },

        /**
         * Re-enable the trigger button.
         *
         * @param {string} syncId Sync ID.
         * @private
         */
        _enableTrigger: function (syncId) {
            $('.inline-sync-trigger[data-sync-id="' + syncId + '"]')
                .removeClass('is-syncing updating-message')
                .prop('disabled', false);
        },

        /**
         * Escape HTML entities.
         *
         * @param {string} str Input.
         * @returns {string}
         * @private
         */
        _esc: function (str) {
            if (!str) return '';
            const el = document.createElement('div');
            el.textContent = str;
            return el.innerHTML;
        }
    };

    // =========================================================================
    // Auto-bind trigger buttons
    // =========================================================================

    $(document).on('click', '.inline-sync-trigger', function (e) {
        e.preventDefault();

        const $btn = $(this);

        if ($btn.hasClass('is-syncing')) {
            return;
        }

        const syncId = $btn.data('sync-id');

        if (syncId) {
            InlineSync.start(syncId);
        }
    });

})(jQuery);
