# One Care Database Migration

This document explains how to migrate products from the `one_care` database to the current sales system.

## Prerequisites

1. **Database Connection**: Ensure you have access to the `one_care` database
2. **Environment Variables**: Configure the database connection in your `.env` file

## Configuration

### 1. Environment Variables

Add the following variables to your `.env` file:

```env
# One Care Database Configuration
DB_HOST_ONECARE=127.0.0.1
DB_PORT_ONECARE=3306
DB_DATABASE_ONECARE=one_care
DB_USERNAME_ONECARE=your_username
DB_PASSWORD_ONECARE=your_password
```

### 2. Database Connection

The `one_care` database connection is already configured in `config/database.php`:

```php
'one_care' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST_ONECARE', '127.0.0.1'),
    'port' => env('DB_PORT_ONECARE', '3306'),
    'database' => env('DB_DATABASE_ONECARE', 'one_care'),
    'username' => env('DB_USERNAME_ONECARE', 'forge'),
    'password' => env('DB_PASSWORD_ONECARE', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
],
```

## Running the Migration

### 1. Dry Run (Recommended First Step)

To see what would be migrated without actually doing it:

```bash
php artisan migrate:one-care-products --dry-run
```

This will show you:
- Total items found in the one_care database
- Which products would be migrated
- Any items that would be skipped (duplicates, empty names, etc.)

### 2. Actual Migration

To perform the actual migration:

```bash
php artisan migrate:one-care-products
```

## Field Mapping

The migration maps the following fields from the `one_care.items` table to the current `products` table:

| One Care Field | Current Field | Notes |
|----------------|---------------|-------|
| `market_name` | `name` | Primary product name |
| `name` | `name` | Fallback if market_name is empty |
| `sc_name` | `scientific_name` | Scientific name |
| `barcode` | `sku` | Stock keeping unit |
| Multiple fields | `description` | Combined description from various fields |
| `drug_category_id` | `category_id` | Mapped to categories table |
| `unit` | `sellable_unit_id` | Mapped to units table |
| `pack_size` | `units_per_stocking_unit` | Number of sellable units per stocking unit |
| `initial_balance` | `stock_quantity` | Initial stock quantity |
| `require_amount` | `stock_alert_level` | Stock alert threshold |

## Description Field

The description field combines information from multiple one_care fields:
- Scientific Name (sc_name)
- Active ingredients (active1, active2, active3)
- Batch information
- Pack size
- Strips count
- Tests count
- Expiry date

## Units and Categories

### Default Units Created

The migration automatically creates these default units if they don't exist:

**Sellable Units:**
- Piece
- Item
- Bottle
- Tablet
- Capsule
- Ampoule
- Vial

**Stocking Units:**
- Box
- Carton
- Pack

### Categories

- Categories are mapped from the `drug_categories` table in one_care
- If a category doesn't exist, it's created automatically
- If the drug_categories table is not accessible, products are assigned to a "General" category

## Duplicate Handling

The migration skips products that already exist based on:
1. Product name (market_name or name)
2. SKU (barcode)

## Error Handling

- **Database Connection**: The command will fail if it cannot connect to the one_care database
- **Missing Categories**: If drug_categories table is not accessible, products are assigned to "General" category
- **Invalid Data**: Items with empty names are skipped
- **Duplicate Products**: Existing products are skipped to prevent conflicts

## Rollback

To rollback the migration (delete migrated products):

```bash
# Delete products with generated SKUs (SKU-*)
php artisan tinker
>>> App\Models\Product::where('sku', 'like', 'SKU-%')->delete();
```

## Monitoring

The command provides detailed output including:
- Total items processed
- Number of products migrated
- Number of items skipped
- Progress updates during migration

## Troubleshooting

### Connection Issues
- Verify database credentials in `.env`
- Ensure the one_care database is accessible
- Check if the `items` table exists in one_care

### Missing Tables
- If `drug_categories` table doesn't exist, categories will default to "General"
- The migration will continue even if some related tables are missing

### Performance
- The migration processes items in chunks of 200 to manage memory
- For large datasets, consider running during off-peak hours

## Files Modified

1. **Migration File**: `database/integrations/2025_06_24_091511_migrate_old_items_to_products_table.php`
2. **Command**: `app/Console/Commands/MigrateOneCareProducts.php`
3. **Database Config**: `config/database.php` (added one_care connection) 