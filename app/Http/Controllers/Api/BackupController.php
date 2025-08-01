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
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port', 3306);

        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "backup_{$database}_{$timestamp}.sql";
        $backupPath = storage_path("app/backups/{$filename}");

        // Ensure backups directory exists
        Storage::makeDirectory('backups');

        // Build mysqldump command
        $command = "C:\\xampp\\mysql\\bin\\mysqldump.exe";
        
        if ($host) {
            $command .= " -h {$host}";
        }
        
        if ($port) {
            $command .= " -P {$port}";
        }
        
        $command .= " -u {$username}";
        
        if ($password) {
            $command .= " -p{$password}";
        }

        // Add options based on user preferences
        if (!$includeData) {
            $command .= " --no-data";
        }
        
        if (!$includeStructure) {
            $command .= " --no-create-info";
        }

        $command .= " {$database} > \"{$backupPath}\"";

        // Execute the command
        $output = [];
        $returnCode = 0;
        exec($command . " 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('mysqldump failed: ' . implode("\n", $output));
        }

        // Store backup metadata
        $metadata = [
            'filename' => $filename,
            'description' => $description,
            'size' => Storage::size("backups/{$filename}"),
            'created_at' => now()->toISOString(),
            'include_data' => $includeData,
            'include_structure' => $includeStructure,
        ];

        Storage::put("backups/{$filename}.meta", json_encode($metadata));

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
        usort($backups, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
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