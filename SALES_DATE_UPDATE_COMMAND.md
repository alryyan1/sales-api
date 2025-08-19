# Sales Date Update Command

This command updates the `sales` table to set `sale_date` equal to `created_at` for all sales records.

## Purpose

The command ensures that all sales have their `sale_date` field synchronized with their `created_at` timestamp, which is useful for:

- Data consistency
- Reporting accuracy
- Historical data migration
- Fixing data inconsistencies

## Usage

### Dry Run (Recommended First Step)

To see what changes would be made without actually updating the database:

```bash
php artisan sales:update-date --dry-run
```

This will:
- Show the total number of sales records
- Display which records need updating
- Show a preview of the changes (first 10 records)
- Not make any actual changes to the database

### Execute the Update

To actually update the sales records:

```bash
php artisan sales:update-date
```

This will:
- Show the same preview as dry-run
- Ask for confirmation before proceeding
- Update all sales where `sale_date` differs from `created_at` date
- Show progress with a progress bar
- Provide a summary of the results
- Verify the update was successful

## What the Command Does

1. **Identifies Records**: Finds all sales where `sale_date` date is different from `created_at` date
2. **Shows Preview**: Displays a table showing the current and new values
3. **Confirms Action**: Asks for user confirmation before making changes
4. **Updates Records**: Sets `sale_date` to the date portion of `created_at`
5. **Provides Feedback**: Shows progress and results
6. **Verifies Results**: Checks that all records were updated correctly

## Example Output

```
🔍 DRY RUN MODE - No changes will be made
Starting sales date update process...
Total sales records found: 13
Sales records that need updating: 13

📋 Preview of changes:
+----+-------------------+--------------------+---------------+
| ID | Current sale_date | Current created_at | New sale_date |
+----+-------------------+--------------------+---------------+
| 5  | 2025-08-02        | 2025-08-03         | 2025-08-03    |
| 11 | 2025-08-02        | 2025-08-03         | 2025-08-03    |
+----+-------------------+--------------------+---------------+

✅ Dry run completed. No changes were made.
```

## Safety Features

- **Dry Run Option**: Always test with `--dry-run` first
- **Confirmation Prompt**: Requires user confirmation before making changes
- **Error Handling**: Catches and reports any errors during the update
- **Verification**: Checks that the update was successful
- **Progress Tracking**: Shows progress bar for large datasets

## Database Impact

- **Target Table**: `sales`
- **Fields Updated**: `sale_date` only
- **Update Logic**: `sale_date = DATE(created_at)`
- **Transaction Safety**: Each update is handled individually with error catching

## Requirements

- Laravel application with `sales` table
- `sale_date` and `created_at` columns in the sales table
- Proper database permissions

## Notes

- The command only updates the date portion, not the time
- Records where `sale_date` is already equal to `created_at` date are skipped
- The command is safe to run multiple times
- All changes are logged to the console output
