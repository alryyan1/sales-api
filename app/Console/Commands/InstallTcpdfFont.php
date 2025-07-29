<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallTcpdfFont extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tcpdf:install-font 
                            {font=arial : Font name to install (without extension)}
                            {--type= : Font type (TrueTypeUnicode, TrueType, etc.)}
                            {--encoding= : Font encoding}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install TCPDF fonts using the tcpdf_addfont.php script';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fontName = $this->argument('font');
        $fontType = $this->option('type') ?: 'TrueTypeUnicode';
        $encoding = $this->option('encoding') ?: 'UTF-8';

        // Check if TCPDF is installed
        if (!class_exists('TCPDF')) {
            $this->error('TCPDF is not installed. Please install it first: composer require tecnickcom/tcpdf');
            return 1;
        }

        // Get TCPDF installation path
        $tcpdfPath = $this->getTcpdfPath();
        if (!$tcpdfPath) {
            $this->error('Could not find TCPDF installation path.');
            return 1;
        }

        // Check if tcpdf_addfont.php exists
        $addFontScript = $tcpdfPath . '/tools/tcpdf_addfont.php';
        if (!File::exists($addFontScript)) {
            $this->error("TCPDF font installation script not found at: {$addFontScript}");
            return 1;
        }

        // Check if font file exists in public folder (case-insensitive)
        $fontFile = $this->findFontFile($fontName);
        if (!$fontFile) {
            $this->error("Font file not found in public folder.");
            $this->info("Looking for: {$fontName}.ttf, {$fontName}.TTF, or {$fontName}.otf");
            $this->info('Please place the font file in the public folder.');
            return 1;
        }

        $this->info("Installing font: {$fontName}.ttf");
        $this->info("Font type: {$fontType}");
        $this->info("Encoding: {$encoding}");

        // Build the command
        $command = "php \"{$addFontScript}\" -b -t {$fontType} -i \"{$fontFile}\"";

        $this->info("Executing: {$command}");

        // Execute the command
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        // Display output
        foreach ($output as $line) {
            $this->line($line);
        }

        if ($returnCode === 0) {
            $this->info("✅ Font '{$fontName}' installed successfully!");
            
            // Check if font files were created
            $fontDir = $tcpdfPath . '/fonts/';
            $fontFiles = [
                "{$fontName}.php",
                "{$fontName}.z",
                "{$fontName}.ctg.z"
            ];

            $this->info("Checking created font files:");
            foreach ($fontFiles as $file) {
                if (File::exists($fontDir . $file)) {
                    $this->info("  ✅ {$file}");
                } else {
                    $this->warn("  ⚠️  {$file} (not found)");
                }
            }

            $this->info("\nFont installation completed successfully!");
            $this->info("You can now use '{$fontName}' font in your TCPDF documents.");
            
        } else {
            $this->error("❌ Font installation failed with return code: {$returnCode}");
            return 1;
        }

        return 0;
    }

    /**
     * Get TCPDF installation path
     */
    private function getTcpdfPath(): ?string
    {
        // Try to get path from composer autoload
        $vendorPath = base_path('vendor/tecnickcom/tcpdf');
        if (File::exists($vendorPath)) {
            return $vendorPath;
        }

        // Try alternative paths
        $possiblePaths = [
            base_path('vendor/tcpdf/tcpdf'),
            base_path('vendor/tecnickcom/tcpdf'),
            base_path('tcpdf'),
        ];

        foreach ($possiblePaths as $path) {
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Find a font file in the public directory, considering case-insensitive matches.
     */
    private function findFontFile(string $fontName): ?string
    {
        $publicPath = public_path();
        $fontNameLower = strtolower($fontName);

        $possibleFontFiles = [
            "{$fontName}.ttf",
            "{$fontName}.TTF",
            "{$fontName}.otf",
        ];

        foreach ($possibleFontFiles as $file) {
            $fullPath = $publicPath . '/' . $file;
            if (File::exists($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }
}
