<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BackupController extends Controller
{
    /**
     * Get backup status and list of available backups
     */
    public function index(Request $request)
    {
        try {
            $backups = $this->getBackupList();
            
            return response()->json([
                'backups' => $backups,
                'total_backups' => count($backups),
                'total_size' => $this->formatBytes($this->getTotalBackupSize($backups)),
                'last_backup' => $this->getLastBackupDate($backups),
            ]);
        } catch (\Exception $e) {
            Log::error('Backup index error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve backup information',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new database backup
     */
    public function store(Request $request)
    {
        try {
                    $request->validate([
            'description' => 'nullable|string|max:255',
            'include_data' => 'boolean',
            'include_structure' => 'boolean',
        ]);

            $description = $request->input('description', 'Manual backup');
            $includeData = $request->boolean('include_data', true);
            $includeStructure = $request->boolean('include_structure', true);

            $backupFileName = $this->createBackup($description, $includeData, $includeStructure);

            return response()->json([
                'message' => 'Backup created successfully',
                'backup_file' => $backupFileName,
                'created_at' => now()->toISOString(),
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            Log::error('Backup creation error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create backup',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download a specific backup file
     */
    public function download(Request $request, $filename)
    {
        try {
            $backupPath = 'backups/' . $filename;
            
            if (!Storage::exists($backupPath)) {
                return response()->json([
                    'message' => 'Backup file not found'
                ], Response::HTTP_NOT_FOUND);
            }

            return Storage::download($backupPath, $filename);

        } catch (\Exception $e) {
            Log::error('Backup download error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to download backup',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a specific backup file
     */
    public function destroy(Request $request, $filename)
    {
        try {
            $backupPath = 'backups/' . $filename;
            
            if (!Storage::exists($backupPath)) {
                return response()->json([
                    'message' => 'Backup file not found'
                ], Response::HTTP_NOT_FOUND);
            }

            Storage::delete($backupPath);

            return response()->json([
                'message' => 'Backup deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Backup deletion error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete backup',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get backup statistics
     */
    public function statistics(Request $request)
    {
        try {
            $backups = $this->getBackupList();
            
            $stats = [
                'total_backups' => count($backups),
                'total_size' => $this->getTotalBackupSize($backups),
                'total_size_formatted' => $this->formatBytes($this->getTotalBackupSize($backups)),
                'last_backup' => $this->getLastBackupDate($backups),
                'oldest_backup' => $this->getOldestBackupDate($backups),
                'backups_this_month' => $this->getBackupsThisMonth($backups),
                'backups_this_week' => $this->getBackupsThisWeek($backups),
                'average_backup_size' => $this->getAverageBackupSize($backups),
            ];

            return response()->json($stats);

        } catch (\Exception $e) {
            Log::error('Backup statistics error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve backup statistics',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a database backup using mysqldump
     */
    private function createBackup($description, $includeData, $includeStructure)
    {
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host     = config('database.connections.mysql.host', '127.0.0.1');
        $port     = config('database.connections.mysql.port', 3306);

        $timestamp  = now()->format('Y-m-d_H-i-s');
        $filename   = "backup_{$database}_{$timestamp}.sql";
        $backupDir  = storage_path('app/backups');
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        // Ensure backups directory exists (Storage::makeDirectory can silently fail)
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Locate mysqldump — check common XAMPP path first, then PATH
        $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (!file_exists($mysqldump)) {
            $mysqldump = 'mysqldump'; // fall back to PATH
        }

        // Build command arguments
        $args = [];
        $args[] = '-h ' . escapeshellarg($host);
        $args[] = '-P ' . escapeshellarg((string) $port);
        $args[] = '-u ' . escapeshellarg($username);
        if ($password !== '' && $password !== null) {
            // Use --password= to safely handle special characters
            $args[] = '--password=' . escapeshellarg($password);
        }
        if (!$includeData)      $args[] = '--no-data';
        if (!$includeStructure) $args[] = '--no-create-info';
        $args[] = escapeshellarg($database);

        $command = escapeshellarg($mysqldump) . ' ' . implode(' ', $args);

        // Use proc_open so we can capture stderr separately from the SQL output file
        $descriptors = [
            0 => ['pipe', 'r'],                      // stdin  (unused)
            1 => ['file', $backupPath, 'w'],          // stdout → backup file
            2 => ['pipe', 'w'],                       // stderr → captured
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \Exception('Failed to start mysqldump process. Check that mysqldump is installed and accessible.');
        }

        fclose($pipes[0]);
        $stderr     = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            if (file_exists($backupPath)) @unlink($backupPath);
            throw new \Exception("mysqldump exited with code {$returnCode}: " . trim($stderr));
        }

        // Verify the file was actually created and has content
        if (!file_exists($backupPath) || filesize($backupPath) === 0) {
            if (file_exists($backupPath)) @unlink($backupPath);
            throw new \Exception('Backup file was not created or is empty. ' . trim($stderr));
        }

        // Store backup metadata
        Storage::put("backups/{$filename}.meta", json_encode([
            'filename'           => $filename,
            'description'        => $description,
            'size'               => filesize($backupPath),
            'created_at'         => now()->toISOString(),
            'include_data'       => $includeData,
            'include_structure'  => $includeStructure,
        ]));

        Log::info("Database backup created: {$filename}");

        return $filename;
    }

    /**
     * Get list of available backups
     */
    private function getBackupList()
    {
        $backups = [];
        $files = Storage::files('backups');

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $filename = basename($file);
                $metadataFile = str_replace('.sql', '.sql.meta', $file);
                
                $metadata = [];
                if (Storage::exists($metadataFile)) {
                    $metadata = json_decode(Storage::get($metadataFile), true);
                }

                $backups[] = [
                    'filename' => $filename,
                    'size' => Storage::size($file),
                    'size_formatted' => $this->formatBytes(Storage::size($file)),
                    'created_at' => $metadata['created_at'] ?? Storage::lastModified($file),
                    'description' => $metadata['description'] ?? 'No description',
                    'include_data' => $metadata['include_data'] ?? true,
                    'include_structure' => $metadata['include_structure'] ?? true,
                ];
            }
        }

        // Sort by creation date (newest first)
        // created_at may be an ISO string or a raw Unix timestamp integer (from lastModified)
        usort($backups, function($a, $b) {
            $ta = is_numeric($a['created_at']) ? (int)$a['created_at'] : strtotime($a['created_at']);
            $tb = is_numeric($b['created_at']) ? (int)$b['created_at'] : strtotime($b['created_at']);
            return $tb - $ta;
        });

        return $backups;
    }

    /**
     * Get total size of all backups
     */
    private function getTotalBackupSize($backups)
    {
        return array_sum(array_column($backups, 'size'));
    }

    /**
     * Get last backup date
     */
    private function getLastBackupDate($backups)
    {
        return !empty($backups) ? $backups[0]['created_at'] : null;
    }

    /**
     * Get oldest backup date
     */
    private function getOldestBackupDate($backups)
    {
        return !empty($backups) ? end($backups)['created_at'] : null;
    }

    /**
     * Get backups count for this month
     */
    private function getBackupsThisMonth($backups)
    {
        $thisMonth = now()->startOfMonth();
        return count(array_filter($backups, function($backup) use ($thisMonth) {
            return Carbon::parse($backup['created_at'])->gte($thisMonth);
        }));
    }

    /**
     * Get backups count for this week
     */
    private function getBackupsThisWeek($backups)
    {
        $thisWeek = now()->startOfWeek();
        return count(array_filter($backups, function($backup) use ($thisWeek) {
            return Carbon::parse($backup['created_at'])->gte($thisWeek);
        }));
    }

    /**
     * Get average backup size
     */
    private function getAverageBackupSize($backups)
    {
        return !empty($backups) ? $this->getTotalBackupSize($backups) / count($backups) : 0;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
} 