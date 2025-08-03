<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;

class UpdateController extends Controller
{
    public function checkForUpdates()
    {
        try {
            // Get current commit hash
            $currentCommit = trim(shell_exec('git rev-parse HEAD'));
            
            // Get current branch
            $currentBranch = trim(shell_exec('git branch --show-current'));
            
            // Fetch latest changes without merging
            shell_exec('git fetch origin');
            
            // Get latest commit hash from remote
            $latestCommit = trim(shell_exec("git rev-parse origin/{$currentBranch}"));
            
            // Check if there are any uncommitted changes
            $hasUncommittedChanges = !empty(trim(shell_exec('git status --porcelain')));
            
            // Check if migrations need to be run
            $hasPendingMigrations = $this->hasPendingMigrations();
            
            return response()->json([
                'current_commit' => substr($currentCommit, 0, 8),
                'latest_commit' => substr($latestCommit, 0, 8),
                'current_branch' => $currentBranch,
                'update_available' => $currentCommit !== $latestCommit,
                'has_uncommitted_changes' => $hasUncommittedChanges,
                'pending_migrations' => $hasPendingMigrations,
                'current_version' => $this->getCurrentVersion(),
                'latest_version' => $this->getLatestVersion()
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking for updates: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to check for updates',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function performUpdate(Request $request)
    {
        try {
            $results = [];
            
            // Step 1: Frontend Update (if requested)
            if ($request->get('update_frontend', true)) {
                $results['frontend'] = $this->updateFrontend();
            }
            
            // Step 2: Backend Update (if requested)
            if ($request->get('update_backend', true)) {
                $results['backend'] = $this->updateBackend();
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Update completed successfully',
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            Log::error('Update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Update failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function updateFrontend()
    {
        $results = [];
        
        // Step 1: Git stash (save uncommitted changes)
        $stashOutput = shell_exec('git stash push -m "Auto stash before update"');
        $results['stash'] = $stashOutput;
        
        // Step 2: Git pull
        $pullOutput = shell_exec('git pull origin ' . trim(shell_exec('git branch --show-current')));
        $results['pull'] = $pullOutput;
        
        // Step 3: Install/update npm dependencies if package.json changed
        if (File::exists(base_path('package.json'))) {
            $npmInstallOutput = shell_exec('npm install 2>&1');
            $results['npm_install'] = $npmInstallOutput;
            
            // Step 4: Build frontend
            $buildOutput = shell_exec('npm run build 2>&1');
            $results['build'] = $buildOutput;
        }
        
        return $results;
    }

    private function updateBackend()
    {
        $results = [];
        
        // Step 1: Git pull (if not already done in frontend)
        $pullOutput = shell_exec('git pull origin ' . trim(shell_exec('git branch --show-current')));
        $results['pull'] = $pullOutput;
        
        // Step 2: Install/update composer dependencies if composer.json changed
        $composerOutput = shell_exec('composer install --optimize-autoloader --no-dev 2>&1');
        $results['composer'] = $composerOutput;
        
        // Step 3: Run migrations if any exist
        if ($this->hasPendingMigrations()) {
            Artisan::call('migrate', ['--force' => true]);
            $results['migrations'] = Artisan::output();
        }
        
        // Step 4: Clear and recache
        $results['cache_clear'] = $this->clearAndRecache();
        
        return $results;
    }

    private function hasPendingMigrations()
    {
        try {
            Artisan::call('migrate:status');
            $output = Artisan::output();
            return strpos($output, 'Pending') !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function clearAndRecache()
    {
        $results = [];
        
        // Clear caches
        Artisan::call('route:clear');
        $results['route_clear'] = Artisan::output();
        
        Artisan::call('config:clear');
        $results['config_clear'] = Artisan::output();
        
        Artisan::call('cache:clear');
        $results['cache_clear'] = Artisan::output();
        
        Artisan::call('view:clear');
        $results['view_clear'] = Artisan::output();
        
        // Recache
        Artisan::call('route:cache');
        $results['route_cache'] = Artisan::output();
        
        Artisan::call('config:cache');
        $results['config_cache'] = Artisan::output();
        
        Artisan::call('view:cache');
        $results['view_cache'] = Artisan::output();
        
        return $results;
    }

    private function getCurrentVersion()
    {
        // Try to get version from package.json
        if (File::exists(base_path('package.json'))) {
            $packageJson = json_decode(File::get(base_path('package.json')), true);
            if (isset($packageJson['version'])) {
                return $packageJson['version'];
            }
        }
        
        // Fallback to git commit
        return substr(trim(shell_exec('git rev-parse HEAD')), 0, 8);
    }

    private function getLatestVersion()
    {
        try {
            // Fetch latest changes
            shell_exec('git fetch origin');
            
            // Get latest commit from remote
            $branch = trim(shell_exec('git branch --show-current'));
            return substr(trim(shell_exec("git rev-parse origin/{$branch}")), 0, 8);
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    public function showVersionPage()
    {
        $versionInfo = $this->checkForUpdates()->getData(true);
        return view('version', compact('versionInfo'));
    }
}