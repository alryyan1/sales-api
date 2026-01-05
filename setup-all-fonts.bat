@echo off
REM ============================================
REM TCPDF Font Setup Script - Multiple Fonts
REM ============================================
REM This script installs multiple fonts from the public folder to TCPDF
REM It will scan the public folder for .ttf and .otf files and install them
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
echo %BLUE%  TCPDF Font Setup Script - Multiple Fonts%RESET%
echo %BLUE%============================================%RESET%
echo.

REM Check if we're in the correct directory
if not exist "artisan" (
    echo %RED%Error: artisan file not found!%RESET%
    echo Please run this script from the Laravel project root directory.
    pause
    exit /b 1
)

REM Check if public folder exists
if not exist "public" (
    echo %RED%Error: public folder not found!%RESET%
    pause
    exit /b 1
)

echo %GREEN%Scanning public folder for font files...%RESET%
echo.

REM Find all .ttf and .otf files in public folder
set "FONT_COUNT=0"
set "FONTS_INSTALLED=0"
set "FONTS_FAILED=0"

for %%F in (public\*.ttf public\*.TTF public\*.otf public\*.OTF) do (
    set /a FONT_COUNT+=1
    set "FONT_FILE=%%~nxF"
    set "FONT_NAME=%%~nF"
    
    echo %YELLOW%[!FONT_COUNT!] Found: !FONT_FILE!%RESET%
    echo %BLUE%Installing font: !FONT_NAME!...%RESET%
    
    REM Run the Laravel artisan command
    php artisan tcpdf:install-font "!FONT_NAME!"
    
    if !ERRORLEVEL! EQU 0 (
        echo %GREEN%✓ Successfully installed: !FONT_NAME!%RESET%
        set /a FONTS_INSTALLED+=1
    ) else (
        echo %RED%✗ Failed to install: !FONT_NAME!%RESET%
        set /a FONTS_FAILED+=1
    )
    echo.
)

echo.
echo %BLUE%============================================%RESET%
echo %BLUE%  Installation Summary%RESET%
echo %BLUE%============================================%RESET%
echo %GREEN%Total fonts found: %FONT_COUNT%%RESET%
echo %GREEN%Successfully installed: %FONTS_INSTALLED%%RESET%
if %FONTS_FAILED% GTR 0 (
    echo %RED%Failed: %FONTS_FAILED%%RESET%
)
echo.

if %FONT_COUNT% EQU 0 (
    echo %YELLOW%No font files found in public folder.%RESET%
    echo Please place .ttf or .otf font files in the public folder.
    echo.
)

pause



