@echo off
echo 🧹 Clearing Laravel caches...
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear

echo ⚡ Recaching Laravel...
php artisan route:cache
php artisan config:cache
php artisan view:cache

echo ✅ All caches cleared and recached successfully!
pause 