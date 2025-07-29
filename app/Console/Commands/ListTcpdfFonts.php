<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ListTcpdfFonts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tcpdf:list-fonts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all available TCPDF fonts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get TCPDF installation path
        $tcpdfPath = $this->getTcpdfPath();
        if (!$tcpdfPath) {
            $this->error('Could not find TCPDF installation path.');
            return 1;
        }

        $fontDir = $tcpdfPath . '/fonts/';
        if (!File::exists($fontDir)) {
            $this->error("Font directory not found at: {$fontDir}");
            return 1;
        }

        $this->info("Available TCPDF fonts in: {$fontDir}");
        $this->line('');

        // Get all font files
        $fontFiles = File::files($fontDir);
        $fonts = [];

        foreach ($fontFiles as $file) {
            $filename = $file->getFilename();
            $extension = $file->getExtension();
            
            // Only show .php files (font definitions)
            if ($extension === 'php') {
                $fontName = pathinfo($filename, PATHINFO_FILENAME);
                $fonts[] = $fontName;
            }
        }

        if (empty($fonts)) {
            $this->warn('No fonts found.');
            return 0;
        }

        // Sort fonts alphabetically
        sort($fonts);

        // Display fonts in a table
        $headers = ['Font Name', 'Status'];
        $rows = [];

        foreach ($fonts as $font) {
            $phpFile = $fontDir . $font . '.php';
            $zFile = $fontDir . $font . '.z';
            $ctgFile = $fontDir . $font . '.ctg.z';

            $status = '✅ Complete';
            if (!File::exists($zFile)) {
                $status = '⚠️  Missing .z file';
            } elseif (!File::exists($ctgFile)) {
                $status = '⚠️  Missing .ctg.z file';
            }

            $rows[] = [$font, $status];
        }

        $this->table($headers, $rows);

        $this->line('');
        $this->info("Total fonts found: " . count($fonts));
        
        // Show some common fonts
        $commonFonts = ['arial', 'dejavusans', 'helvetica', 'times'];
        $this->line('');
        $this->info("Common fonts:");
        foreach ($commonFonts as $font) {
            if (in_array($font, $fonts)) {
                $this->line("  ✅ {$font}");
            } else {
                $this->line("  ❌ {$font} (not installed)");
            }
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
}
