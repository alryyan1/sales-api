import React, { useState, useEffect } from 'react';
import UpdateModal from './UpdateModal';

const UpdateButton = () => {
    const [updateStatus, setUpdateStatus] = useState({
        updateAvailable: false,
        loading: false,
        buttonText: 'Check Updates'
    });
    const [showModal, setShowModal] = useState(false);

    useEffect(() => {
        checkForUpdates();
        // Check for updates every 5 minutes
        const interval = setInterval(checkForUpdates, 5 * 60 * 1000);
        return () => clearInterval(interval);
    }, []);

    const checkForUpdates = async () => {
        try {
            setUpdateStatus(prev => ({ ...prev, loading: true }));
            
            const response = await fetch('/api/updates/check');
            const data = await response.json();
            
            if (data.error) {
                console.error('Error checking for updates:', data.message);
                setUpdateStatus({
                    updateAvailable: false,
                    loading: false,
                    buttonText: 'Check Updates'
                });
                return;
            }
            
            setUpdateStatus({
                updateAvailable: data.update_available || false,
                loading: false,
                buttonText: data.update_available ? 'Update Available' : 'Up to Date'
            });
        } catch (error) {
            console.error('Error checking for updates:', error);
            setUpdateStatus({
                updateAvailable: false,
                loading: false,
                buttonText: 'Check Updates'
            });
        }
    };

    const handleButtonClick = () => {
        if (updateStatus.updateAvailable) {
            setShowModal(true);
        } else {
            checkForUpdates();
        }
    };

    const buttonClasses = `inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white focus:outline-none focus:ring-2 focus:ring-offset-2 transition-all duration-200 ${
        updateStatus.updateAvailable
            ? 'bg-green-600 hover:bg-green-700 focus:ring-green-500 animate-pulse'
            : 'bg-gray-600 hover:bg-gray-700 focus:ring-gray-500'
    }`;

    return (
        <>
            <button 
                onClick={handleButtonClick}
                disabled={updateStatus.loading}
                className={buttonClasses}
            >
                <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                {updateStatus.loading ? 'Checking...' : updateStatus.buttonText}
            </button>

            <UpdateModal 
                isOpen={showModal} 
                onClose={() => setShowModal(false)}
                onUpdateComplete={() => {
                    setShowModal(false);
                    // Reload the page after successful update
                    setTimeout(() => window.location.reload(), 2000);
                }}
            />
        </>
    );
};

export default UpdateButton;