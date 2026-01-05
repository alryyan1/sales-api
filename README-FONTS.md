# TCPDF Font Setup Guide

This guide explains how to install fonts for TCPDF in your Laravel application.

## Quick Start

### Option 1: Install Single Font

1. Place your font file (`.ttf` or `.otf`) in the `public` folder
2. Run the batch file:
   ```batch
   setup-fonts.bat arial
   ```
   Replace `arial` with your font name (without extension)

### Option 2: Install All Fonts

1. Place all your font files (`.ttf` or `.otf`) in the `public` folder
2. Run the batch file:
   ```batch
   setup-all-fonts.bat
   ```
   This will automatically find and install all fonts in the public folder

## Manual Installation

If you prefer to use the command line directly:

```bash
php artisan tcpdf:install-font arial
```

### Options

- `--type`: Font type (default: TrueTypeUnicode)
- `--encoding`: Font encoding (default: UTF-8)

Example:
```bash
php artisan tcpdf:install-font arial --type=TrueTypeUnicode --encoding=UTF-8
```

## Font File Requirements

- Font files must be placed in the `public` folder
- Supported formats: `.ttf`, `.TTF`, `.otf`, `.OTF`
- Font name is case-insensitive (e.g., `arial.ttf` = `ARIAL.ttf`)

## Common Fonts

Popular fonts you might want to install:

- **Arial**: `setup-fonts.bat arial`
- **Times New Roman**: `setup-fonts.bat times`
- **Courier**: `setup-fonts.bat courier`
- **Helvetica**: `setup-fonts.bat helvetica`

## Troubleshooting

### Font Not Found
- Make sure the font file is in the `public` folder
- Check the file name matches (case-insensitive)
- Verify the file extension is `.ttf` or `.otf`

### Installation Fails
- Check if TCPDF is installed: `composer require tecnickcom/tcpdf`
- Verify PHP has write permissions to the TCPDF fonts directory
- Check the Laravel logs for detailed error messages

### Font Not Working in PDF
- Clear Laravel cache: `php artisan cache:clear`
- Verify the font was installed correctly in `vendor/tecnickcom/tcpdf/fonts/`
- Check that you're using the correct font name in your code

## Using Fonts in Code

After installation, you can use the font in your TCPDF documents:

```php
$pdf->SetFont('arial', '', 12);
```

## Notes

- Fonts are installed to: `vendor/tecnickcom/tcpdf/fonts/`
- The installation creates three files: `{fontname}.php`, `{fontname}.z`, `{fontname}.ctg.z`
- Fonts are shared across all TCPDF documents in your application

