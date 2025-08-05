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
     * Check for updates by fetching from remote (both backend and frontend)
     */
    public function checkForUpdates(Request $request)
    {
        // $this->checkAuthorization('update-system');

        try {
            // Backend updates check
            $backendUpdates = $this->checkBackendUpdates();
            
            // Frontend updates check
            $frontendUpdates = $this->checkFrontendUpdates();
            
            // Determine overall update status
            $hasUpdates = $backendUpdates['has_updates'] || $frontendUpdates['has_updates'];
            $totalCommitCount = $backendUpdates['commit_count'] + $frontendUpdates['commit_count'];

            return response()->json([
                'data' => [
                    'has_updates' => $hasUpdates,
                    'current_commit' => $backendUpdates['current_commit'],
                    'latest_commit' => $backendUpdates['latest_commit'],
                    'commit_count' => $totalCommitCount,
                    'latest_commit_info' => $backendUpdates['latest_commit_info'],
                    'fetch_result' => $backendUpdates['fetch_result'],
                    'checked_at' => now()->toISOString(),
                    
                    // Enhanced fields for frontend/backend separation
                    'backend_has_updates' => $backendUpdates['has_updates'],
                    'frontend_has_updates' => $frontendUpdates['has_updates'],
                    'backend_commit_count' => $backendUpdates['commit_count'],
                    'frontend_commit_count' => $frontendUpdates['commit_count'],
                    'backend_latest_commit_info' => $backendUpdates['latest_commit_info'],
                    'frontend_latest_commit_info' => $frontendUpdates['latest_commit_info'],
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
     * Check for backend updates
     */
    private function checkBackendUpdates()
    {
        // Fetch latest changes from remote
        $fetchResult = shell_exec('git fetch origin 2>&1');
        
        // Get current commit
        $currentCommit = trim(shell_exec('git rev-parse HEAD 2>/dev/null') ?: 'unknown');
        
        // Get latest commit on current branch
        $currentBranch = trim(shell_exec('git branch --show-current 2>/dev/null') ?: 'main');
        $latestCommit = trim(shell_exec("git rev-parse origin/{$currentBranch} 2>/dev/null") ?: 'unknown');
        
        // Check if there are updates available
        $hasUpdates = $currentCommit !== $latestCommit && $latestCommit !== 'unknown';
        
        // Get commit count difference
        $commitCount = 0;
        if ($hasUpdates) {
            $commitCount = (int)trim(shell_exec("git rev-list --count HEAD..origin/{$currentBranch} 2>/dev/null") ?: '0');
        }
        
        // Get latest commit info
        $latestCommitInfo = null;
        if ($hasUpdates) {
            $latestCommitInfo = [
                'hash' => $latestCommit,
                'message' => trim(shell_exec("git log -1 --format=%s origin/{$currentBranch} 2>/dev/null") ?: ''),
                'author' => trim(shell_exec("git log -1 --format=%an origin/{$currentBranch} 2>/dev/null") ?: ''),
                'date' => trim(shell_exec("git log -1 --format=%cd origin/{$currentBranch} 2>/dev/null") ?: '')
            ];
        }

        return [
            'has_updates' => $hasUpdates,
            'current_commit' => $currentCommit,
            'latest_commit' => $latestCommit,
            'commit_count' => $commitCount,
            'latest_commit_info' => $latestCommitInfo,
            'fetch_result' => $fetchResult
        ];
    }

    /**
     * Check for frontend updates
     */
    private function checkFrontendUpdates()
    {
        $frontendPath = dirname(base_path()) . '/sales-ui';
        
        // Check if frontend directory exists
        if (!File::exists($frontendPath)) {
            return [
                'has_updates' => false,
                'current_commit' => 'unknown',
                'latest_commit' => 'unknown',
                'commit_count' => 0,
                'latest_commit_info' => null,
                'fetch_result' => 'Frontend directory not found'
            ];
        }

        // Change to frontend directory and check for updates
        $originalDir = getcwd();
        chdir($frontendPath);
        
        try {
            // Fetch latest changes from remote
            $fetchResult = shell_exec('git fetch origin 2>&1');
            
            // Get current commit
            $currentCommit = trim(shell_exec('git rev-parse HEAD 2>/dev/null') ?: 'unknown');
            
            // Get latest commit on current branch
            $currentBranch = trim(shell_exec('git branch --show-current 2>/dev/null') ?: 'main');
            $latestCommit = trim(shell_exec("git rev-parse origin/{$currentBranch} 2>/dev/null") ?: 'unknown');
            
            // Check if there are updates available
            $hasUpdates = $currentCommit !== $latestCommit && $latestCommit !== 'unknown';
            
            // Get commit count difference
            $commitCount = 0;
            if ($hasUpdates) {
                $commitCount = (int)trim(shell_exec("git rev-list --count HEAD..origin/{$currentBranch} 2>/dev/null") ?: '0');
            }
            
            // Get latest commit info
            $latestCommitInfo = null;
            if ($hasUpdates) {
                $latestCommitInfo = [
                    'hash' => $latestCommit,
                    'message' => trim(shell_exec("git log -1 --format=%s origin/{$currentBranch} 2>/dev/null") ?: ''),
                    'author' => trim(shell_exec("git log -1 --format=%an origin/{$currentBranch} 2>/dev/null") ?: ''),
                    'date' => trim(shell_exec("git log -1 --format=%cd origin/{$currentBranch} 2>/dev/null") ?: '')
                ];
            }

            return [
                'has_updates' => $hasUpdates,
                'current_commit' => $currentCommit,
                'latest_commit' => $latestCommit,
                'commit_count' => $commitCount,
                'latest_commit_info' => $latestCommitInfo,
                'fetch_result' => $fetchResult
            ];
        } finally {
            // Restore original directory
            chdir($originalDir);
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
            $currentBranch = trim(shell_exec('git branch --show-current 2>/dev/null') ?: 'main');
            $pullResult = shell_exec("git pull origin {$currentBranch} 2>&1");
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
     * Perform frontend update operations
     */
    public function updateFrontend(Request $request)
    {
        // $this->checkAuthorization('update-system');

        try {
            $steps = [];
            $errors = [];
            $buildOutput = '';

            $frontendPath = dirname(base_path()) . '/sales-ui';
            
            // Check if frontend directory exists
            if (!File::exists($frontendPath)) {
                return response()->json([
                    'message' => 'Frontend directory not found',
                    'data' => [
                        'steps' => ['Error: Frontend directory not found'],
                        'errors' => ['Frontend directory not found at: ' . $frontendPath],
                        'success' => false,
                        'updated_at' => now()->toISOString()
                    ]
                ], 404);
            }

            // Change to frontend directory
            $originalDir = getcwd();
            chdir($frontendPath);

            try {
                // Step 1: Stash any changes
                $steps[] = 'Stashing frontend changes...';
                $stashResult = shell_exec('git stash 2>&1');
                if (strpos($stashResult, 'No local changes') === false) {
                    $steps[] = 'Frontend changes stashed successfully';
                } else {
                    $steps[] = 'No frontend changes to stash';
                }

                // Step 2: Pull latest changes
                $steps[] = 'Pulling latest frontend changes...';
                $currentBranch = trim(shell_exec('git branch --show-current 2>/dev/null') ?: 'main');
                $pullResult = shell_exec("git pull origin {$currentBranch} 2>&1");
                if (strpos($pullResult, 'Already up to date') !== false) {
                    $steps[] = 'Frontend already up to date';
                } elseif (strpos($pullResult, 'Updating') !== false) {
                    $steps[] = 'Successfully pulled latest frontend changes';
                } else {
                    $errors[] = 'Failed to pull frontend changes: ' . $pullResult;
                }

                // Step 3: Install dependencies
                $steps[] = 'Installing frontend dependencies...';
                $npmInstallResult = shell_exec('npm install 2>&1');
                if (strpos($npmInstallResult, 'added') !== false || strpos($npmInstallResult, 'up to date') !== false) {
                    $steps[] = 'Frontend dependencies installed successfully';
                } else {
                    $errors[] = 'Failed to install frontend dependencies: ' . $npmInstallResult;
                }

                // Step 4: Build frontend
                $steps[] = 'Building frontend application...';
                $buildResult = shell_exec('npm run build 2>&1');
                $buildOutput = $buildResult;
                
                if (strpos($buildResult, 'built successfully') !== false || strpos($buildResult, 'Build complete') !== false) {
                    $steps[] = 'Frontend built successfully';
                } else {
                    $errors[] = 'Failed to build frontend: ' . $buildResult;
                }

                // Step 5: Pop stashed changes if any
                $steps[] = 'Restoring stashed frontend changes...';
                $stashList = shell_exec('git stash list 2>/dev/null');
                if (!empty(trim($stashList))) {
                    $popResult = shell_exec('git stash pop 2>&1');
                    if (strpos($popResult, 'Dropped refs/stash') !== false) {
                        $steps[] = 'Stashed frontend changes restored successfully';
                    } else {
                        $errors[] = 'Failed to restore stashed frontend changes: ' . $popResult;
                    }
                } else {
                    $steps[] = 'No stashed frontend changes to restore';
                }

            } finally {
                // Restore original directory
                chdir($originalDir);
            }

            return response()->json([
                'message' => empty($errors) ? 'Frontend updated successfully' : 'Frontend updated with some errors',
                'data' => [
                    'steps' => $steps,
                    'errors' => $errors,
                    'success' => empty($errors),
                    'updated_at' => now()->toISOString(),
                    'build_output' => $buildOutput
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating frontend: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update frontend',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Perform combined update (both frontend and backend)
     */
    public function updateBoth(Request $request)
    {
        // $this->checkAuthorization('update-system');

        try {
            $backendResult = $this->updateBackend($request);
            $frontendResult = $this->updateFrontend($request);

            $backendData = json_decode($backendResult->getContent(), true);
            $frontendData = json_decode($frontendResult->getContent(), true);

            $overallSuccess = $backendData['data']['success'] && $frontendData['data']['success'];
            $allErrors = array_merge($backendData['data']['errors'], $frontendData['data']['errors']);

            return response()->json([
                'message' => $overallSuccess ? 'Both backend and frontend updated successfully' : 'Update completed with some errors',
                'data' => [
                    'backend' => $backendData['data'],
                    'frontend' => $frontendData['data'],
                    'overall_success' => $overallSuccess,
                    'updated_at' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating both: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update both backend and frontend',
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