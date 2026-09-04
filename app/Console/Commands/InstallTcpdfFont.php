<?php

namespace App\Console\Commands;

use App\Services\Pdf\TcpdfFontInstaller;
use Illuminate\Console\Command;

class InstallTcpdfFont extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tcpdf:install-font {font=arial : Font name to install (without extension)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate TCPDF font definition files from a TTF placed in public/ (e.g. public/arial.ttf)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $font = $this->argument('font');

        if (!class_exists(\TCPDF::class)) {
            $this->error('TCPDF is not installed. Run: composer require tecnickcom/tcpdf');
            return self::FAILURE;
        }

        $this->info("Ensuring TCPDF font '{$font}' is installed...");

        $resolved = TcpdfFontInstaller::ensure($font);

        if ($resolved === $font) {
            $this->info("✅ Font '{$font}' is ready to use with \$pdf->SetFont('{$font}', ...).");
            return self::SUCCESS;
        }

        $this->error(
            "❌ Could not install '{$font}'. Place '{$font}.ttf' in " . public_path() .
            " and try again. Falling back to '{$resolved}' at runtime. See laravel.log for details."
        );

        return self::FAILURE;
    }
}
