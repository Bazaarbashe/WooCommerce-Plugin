# BazaarBashe WooCommerce Plugin

The official BazaarBashe plugin for WordPress and WooCommerce provides a simple way to connect your WooCommerce store to the BazaarBashe platform.

With this plugin, product creation, updates, and deletion in WooCommerce can be automatically synced with BazaarBashe.

---

## Features

* Connect WooCommerce to BazaarBashe using an Access Token
* Support for BazaarBashe Store ID
* Automatic product synchronization
* Automatically create new products in BazaarBashe
* Automatically update product information
* Automatically delete products from BazaarBashe
* Product image support
* Full sync logs
* API connection status check
* Sync failure diagnostics
* Persian RTL admin interface

---

## What This Plugin Does

If your online store is built with WordPress and WooCommerce, this plugin helps you sync your products with BazaarBashe without manually adding them.

After installing and activating the plugin, whenever you:

* Add a new product in WooCommerce
* Edit an existing product
* Delete a product

the same action can automatically be applied in BazaarBashe.

---

## Requirements

To use this plugin, you need:

* WordPress
* WooCommerce
* An approved website-based store in BazaarBashe
* An Access Token from the BazaarBashe Developer Panel
* Your BazaarBashe Store ID

---

## Installation

### Method 1: Install from WordPress Admin

1. Download the plugin ZIP file.
2. Log in to your WordPress admin panel.
3. Go to **Plugins → Add New**.
4. Click **Upload Plugin**.
5. Select the plugin ZIP file and install it.
6. Activate the plugin.

### Method 2: Manual Installation

1. Upload the plugin folder to:

```text
wp-content/plugins/
```

2. Log in to your WordPress admin panel.
3. Go to **Plugins**.
4. Activate **BazaarBashe**.

---

## Plugin Setup

After activation, a new **BazaarBashe** menu will appear in your WordPress admin panel.

To configure the plugin:

1. Go to **BazaarBashe → Settings**.
2. Enter your Access Token.
3. Enter your BazaarBashe Store ID.
4. Click **Test Connection**.
5. If the connection is successful, product synchronization can be enabled.

---

## How to Get an Access Token

To get your Access Token:

1. Open the BazaarBashe Developer/Admin Panel.
2. Create a new Access Token.
3. Copy the generated token.
4. Paste it into the plugin settings page in WordPress.

---

## How to Get Your Store ID

To get your BazaarBashe Store ID:

1. Log in to your BazaarBashe account.
2. Go to:

```text
Account → Store Management
```

3. Click the copy icon next to your store ID.
4. Paste the copied Store ID into the plugin settings page.

---

## Logs and Troubleshooting

The plugin includes built-in logs and sync diagnostics.

If a product is not synced, the plugin shows the reason, such as:

* Invalid or expired token
* Wrong Store ID
* Store does not belong to the token owner
* WordPress server cannot reach the BazaarBashe API
* DNS or SSL error
* API error
* Product image upload failed
* Invalid or incomplete product data
* WooCommerce product not found

This helps store managers quickly understand what went wrong and how to fix it.

---

## Manual Sync

In addition to automatic synchronization, the plugin also supports manual sync.

You can enter a WooCommerce Product ID and run sync manually.

This is useful for testing, debugging, and re-syncing specific products.

---

## Important Notes

* This plugin is intended for website-based BazaarBashe stores only.
* Channel-based stores cannot use product API synchronization.
* Your BazaarBashe store must be approved before using product sync.
* If a product has images, the plugin uploads the images to BazaarBashe first, then sends the returned image IDs with the product data.
* If the WordPress server cannot reach the BazaarBashe API, synchronization will fail and the plugin will show the related diagnostic message.

---

## Developer Notes

This plugin is built to connect WooCommerce with the BazaarBashe API.

Main project structure:

```text
admin/
api/
hooks/
models/
queue/
sync/
assets/
languages/
```

---

## Contributing

Contributions are welcome.

To contribute:

1. Fork the repository.
2. Create a new branch.
3. Make your changes.
4. Open a Pull Request.

---

## Reporting Issues

If you encounter a problem, please open a GitHub Issue and include:

* WordPress version
* WooCommerce version
* Plugin version
* Error description
* Relevant BazaarBashe plugin logs
* Steps to reproduce the issue

---

## License

This project is released under the MIT License.

---

## About BazaarBashe

BazaarBashe is a platform for registering, managing, and displaying online store products. This plugin helps WooCommerce stores sync their products with BazaarBashe automatically and efficiently.
