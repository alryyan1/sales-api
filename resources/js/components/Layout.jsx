import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import UpdateButton from './UpdateButton';

const Layout = ({ children }) => {
    return (
        <div className="font-sans antialiased bg-gray-100 dark:bg-gray-900 min-h-screen">
            {/* Navigation */}
            <nav className="bg-white dark:bg-gray-800 shadow">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex justify-between h-16">
                        <div className="flex items-center">
                            {/* Logo */}
                            <div className="flex-shrink-0">
                                <h1 className="text-xl font-bold text-gray-900 dark:text-white">Sales API</h1>
                            </div>
                            
                            {/* Navigation Links */}
                            <div className="hidden md:ml-6 md:flex md:space-x-8">
                                <Link 
                                    to="/" 
                                    className="text-gray-900 dark:text-white hover:text-gray-700 dark:hover:text-gray-300 px-3 py-2 text-sm font-medium"
                                >
                                    Home
                                </Link>
                                <Link 
                                    to="/version" 
                                    className="text-gray-900 dark:text-white hover:text-gray-700 dark:hover:text-gray-300 px-3 py-2 text-sm font-medium"
                                >
                                    Version
                                </Link>
                            </div>
                        </div>

                        {/* Update Button */}
                        <div className="flex items-center space-x-4">
                            <UpdateButton />
                        </div>
                    </div>
                </div>
            </nav>

            {/* Main Content */}
            <main className="py-6">
                {children}
            </main>
        </div>
    );
};

export default Layout;