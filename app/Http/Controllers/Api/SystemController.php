<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Auth;

class SystemController extends Controller
{
    /**
     * Get current system version and status
     */
    public function getVersion(Request $request)
    {
        $this->checkAuthorization('view-system');

        try {
            // Get current git commit hash
            $currentCommit = trim(shell_exec('git rev-parse HEAD 2>/dev/null') ?: 'unknown');
            $currentBranch = trim(shell_exec('git branch --show-current 2>/dev/null') ?: 'unknown');
            $lastCommitDate = trim(shell_exec('git log -1 --format=%cd 2>/dev/null') ?: 'unknown');
            
            // Get Laravel version
            $laravelVersion = app()->version();
            
            // Get PHP version
            $phpVersion = phpversion();
            
            // Check if there are uncommitted changes
            $hasUncommittedChanges = !empty(shell_exec('git status --porcelain 2>/dev/null'));
            
            // Get remote origin URL
            $remoteUrl = trim(shell_exec('git config --get remote.origin.url 2>/dev/null') ?: 'unknown');

            return response()->json([
                'data' => [
                    'current_commit' => $currentCommit,
                    'current_branch' => $currentBranch,
                    'last_commit_date' => $lastCommitDate,
                    'laravel_version' => $laravelVersion,
                    'php_version' => $phpVersion,
                    'has_uncommitted_changes' => $hasUncommittedChanges,
                    'remote_url' => $remoteUrl,
                    'last_check' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting system version: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to get system version information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check for updates by fetching from remote
     */
    public function checkForUpdates(Request $request)
    {
        // $this->checkAuthorization('update-system');

        try {
            // Fetch latest changes from remote
            $fetchResult = shell_exec('git fetch origin 2>&1');
            
            // Get current commit
            $currentCommit = trim(shell_exec('git rev-parse HEAD 2>/dev/null') ?: 'unknown');
            
            // Get latest commit on current branch
            $latestCommit = trim(shell_exec('git rev-parse origin/' . trim(shell_exec('git branch --show-current 2>/dev/null')) . ' 2>/dev/null') ?: 'unknown');
            
            // Check if there are updates available
            $hasUpdates = $currentCommit !== $latestCommit && $latestCommit !== 'unknown';
            
            // Get commit count difference
            $commitCount = 0;
            if ($hasUpdates) {
                $commitCount = (int)trim(shell_exec('git rev-list --count HEAD..origin/' . trim(shell_exec('git branch --show-current 2>/dev/null')) . ' 2>/dev/null') ?: '0');
            }
            
            // Get latest commit info
            $latestCommitInfo = null;
            if ($hasUpdates) {
                $latestCommitInfo = [
                    'hash' => $latestCommit,
                    'message' => trim(shell_exec('git log -1 --format=%s origin/' . trim(shell_exec('git branch --show-current 2>/dev/null')) . ' 2>/dev/null') ?: ''),
                    'author' => trim(shell_exec('git log -1 --format=%an origin/' . trim(shell_exec('git branch --show-current 2>/dev/null')) . ' 2>/dev/null') ?: ''),
                    'date' => trim(shell_exec('git log -1 --format=%cd origin/' . trim(shell_exec('git branch --show-current 2>/dev/null')) . ' 2>/dev/null') ?: '')
                ];
            }

            return response()->json([
                'data' => [
                    'has_updates' => $hasUpdates,
                    'current_commit' => $currentCommit,
                    'latest_commit' => $latestCommit,
                    'commit_count' => $commitCount,
                    'latest_commit_info' => $latestCommitInfo,
                    'fetch_result' => $fetchResult,
                    'checked_at' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error checking for updates: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to check for updates',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Perform backend update operations
     */
    public function updateBackend(Request $request)
    {
        // $this->checkAuthorization('update-system');

        try {
            $steps = [];
            $errors = [];

            // Step 1: Stash any changes
            $steps[] = 'Stashing changes...';
            $stashResult = shell_exec('git stash 2>&1');
            if (strpos($stashResult, 'No local changes') === false) {
                $steps[] = 'Changes stashed successfully';
            } else {
                $steps[] = 'No changes to stash';
            }

            // Step 2: Pull latest changes
            $steps[] = 'Pulling latest changes...';
            $pullResult = shell_exec('git pull origin ' . trim(shell_exec('git branch --show-current 2>/dev/null')) . ' 2>&1');
            if (strpos($pullResult, 'Already up to date') !== false) {
                $steps[] = 'Already up to date';
            } elseif (strpos($pullResult, 'Updating') !== false) {
                $steps[] = 'Successfully pulled latest changes';
            } else {
                $errors[] = 'Failed to pull changes: ' . $pullResult;
            }

            // Step 3: Run migrations
            $steps[] = 'Running migrations...';
            try {
                Artisan::call('migrate', ['--force' => true]);
                $steps[] = 'Migrations completed successfully';
            } catch (\Exception $e) {
                $errors[] = 'Migration failed: ' . $e->getMessage();
            }

            // Step 4: Clear cache
            $steps[] = 'Clearing cache...';
            try {
                Artisan::call('cache:clear');
                Artisan::call('config:clear');
                Artisan::call('route:clear');
                Artisan::call('view:clear');
                $steps[] = 'Cache cleared successfully';
            } catch (\Exception $e) {
                $errors[] = 'Cache clear failed: ' . $e->getMessage();
            }

            // Step 5: Recache for production
            $steps[] = 'Recaching for production...';
            try {
                Artisan::call('config:cache');
                Artisan::call('route:cache');
                $steps[] = 'Recaching completed successfully';
            } catch (\Exception $e) {
                $errors[] = 'Recaching failed: ' . $e->getMessage();
            }

            // Step 6: Pop stashed changes if any
            $steps[] = 'Restoring stashed changes...';
            $stashList = shell_exec('git stash list 2>/dev/null');
            if (!empty(trim($stashList))) {
                $popResult = shell_exec('git stash pop 2>&1');
                if (strpos($popResult, 'Dropped refs/stash') !== false) {
                    $steps[] = 'Stashed changes restored successfully';
                } else {
                    $errors[] = 'Failed to restore stashed changes: ' . $popResult;
                }
            } else {
                $steps[] = 'No stashed changes to restore';
            }

            return response()->json([
                'message' => empty($errors) ? 'Backend updated successfully' : 'Backend updated with some errors',
                'data' => [
                    'steps' => $steps,
                    'errors' => $errors,
                    'success' => empty($errors),
                    'updated_at' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating backend: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update backend',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get frontend update instructions
     */
    public function getFrontendUpdateInstructions(Request $request)
    {
        $this->checkAuthorization('update-system');

        try {
            // Get frontend directory path (assuming it's in the same parent directory)
            $frontendPath = dirname(base_path()) . '/sales-ui';
            
            $instructions = [
                'frontend_path' => $frontendPath,
                'commands' => [
                    'cd ' . $frontendPath,
                    'git stash',
                    'git pull',
                    'npm install',
                    'npm run build'
                ],
                'description' => 'These commands should be run in the frontend directory to update the React application'
            ];

            return response()->json([
                'data' => $instructions
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting frontend update instructions: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to get frontend update instructions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to authorize based on permission string
     */
    private function checkAuthorization(string $permission): void
    {
        if (Auth::user() && !Auth::user()->can($permission)) {
            abort(403, 'This action is unauthorized.');
        } elseif (!Auth::user()) {
            abort(401, 'Unauthenticated.');
        }
    }
} 