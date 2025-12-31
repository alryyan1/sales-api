@echo off
REM ============================================
REM Laravel Queue Worker (Sync Mode)
REM ============================================
REM This script runs queue worker in sync mode (no queue needed)
REM Use this if you don't want to run a separate queue worker process

echo.
echo ============================================
echo Setting Queue to Sync Mode...
echo ============================================
echo.

REM Change to the Laravel directory
cd /d "%~dp0"

REM Check if artisan exists
if not exist "artisan" (
    echo ERROR: artisan file not found!
    pause
    exit /b 1
)

REM Check if .env exists
if not exist ".env" (
    echo WARNING: .env file not found!
    echo Creating .env from .env.example...
    copy .env.example .env
    echo Please configure your .env file.
    pause
)

echo Setting QUEUE_CONNECTION=sync in .env...
echo.

REM Use PowerShell to update .env file
powershell -Command "(Get-Content .env) -replace 'QUEUE_CONNECTION=.*', 'QUEUE_CONNECTION=sync' | Set-Content .env"

REM If QUEUE_CONNECTION doesn't exist, add it
findstr /C:"QUEUE_CONNECTION" .env >nul
if errorlevel 1 (
    echo QUEUE_CONNECTION=sync >> .env
    echo Added QUEUE_CONNECTION=sync to .env
) else (
    echo Updated QUEUE_CONNECTION to sync in .env
)

echo.
echo ============================================
echo Queue is now set to sync mode.
echo Notifications will be processed immediately.
echo ============================================
echo.
pause

