@echo off
REM ============================================
REM Notification System Setup Script
REM ============================================
REM This script sets up the notification system:
REM 1. Runs migrations
REM 2. Creates queue table (if needed)
REM 3. Sets up queue configuration

echo.
echo ============================================
echo Notification System Setup
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

echo Step 1: Running migrations...
echo.
php artisan migrate
if errorlevel 1 (
    echo ERROR: Migration failed!
    pause
    exit /b 1
)

echo.
echo Step 2: Checking queue configuration...
echo.

REM Check if queue table exists
php artisan migrate:status | findstr /C:"jobs" >nul
if errorlevel 1 (
    echo Queue table not found. Creating it...
    php artisan queue:table
    php artisan migrate
    echo Queue table created.
) else (
    echo Queue table already exists.
)

echo.
echo Step 3: Setting queue connection...
echo.

REM Check current queue connection
for /f "tokens=2 delims==" %%a in ('findstr /C:"QUEUE_CONNECTION" .env 2^>nul') do set CURRENT_QUEUE=%%a

if "%CURRENT_QUEUE%"=="" (
    echo QUEUE_CONNECTION not found in .env
    echo.
    echo Choose queue connection:
    echo 1. database (recommended - requires queue worker)
    echo 2. sync (immediate - no queue worker needed)
    echo.
    set /p QUEUE_CHOICE="Enter choice (1 or 2): "
    
    if "!QUEUE_CHOICE!"=="1" (
        echo QUEUE_CONNECTION=database >> .env
        echo Set to database mode.
    ) else (
        echo QUEUE_CONNECTION=sync >> .env
        echo Set to sync mode.
    )
) else (
    echo Current QUEUE_CONNECTION: %CURRENT_QUEUE%
)

echo.
echo ============================================
echo Setup Complete!
echo ============================================
echo.

REM Check queue connection
for /f "tokens=2 delims==" %%a in ('findstr /C:"QUEUE_CONNECTION" .env') do set FINAL_QUEUE=%%a

if "%FINAL_QUEUE%"=="database" (
    echo IMPORTANT: You need to run the queue worker!
    echo Use: run-notification-worker.bat
    echo.
) else (
    echo Notifications will be processed immediately (sync mode).
    echo No queue worker needed.
    echo.
)

echo Next steps:
echo 1. Make sure users have 'admin' or 'manager' roles
echo 2. Test by creating a sale or updating product stock
echo 3. Check notifications in the bell icon (top right)
echo.
pause




