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

    if (typeof InlineSyncConfig === 'undefined') {
        return;
    }

    const {restUrl, restNonce, syncs, i18n} = InlineSyncConfig;

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
            $trigger.addClass('is-syncing').prop('disabled', true);

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
            $bar.find('.inline-sync-title').text(config.title + ' — ' + i18n.fetching);

            $.ajax({
                url: restUrl + 'fetch',
                method: 'POST',
                headers: {'X-WP-Nonce': restNonce},
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
                $bar.find('.inline-sync-title').text(config.title + ' — ' + i18n.syncing);

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
                const msg = xhr.responseJSON?.message || i18n.syncFailed;
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
            if (cancelled[syncId]) {
                this._showCancelled($bar, syncId);
                return;
            }

            $.ajax({
                url: restUrl + 'process',
                method: 'POST',
                headers: {'X-WP-Nonce': restNonce},
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
                this._updateProgress($bar, totals, response.items);

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
                const msg = xhr.responseJSON?.message || i18n.syncFailed;
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
            const self = this;

            return $([
                '<div class="inline-sync-bar" data-sync-id="' + this._esc(syncId) + '">',
                '  <div class="inline-sync-header">',
                '    <div class="inline-sync-title"></div>',
                '    <button type="button" class="inline-sync-cancel">' + i18n.cancel + '</button>',
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
                if (confirm(i18n.confirmCancel)) {
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
            $bar.find('.inline-sync-title').text(config.title + ' — ' + i18n.fetching);
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
        _updateProgress: function ($bar, totals, items) {
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
                ? totals.processed + ' ' + i18n.of + ' ' + displayTotal
                : totals.processed + ' ' + i18n.items;
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
            $bar.find('.inline-sync-title').text(config.title + ' — ' + i18n.complete);

            // Summary
            const total = totals.created + totals.updated + totals.skipped + totals.failed;
            const parts = [];

            if (totals.created > 0) parts.push(totals.created + ' ' + i18n.created);
            if (totals.updated > 0) parts.push(totals.updated + ' ' + i18n.updated);
            if (totals.skipped > 0) parts.push(totals.skipped + ' ' + i18n.skipped);

            let html = '<span>' + total + ' ' + i18n.items + ' synced';
            if (parts.length > 0) {
                html += ' — ' + parts.join(', ');
            }
            html += '.</span>';

            // Errors
            if (totals.failed > 0) {
                html += '<div class="inline-sync-error-summary">';
                html += totals.failed + ' ' + i18n.failed + '.';

                const show = totals.errors.slice(0, 3);
                if (show.length > 0) {
                    html += ' ' + this._esc(show.join('; '));
                    if (totals.errors.length > 3) {
                        html += '...';
                    }
                }
                html += '</div>';
            }

            html += '<button type="button" class="inline-sync-dismiss">' + i18n.dismiss + '</button>';

            $bar.find('.inline-sync-result').html(html).show();

            this._enableTrigger(syncId);

            $(document).trigger('inline-sync:complete', [syncId, totals]);
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
                    '<span>' + i18n.cancelled + '</span>' +
                    '<button type="button" class="inline-sync-dismiss">' + i18n.dismiss + '</button>'
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
                    '<button type="button" class="inline-sync-dismiss">' + i18n.dismiss + '</button>'
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
                .removeClass('is-syncing')
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
