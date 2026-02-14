# WP Inline Sync

A lightweight WordPress library for inline batch sync operations with progress UI. Register sync operations that run
directly on existing admin screens — no separate pages, no stats storage, no complexity.

## Features

- **Inline progress bar** — renders above your admin table with real-time progress
- **Current item display** — shows the name of each item as it's processed
- **Auto-binding buttons** — output a trigger button, the JS handles everything
- **Single REST endpoint** — one endpoint serves all registered syncs
- **Cursor-based pagination** — works with any API that supports cursor pagination
- **Cancel support** — users can cancel mid-sync
- **Dismissable notices** — completion summary disappears on click or page refresh
- **No persistence** — nothing stored in the database, refresh and it's gone

## Requirements

- PHP 8.1+
- WordPress 6.0+

## Installation

```bash
composer require arraypress/wp-inline-sync
```

## Quick Start

### 1. Register a sync

```php
register_sync( 'stripe_prices', [
    'hook_suffix'      => 'toplevel_page_sugarcart',
    'title'            => __( 'Sync Prices', 'my-plugin' ),
    'button_label'     => __( 'Sync from Stripe', 'my-plugin' ),
    'container'        => '.wp-list-table',
    'data_callback'    => 'MyPlugin\fetch_prices',
    'process_callback' => 'MyPlugin\process_price',
    'name_callback'    => fn( $item ) => $item->product->name ?? $item->id,
] );
```

### 2. Output the trigger button

```php
// In your admin screen
render_sync_button( 'stripe_prices' );
```

That's it. The button renders with data attributes, the JS auto-binds on click, and the progress bar appears above your
table.

## Configuration

| Key                | Type             | Default            | Description                                              |
|--------------------|------------------|--------------------|----------------------------------------------------------|
| `hook_suffix`      | `string\|array`  | `''`               | Admin screen hook suffix(es) where assets should load.   |
| `capability`       | `string`         | `'manage_options'` | Required user capability.                                |
| `title`            | `string`         | `''`               | Display title shown in the progress bar header.          |
| `button_label`     | `string`         | `'Sync'`           | Text for the trigger button.                             |
| `button_class`     | `string`         | `'button'`         | CSS class(es) for the trigger button.                    |
| `container`        | `string`         | `'.wp-list-table'` | CSS selector — progress bar inserts before this element. |
| `data_callback`    | `callable`       | `null`             | Fetches items. Receives `(string $cursor)`.              |
| `process_callback` | `callable`       | `null`             | Processes one item.                                      |
| `name_callback`    | `callable\|null` | `null`             | Extracts a display name from an item.                    |

## Callbacks

### data_callback

Fetches a batch of items from your data source. Receives the cursor string (empty on first call). You control the batch
size internally.

```php
function fetch_prices( string $cursor ): array {
    $params = [
        'limit'  => 100,
        'active' => true,
    ];

    if ( $cursor ) {
        $params['starting_after'] = $cursor;
    }

    $prices = $stripe->prices->all( $params );
    $last   = end( $prices->data );

    return [
        'items'    => $prices->data,
        'has_more' => $prices->has_more,
        'cursor'   => $last ? $last->id : '',
        'total'    => null, // null if unknown
    ];
}
```

**Return format:**

| Key        | Type        | Description                                     |
|------------|-------------|-------------------------------------------------|
| `items`    | `array`     | Items to process this batch.                    |
| `has_more` | `bool`      | Whether more batches remain.                    |
| `cursor`   | `string`    | Cursor to pass on next call.                    |
| `total`    | `int\|null` | Total item count if known (enables % progress). |

### process_callback

Processes a single item. Called once per item in the batch.

```php
function process_price( object $item ): string|WP_Error {
    $existing = get_price_by_stripe_id( $item->id );

    if ( $existing ) {
        update_price( $existing->id, [ /* ... */ ] );
        return 'updated';
    }

    add_price( [ /* ... */ ] );
    return 'created';
}
```

**Return values:**

| Value       | Meaning                |
|-------------|------------------------|
| `'created'` | Item was newly created |
| `'updated'` | Item was updated       |
| `'skipped'` | Item was skipped       |
| `WP_Error`  | Item failed            |

### name_callback

Optional. Extracts a display name from an item for the progress UI. If not provided, the library guesses by checking
common fields (`name`, `title`, `label`, `email`, `id`).

```php
'name_callback' => fn( $item ) => $item->product->name ?? $item->id,
```

## Functions

### register_sync

```php
register_sync( string $id, array $config ): Sync
```

Register an inline sync operation.

### get_sync

```php
get_sync( string $id ): ?Sync
```

Retrieve a registered sync instance.

### has_sync

```php
has_sync( string $id ): bool
```

Check if a sync is registered.

### render_sync_button

```php
render_sync_button( string $id, array $args = [] ): void
```

Output a trigger button. Accepts optional overrides for `label`, `class`, and `container`.

### get_sync_button

```php
get_sync_button( string $id, array $args = [] ): string
```

Returns the button HTML instead of echoing it.

## JavaScript API

The JS auto-binds to `.inline-sync-trigger` buttons. For programmatic control:

```js
// Start a sync manually
InlineSync.start('stripe_prices');

// Cancel a running sync
InlineSync.cancel('stripe_prices');
```

### Events

Events are fired on `$(document)`:

| Event                   | Arguments         | Description    |
|-------------------------|-------------------|----------------|
| `inline-sync:complete`  | `syncId, totals`  | Sync finished  |
| `inline-sync:cancelled` | `syncId`          | User cancelled |
| `inline-sync:error`     | `syncId, message` | Sync failed    |

```js
$(document).on('inline-sync:complete', function (e, syncId, totals) {
    if (syncId === 'stripe_prices') {
        // Reload the table
        location.reload();
    }
});
```

## Multiple Syncs

Register each sync separately. They can target the same screen:

```php
register_sync( 'stripe_prices', [
    'hook_suffix'      => 'toplevel_page_my-plugin',
    'title'            => 'Sync Prices',
    'button_label'     => 'Sync Prices',
    'data_callback'    => 'fetch_prices',
    'process_callback' => 'process_price',
] );

register_sync( 'stripe_customers', [
    'hook_suffix'      => 'my-plugin_page_customers',
    'title'            => 'Sync Customers',
    'button_label'     => 'Sync Customers',
    'data_callback'    => 'fetch_customers',
    'process_callback' => 'process_customer',
] );
```

## Progress Bar Behavior

**During sync:** Blue left border, progress track with fill, item count, current item name, cancel button.

**On completion:** Green left border, summary line (e.g., "120 items synced — 3 created, 115 updated, 2 skipped"),
dismiss button. First 3 error messages shown inline if any items failed.

**On error/cancel:** Red left border, error message or "Sync cancelled", dismiss button.

**On dismiss or page refresh:** Gone. Nothing persisted.

## License

GPL-2.0-or-later