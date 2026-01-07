@echo off
REM ============================================
REM Laravel Queue Worker for Notifications
REM ============================================
REM This script runs the Laravel queue worker to process notifications
REM Make sure to run migrations first: php artisan migrate

echo.
echo ============================================
echo Starting Notification Queue Worker...
echo ============================================
echo.

REM Change to the Laravel directory
cd /d "%~dp0"

REM Check if artisan exists
if not exist "artisan" (
    echo ERROR: artisan file not found!
    echo Please make sure you're running this from the Laravel root directory.
    pause
    exit /b 1
)

REM Check if .env exists
if not exist ".env" (
    echo WARNING: .env file not found!
    echo Make sure your environment is configured.
    echo.
)

REM Display current directory
echo Current directory: %CD%
echo.

REM Check queue connection
echo Checking queue configuration...
php artisan config:show queue.default
echo.

REM Start the queue worker
echo Starting queue worker...
echo Press Ctrl+C to stop the worker
echo.
echo ============================================
echo.

REM Run queue worker with auto-restart on code changes
php artisan queue:work --tries=3 --timeout=90 --memory=128

REM If worker stops, show message
echo.
echo ============================================
echo Queue worker stopped.
echo ============================================
pause




