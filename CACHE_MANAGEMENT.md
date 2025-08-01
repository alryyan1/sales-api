# Laravel Cache Management

This document provides commands and scripts for managing Laravel caches in the sales-api project.

## Quick Cache Commands

### Clear All Caches
```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

### Recache Everything
```bash
php artisan route:cache
php artisan config:cache
php artisan view:cache
```

## Automated Scripts

### Windows (PowerShell)
```powershell
powershell -ExecutionPolicy Bypass -File clear-and-cache.ps1
```

### Windows (Batch)
```cmd
clear-and-cache.bat
```

### Linux/Mac (Bash)
```bash
chmod +x clear-and-cache.sh
./clear-and-cache.sh
```

## When to Use

Use these commands when:
- Adding new routes (like the backup routes)
- Modifying configuration files
- Experiencing caching issues
- After deploying new code
- When routes or config changes aren't reflecting

## What Each Command Does

- `route:clear` - Clears the route cache
- `config:clear` - Clears the configuration cache
- `cache:clear` - Clears the application cache
- `view:clear` - Clears the compiled Blade templates
- `route:cache` - Caches routes for faster loading
- `config:cache` - Caches configuration for faster loading
- `view:cache` - Caches Blade templates for faster loading

## Notes

- Always run clear commands before cache commands
- These commands should be run from the `sales-api` directory
- Make sure PHP and Composer are properly installed
- The scripts include visual feedback with emojis and colors 