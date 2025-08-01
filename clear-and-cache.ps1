Write-Host "🧹 Clearing Laravel caches..." -ForegroundColor Green
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear

Write-Host "⚡ Recaching Laravel..." -ForegroundColor Yellow
php artisan route:cache
php artisan config:cache
php artisan view:cache

Write-Host "✅ All caches cleared and recached successfully!" -ForegroundColor Green
Read-Host "Press Enter to continue" 