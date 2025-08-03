<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Sales API')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        .update-available {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    </style>
</head>
<body class="font-sans antialiased bg-gray-100 dark:bg-gray-900">
    <!-- Navigation -->
    <nav class="bg-white dark:bg-gray-800 shadow">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <!-- Logo -->
                    <div class="flex-shrink-0">
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white">Sales API</h1>
                    </div>
                    
                    <!-- Navigation Links -->
                    <div class="hidden md:ml-6 md:flex md:space-x-8">
                        <a href="{{ url('/') }}" class="text-gray-900 dark:text-white hover:text-gray-700 dark:hover:text-gray-300 px-3 py-2 text-sm font-medium">
                            Home
                        </a>
                        <a href="{{ route('version') }}" class="text-gray-900 dark:text-white hover:text-gray-700 dark:hover:text-gray-300 px-3 py-2 text-sm font-medium">
                            Version
                        </a>
                    </div>
                </div>

                <!-- Update Button -->
                <div class="flex items-center space-x-4">
                    <button 
                        id="updateButton" 
                        onclick="checkForUpdates()"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        <span id="updateButtonText">Check Updates</span>
                    </button>

                    <!-- Update Progress Modal -->
                    <div id="updateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
                        <div class="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Update Progress</h3>
                            <div id="updateProgress" class="space-y-2">
                                <!-- Progress will be shown here -->
                            </div>
                            <div class="mt-4 flex justify-end">
                                <button onclick="closeUpdateModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="py-6">
        @yield('content')
    </main>

    <!-- Update JavaScript -->
    <script>
        let updateCheckInterval;
        
        // Check for updates on page load
        document.addEventListener('DOMContentLoaded', function() {
            checkForUpdates();
            // Check for updates every 5 minutes
            updateCheckInterval = setInterval(checkForUpdates, 5 * 60 * 1000);
        });

        async function checkForUpdates() {
            try {
                const response = await fetch('/api/updates/check');
                const data = await response.json();
                
                const updateButton = document.getElementById('updateButton');
                const updateButtonText = document.getElementById('updateButtonText');
                
                if (data.update_available) {
                    updateButton.className = updateButton.className.replace('bg-gray-600 hover:bg-gray-700', 'bg-green-600 hover:bg-green-700 update-available');
                    updateButtonText.textContent = 'Update Available';
                    updateButton.onclick = () => performUpdate();
                } else {
                    updateButton.className = updateButton.className.replace('bg-green-600 hover:bg-green-700 update-available', 'bg-gray-600 hover:bg-gray-700');
                    updateButtonText.textContent = 'Up to Date';
                    updateButton.onclick = () => checkForUpdates();
                }
            } catch (error) {
                console.error('Error checking for updates:', error);
            }
        }

        async function performUpdate() {
            if (!confirm('Are you sure you want to update? This will update both frontend and backend.')) {
                return;
            }

            const modal = document.getElementById('updateModal');
            const progress = document.getElementById('updateProgress');
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            progress.innerHTML = '<div class="text-blue-600">Starting update process...</div>';

            try {
                const response = await fetch('/api/updates/perform', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify({
                        update_frontend: true,
                        update_backend: true
                    })
                });

                const data = await response.json();

                if (data.success) {
                    progress.innerHTML = '<div class="text-green-600">✅ Update completed successfully!</div>';
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    progress.innerHTML = `<div class="text-red-600">❌ Update failed: ${data.message}</div>`;
                }
            } catch (error) {
                progress.innerHTML = `<div class="text-red-600">❌ Update failed: ${error.message}</div>`;
                console.error('Update error:', error);
            }
        }

        function closeUpdateModal() {
            const modal = document.getElementById('updateModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>
</body>
</html>