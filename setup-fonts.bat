@echo off
REM ============================================
REM TCPDF Font Setup Script
REM ============================================
REM This script installs fonts from the public folder to TCPDF
REM Usage: setup-fonts.bat [font-name]
REM Example: setup-fonts.bat arial
REM ============================================

setlocal enabledelayedexpansion

REM Set colors
set "GREEN=[92m"
set "RED=[91m"
set "YELLOW=[93m"
set "BLUE=[94m"
set "RESET=[0m"

echo.
echo %BLUE%============================================%RESET%
echo %BLUE%  TCPDF Font Setup Script%RESET%
echo %BLUE%============================================%RESET%
echo.

REM Check if we're in the correct directory
if not exist "artisan" (
    echo %RED%Error: artisan file not found!%RESET%
    echo Please run this script from the Laravel project root directory.
    pause
    exit /b 1
)

REM Check if font name is provided
set "FONT_NAME=%~1"
if "%FONT_NAME%"=="" (
    echo %YELLOW%No font name provided. Using default: arial%RESET%
    set "FONT_NAME=arial"
)

echo %GREEN%Installing font: %FONT_NAME%%RESET%
echo.

REM Check if font file exists in public folder
set "FONT_FOUND=0"
if exist "public\%FONT_NAME%.ttf" (
    set "FONT_FOUND=1"
    set "FONT_FILE=%FONT_NAME%.ttf"
) else if exist "public\%FONT_NAME%.TTF" (
    set "FONT_FOUND=1"
    set "FONT_FILE=%FONT_NAME%.TTF"
) else if exist "public\%FONT_NAME%.otf" (
    set "FONT_FOUND=1"
    set "FONT_FILE=%FONT_NAME%.otf"
)

if !FONT_FOUND!==0 (
    echo %RED%Error: Font file not found in public folder!%RESET%
    echo.
    echo Looking for:
    echo   - public\%FONT_NAME%.ttf
    echo   - public\%FONT_NAME%.TTF
    echo   - public\%FONT_NAME%.otf
    echo.
    echo Please place the font file in the public folder and try again.
    pause
    exit /b 1
)

echo %GREEN%Found font file: public\!FONT_FILE!%RESET%
echo.

REM Run the Laravel artisan command
php artisan tcpdf:install-font %FONT_NAME%

if %ERRORLEVEL% EQU 0 (
    echo.
    echo %GREEN%============================================%RESET%
    echo %GREEN%  Font installation completed successfully!%RESET%
    echo %GREEN%============================================%RESET%
    echo.
) else (
    echo.
    echo %RED%============================================%RESET%
    echo %RED%  Font installation failed!%RESET%
    echo %RED%============================================%RESET%
    echo.
    pause
    exit /b 1
)

pause



