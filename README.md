# WooCommerce SoundImports ERP & Scraper Sync Plugin

A high-performance WordPress / WooCommerce synchronization plugin engineered to connect your WooCommerce store with the **SoundImports Live Scraper FastAPI Service**. Features batch importing, category & brand mapping, inventory management, single SKU lookup, remote scraping controls, and automated background sync queues.

---

## Key Features

- **Live FastAPI Backend Integration**: Direct connection to the SoundImports scraper service deployed on Railway.
- **Configurable Test Sync Limits**: Pre-configured **50-product default testing limit** (options for 50, 100, 250, 500, or All products) to safely verify mappings, prices, and stock levels before running full catalog syncs.
- **Integrated API Endpoints**:
  - `GET /health` - Server health status diagnostic.
  - `GET /api/stats` - Live scraper catalog statistics (total products, categories, brands, last sync timestamp).
  - `POST /api/sync` - Trigger remote scraper execution jobs directly from the admin panel (full vs incremental mode).
  - `GET /api/categories` - Fetch hierarchical product categories tree.
  - `GET /api/brands` - Fetch manufacturer/brand list.
  - `GET /api/products` - Fetch paginated product items with pricing, attributes, stock status, and CDN image galleries.
  - `GET /api/product/sku/{sku}` - Single product lookup and instant import by SKU.
  - `GET /api/products/changed?since={date}` - Incremental product update tracking.
- **Single SKU Quick Import Tool**: Test or update any single product instantly by entering its SKU (e.g. `230-212`).
- **Incremental Date Sync**: Queue product updates modified since a specific calendar date.
- **Background Queue Engine**: Asynchronous chunk processing table with automatic retry attempts for failed records to prevent PHP timeout issues.
- **Activity & Error Logging**: Filterable database logs (`wc_erp_sync_logs`) with full JSON context inspection.
- **Mock Sandbox Mode (DummyClient)**: Includes a simulated client adapter for local testing and schema validation without external server requests.

---

## Directory Structure

```text
218 sound import/
├── assets/
│   ├── css/                  # Modern SaaS Admin UI styles & responsive CSS
│   └── js/
│       └── admin-dashboard.js # Admin dashboard AJAX controller scripts
├── src/
│   ├── API/
│   │   ├── AbstractClient.php      # Base HTTP client request handler
│   │   ├── ClientFactory.php       # Dynamic adapter factory (SoundImports vs Dummy)
│   │   ├── ClientInterface.php     # Client interface contract definitions
│   │   ├── DummyClient.php         # Simulated sandbox client adapter
│   │   ├── SettingsRegistry.php    # Plugin settings schema & sanitizer
│   │   └── SoundImportsClient.php  # Live FastAPI scraper client implementation
│   ├── Admin/
│   │   ├── AJAXHandler.php         # Nonced WordPress AJAX routing endpoints
│   │   ├── DashController.php      # Dashboard analytics & KPI computations
│   │   └── Menu.php                # WP Admin menu & subnav tabs registration
│   ├── Database/
│   │   ├── HistoryTable.php        # Job history tracking DB table
│   │   ├── LogTable.php            # Activity & error logs DB table
│   │   ├── MappingTable.php        # Entity mapping indices DB table
│   │   ├── Migrator.php           # DB table creation & migration schema
│   │   └── QueueTable.php          # Asynchronous sync queue DB table
│   ├── Logger/
│   │   └── Logger.php              # Standardized logger abstraction
│   ├── Modules/
│   │   ├── BaseModule.php          # Abstract base module implementation
│   │   ├── Brand/                  # Brand mapping module
│   │   ├── Category/               # Category taxonomy import module
│   │   ├── Image/                  # Media library CDN image downloader module
│   │   ├── Product/                # WooCommerce product import/export module
│   │   ├── Registry.php            # Module registry manager
│   │   └── Vendor/                 # Vendor info mapping module
│   ├── Sync/
│   │   ├── BatchProcessor.php      # Batch queue runner
│   │   ├── Engine.php              # Full & incremental sync orchestrator
│   │   ├── QueueManager.php        # Task queue manager
│   │   └── Scheduler.php          # WordPress WP-Cron scheduler
│   └── WooCommerce/
│       ├── ProductMapper.php       # Attribute, category, & price transformer
│       └── WooConnector.php        # WooCommerce product creation/update engine
├── templates/
│   ├── dashboard.php               # Overview KPI dashboard & health status
│   ├── logs.php                    # System activity & error log viewer
│   ├── mappings.php                # Manual entity mapping association editor
│   ├── queue.php                   # Background queue manager & retry controls
│   ├── settings.php               # API connection & sync configuration panel
│   ├── status.php                  # System diagnostics checklist
│   └── sync.php                    # Manual sync, single SKU, remote scraper trigger
├── README.md                       # Comprehensive plugin documentation
└── wc-erp-sync-framework.php       # Main WordPress plugin bootstrap file
```

---

## Installation & Setup

1. **Upload Plugin**:
   Copy the `218 sound import` directory into your WordPress plugins folder:
   `wp-content/plugins/wc-erp-sync-framework/`

2. **Activate Plugin**:
   Navigate to **WordPress Admin Panel → Plugins → Installed Plugins** and activate **WooCommerce ERP Sync Framework**.

3. **Configure API Settings**:
   - Go to **ERP Sync → Settings Panel**.
   - Under **Active ERP / API Client**, select **SoundImports Scraper API**.
   - Set **API Base URL** to your live FastAPI endpoint:
     `https://audio-status-scrapper-production.up.railway.app`
   - Click **Save Settings Options**.

4. **Verify Health Connection**:
   - Go to **ERP Sync → Dashboard**.
   - Click **Test ERP Connection**.
   - The plugin will check `/health` and verify active API connectivity.

---

## Usage Guide

### Initial Testing (50 Products Limit)
1. Go to **ERP Sync → Manual Sync**.
2. Select **50 Products (Recommended Initial Test Sync)** from the batch limit dropdown.
3. Click **Run Synchronization Now**.
4. The progress bar will track records processed in real time and display results upon completion.

### Single SKU Import Tool
1. Go to **ERP Sync → Manual Sync**.
2. Under **Sync Single Product by SKU**, enter any valid product SKU (e.g. `230-212`).
3. Click **Import SKU Product**.

### Trigger Remote FastAPI Scraper
1. Go to **ERP Sync → Manual Sync**.
2. Under **Trigger Remote FastAPI Scraper**, check *Run Incremental Scrape Only* if desired.
3. Click **Trigger Remote Scrape Job** to start a live scraping run on the backend server (`POST /api/sync`).

---

## Developer / Technical Information

- **Text Domain**: `wc-erp-sync`
- **Requires PHP**: 7.4 or higher
- **Requires WooCommerce**: 5.0 or higher
- **Custom Database Tables**:
  - `{$wpdb->prefix}wc_erp_sync_queue`
  - `{$wpdb->prefix}wc_erp_sync_mappings`
  - `{$wpdb->prefix}wc_erp_sync_logs`
  - `{$wpdb->prefix}wc_erp_sync_history`

---

## License
Proprietary / Commercial. All rights reserved.
