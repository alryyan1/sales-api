@echo off
echo ========================================
echo    WhatsApp Scheduler Test Script
echo ========================================
echo.

echo Testing WhatsApp Scheduler for number: 249991961111
echo.

php artisan whatsapp:test-scheduler 249991961111 --force

echo.
echo ========================================
echo Test completed!
echo ========================================
pause 