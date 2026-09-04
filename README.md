# Inline Sync

Pull a few thousand records from an API into WordPress, from a button on an
admin screen, without the request timing out.

## What it does

Importing anything sizeable means batching: fetch a page, process it, report
progress, fetch the next — and doing that from an admin screen means a REST
endpoint, a nonce, a JavaScript loop, a progress bar and somewhere to keep
the cursor between calls.

This is that loop, written once. You supply two callbacks — one that fetches a
page, one that handles a single record — and the batching, the progress UI and
the error reporting are handled.

## Features

* Import in batches from a button, without a request that can time out
* Show progress as it goes, with the name of the record being worked on
* Resume from a cursor, so a paginated API is walked properly
* Report a failure on one record without abandoning the rest of the run
* Put the button on one admin screen, rather than everywhere
* Keep two prefixed copies of the library from fighting over one endpoint

## Installation

```bash
composer require arraypress/wp-inline-sync
```

## Quick start

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

Then, on the screen itself:

```php
render_sync_button( 'stripe_prices' );
```

`data_callback` is handed a cursor and returns a page plus the next one.
`process_callback` gets one record at a time and does whatever the import
means — the loop between them is not yours to write.

Register on `init`, not `admin_init`. The button is an admin thing, but the
work happens over REST, and `admin_init` does not run on a REST request — a
sync registered there has a button and no endpoint behind it.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
