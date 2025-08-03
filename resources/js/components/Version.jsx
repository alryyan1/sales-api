import React, { useState, useEffect } from 'react';

const Version = () => {
    const [versionInfo, setVersionInfo] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        fetchVersionInfo();
    }, []);

    const fetchVersionInfo = async () => {
        try {
            setLoading(true);
            setError(null);
            
            const response = await fetch('/api/updates/check');
            const data = await response.json();
            
            if (data.error) {
                setError(data.message || 'Failed to fetch version information');
            } else {
                setVersionInfo(data);
            }
        } catch (err) {
            setError('Failed to fetch version information');
            console.error('Error fetching version info:', err);
        } finally {
            setLoading(false);
        }
    };

    const performUpdate = async () => {
        if (!confirm('Are you sure you want to update? This will update both frontend and backend.')) {
            return;
        }

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const response = await fetch('/api/updates/perform', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || ''
                },
                body: JSON.stringify({
                    update_frontend: true,
                    update_backend: true
                })
            });

            const data = await response.json();

            if (data.success) {
                alert('Update completed successfully! The page will reload.');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                alert(`Update failed: ${data.message}`);
            }
        } catch (error) {
            alert(`Update failed: ${error.message}`);
            console.error('Update error:', error);
        }
    };

    if (loading) {
        return (
            <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                    <div className="px-4 py-5 sm:p-6">
                        <div className="flex items-center justify-center py-12">
                            <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span className="text-gray-500 dark:text-gray-400">Loading version information...</span>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                    <div className="px-4 py-5 sm:p-6">
                        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                            <div className="flex items-center">
                                <svg className="w-5 h-5 text-red-600 dark:text-red-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd"></path>
                                </svg>
                                <p className="text-red-800 dark:text-red-200 font-medium">Error</p>
                            </div>
                            <p className="text-red-700 dark:text-red-300 mt-2 text-sm">{error}</p>
                            <button 
                                onClick={fetchVersionInfo}
                                className="mt-3 inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 dark:bg-red-800 dark:text-red-200 dark:hover:bg-red-700"
                            >
                                Retry
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                <div className="px-4 py-5 sm:p-6">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-white mb-6">Version Information</h2>
                    
                    {/* Current Version */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <div className="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                            <h3 className="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2">Current Version</h3>
                            <div className="space-y-2">
                                <p className="text-sm text-gray-600 dark:text-gray-300">
                                    <span className="font-medium">Version:</span> {versionInfo?.current_version || 'Unknown'}
                                </p>
                                <p className="text-sm text-gray-600 dark:text-gray-300">
                                    <span className="font-medium">Commit:</span> {versionInfo?.current_commit || 'Unknown'}
                                </p>
                                <p className="text-sm text-gray-600 dark:text-gray-300">
                                    <span className="font-medium">Branch:</span> {versionInfo?.current_branch || 'Unknown'}
                                </p>
                            </div>
                        </div>

                        <div className="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                            <h3 className="text-lg font-semibold text-green-900 dark:text-green-100 mb-2">Latest Version</h3>
                            <div className="space-y-2">
                                <p className="text-sm text-gray-600 dark:text-gray-300">
                                    <span className="font-medium">Version:</span> {versionInfo?.latest_version || 'Unknown'}
                                </p>
                                <p className="text-sm text-gray-600 dark:text-gray-300">
                                    <span className="font-medium">Commit:</span> {versionInfo?.latest_commit || 'Unknown'}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Update Status */}
                    <div className="mb-8">
                        <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-4">Update Status</h3>
                        
                        {versionInfo?.update_available ? (
                            <div className="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                                <div className="flex items-center">
                                    <svg className="w-5 h-5 text-yellow-600 dark:text-yellow-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd"></path>
                                    </svg>
                                    <p className="text-yellow-800 dark:text-yellow-200 font-medium">Update Available</p>
                                </div>
                                <p className="text-yellow-700 dark:text-yellow-300 mt-2 text-sm">
                                    A new version is available. Click the update button in the navigation bar to update.
                                </p>
                            </div>
                        ) : (
                            <div className="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                                <div className="flex items-center">
                                    <svg className="w-5 h-5 text-green-600 dark:text-green-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd"></path>
                                    </svg>
                                    <p className="text-green-800 dark:text-green-200 font-medium">Up to Date</p>
                                </div>
                                <p className="text-green-700 dark:text-green-300 mt-2 text-sm">
                                    You are running the latest version.
                                </p>
                            </div>
                        )}
                    </div>

                    {/* System Status */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div className="space-y-3">
                            <h4 className="font-medium text-gray-900 dark:text-white">Repository Status</h4>
                            
                            <div className="flex items-center text-sm">
                                {versionInfo?.has_uncommitted_changes ? (
                                    <>
                                        <svg className="w-4 h-4 text-orange-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd"></path>
                                        </svg>
                                        <span className="text-orange-700 dark:text-orange-300">Uncommitted changes detected</span>
                                    </>
                                ) : (
                                    <>
                                        <svg className="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd"></path>
                                        </svg>
                                        <span className="text-green-700 dark:text-green-300">Working directory clean</span>
                                    </>
                                )}
                            </div>
                        </div>

                        <div className="space-y-3">
                            <h4 className="font-medium text-gray-900 dark:text-white">Database Status</h4>
                            
                            <div className="flex items-center text-sm">
                                {versionInfo?.pending_migrations ? (
                                    <>
                                        <svg className="w-4 h-4 text-orange-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd"></path>
                                        </svg>
                                        <span className="text-orange-700 dark:text-orange-300">Pending migrations</span>
                                    </>
                                ) : (
                                    <>
                                        <svg className="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd"></path>
                                        </svg>
                                        <span className="text-green-700 dark:text-green-300">Database up to date</span>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="border-t border-gray-200 dark:border-gray-700 pt-6">
                        <div className="flex space-x-4">
                            <button 
                                onClick={fetchVersionInfo} 
                                className="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                            >
                                <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                </svg>
                                Refresh Version Info
                            </button>
                            
                            {versionInfo?.update_available && (
                                <button 
                                    onClick={performUpdate} 
                                    className="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                                >
                                    <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
                                    </svg>
                                    Update Now
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default Version;