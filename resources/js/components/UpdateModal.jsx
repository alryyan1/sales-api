import React, { useState } from 'react';

const UpdateModal = ({ isOpen, onClose, onUpdateComplete }) => {
    const [updateProgress, setUpdateProgress] = useState([]);
    const [isUpdating, setIsUpdating] = useState(false);

    const performUpdate = async () => {
        if (!confirm('Are you sure you want to update? This will update both frontend and backend.')) {
            return;
        }

        setIsUpdating(true);
        setUpdateProgress(['🔄 Starting update process...']);

        try {
            // Get CSRF token
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
                setUpdateProgress(prev => [...prev, '✅ Update completed successfully!']);
                setTimeout(() => {
                    onUpdateComplete();
                }, 2000);
            } else {
                setUpdateProgress(prev => [...prev, `❌ Update failed: ${data.message}`]);
            }
        } catch (error) {
            setUpdateProgress(prev => [...prev, `❌ Update failed: ${error.message}`]);
            console.error('Update error:', error);
        } finally {
            setIsUpdating(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
            <div className="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4">
                <h3 className="text-lg font-medium text-gray-900 dark:text-white mb-4">
                    System Update
                </h3>
                
                {updateProgress.length === 0 ? (
                    <div className="mb-4">
                        <p className="text-gray-600 dark:text-gray-300 mb-4">
                            This will update both the frontend and backend of your application.
                        </p>
                        <div className="flex justify-end space-x-3">
                            <button 
                                onClick={onClose}
                                className="px-4 py-2 bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-400 dark:hover:bg-gray-500"
                            >
                                Cancel
                            </button>
                            <button 
                                onClick={performUpdate}
                                disabled={isUpdating}
                                className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50"
                            >
                                {isUpdating ? 'Updating...' : 'Start Update'}
                            </button>
                        </div>
                    </div>
                ) : (
                    <div className="mb-4">
                        <div className="space-y-2 max-h-64 overflow-y-auto">
                            {updateProgress.map((message, index) => (
                                <div key={index} className="text-sm p-2 bg-gray-100 dark:bg-gray-700 rounded">
                                    {message}
                                </div>
                            ))}
                        </div>
                        
                        <div className="flex justify-end mt-4">
                            <button 
                                onClick={onClose}
                                disabled={isUpdating}
                                className="px-4 py-2 bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-400 dark:hover:bg-gray-500 disabled:opacity-50"
                            >
                                {isUpdating ? 'Updating...' : 'Close'}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default UpdateModal;