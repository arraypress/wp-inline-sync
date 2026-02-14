/**
 * Inline Sync Controller
 *
 * Auto-binds to `.inline-sync-trigger` buttons via data attributes.
 * Manages the batch loop, progress bar, current item name display,
 * and dismissable completion notice.
 *
 * No stats storage, no history, no error logs. Refresh and it's gone.
 *
 * Data attributes on trigger buttons:
 *   data-sync-id    — Matches the registered sync ID.
 *   data-container  — CSS selector for the element the bar inserts before.
 *
 * Public API (for programmatic use):
 *   InlineSync.start( syncId )
 *   InlineSync.cancel( syncId )
 *
 * Events fired on $(document):
 *   'inline-sync:complete'  — (event, syncId, totals)
 *   'inline-sync:cancelled' — (event, syncId)
 *   'inline-sync:error'     — (event, syncId, message)
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
         * Creates or resets the progress bar above the container,
         * disables the trigger button, and begins batch processing.
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

            // Start batch loop
            this._processBatch($bar, syncId, '', {
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
        // Internal
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
            $bar.find('.inline-sync-title').text(config.title + ' — ' + i18n.syncing);
            $bar.find('.inline-sync-fill').css('width', '0%');
            $bar.find('.inline-sync-count').text('');
            $bar.find('.inline-sync-current').text('');
            $bar.find('.inline-sync-result').empty().hide();
            $bar.find('.inline-sync-cancel').show();
            $bar.find('.inline-sync-progress').show();
            $bar.find('.inline-sync-status').show();
        },

        /**
         * Process a single batch and recurse.
         *
         * @param {jQuery} $bar   Bar element.
         * @param {string} syncId Sync ID.
         * @param {string} cursor Pagination cursor.
         * @param {object} totals Running totals.
         * @private
         */
        _processBatch: function ($bar, syncId, cursor, totals) {
            if (cancelled[syncId]) {
                this._showCancelled($bar, syncId);
                return;
            }

            $.ajax({
                url: restUrl + 'batch',
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

                // Accumulate
                totals.created += response.created || 0;
                totals.updated += response.updated || 0;
                totals.skipped += response.skipped || 0;
                totals.failed += response.failed || 0;
                totals.processed += response.processed || 0;

                if (response.total) {
                    totals.total = response.total;
                }

                // Collect errors
                if (response.items) {
                    response.items.forEach(function (item) {
                        if (item.status === 'failed' && item.error) {
                            totals.errors.push(item.name + ': ' + item.error);
                        }
                    });
                }

                // Update progress
                this._updateProgress($bar, totals, response.items);

                if (response.has_more) {
                    this._processBatch($bar, syncId, response.cursor, totals);
                } else {
                    this._showComplete($bar, syncId, totals);
                }

            }).fail((xhr) => {
                const msg = xhr.responseJSON?.message || i18n.syncFailed;
                this._showError($bar, syncId, msg);
            });
        },

        /**
         * Update progress bar and current item.
         *
         * @param {jQuery} $bar   Bar element.
         * @param {object} totals Running totals.
         * @param {array}  items  Items from current batch.
         * @private
         */
        _updateProgress: function ($bar, totals, items) {
            // Percentage
            let pct = 0;
            if (totals.total && totals.total > 0) {
                pct = Math.min(100, Math.round((totals.processed / totals.total) * 100));
            }
            $bar.find('.inline-sync-fill').css('width', pct + '%');

            // Count
            const countText = totals.total
                ? totals.processed + ' ' + i18n.of + ' ' + totals.total
                : totals.processed + ' ' + i18n.items;
            $bar.find('.inline-sync-count').text(countText);

            // Current item name
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
         * Re-enable the trigger button after sync finishes.
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
