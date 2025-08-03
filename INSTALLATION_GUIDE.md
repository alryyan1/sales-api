# Sales Management System - Installation Guide

This guide provides step-by-step instructions for installing and setting up the Sales Management System, which consists of a Laravel backend API and a React TypeScript frontend.

## Table of Contents

- [System Requirements](#system-requirements)
- [Backend Installation (Laravel API)](#backend-installation-laravel-api)
- [Frontend Installation (React/TypeScript)](#frontend-installation-reacttypescript)
- [Database Setup](#database-setup)
- [Environment Configuration](#environment-configuration)
- [Running the Application](#running-the-application)
- [Troubleshooting](#troubleshooting)

## System Requirements

### Backend Requirements
- **PHP**: 8.1 or higher
- **Composer**: Latest version
- **MySQL**: 5.7 or higher / MariaDB 10.2 or higher
- **Web Server**: Apache/Nginx (or use Laravel's built-in server)
- **PHP Extensions**:
  - BCMath PHP Extension
  - Ctype PHP Extension
  - JSON PHP Extension
  - Mbstring PHP Extension
  - OpenSSL PHP Extension
  - PDO PHP Extension
  - Tokenizer PHP Extension
  - XML PHP Extension
  - Fileinfo PHP Extension
  - GD PHP Extension (for image processing)

### Frontend Requirements
- **Node.js**: 18.0 or higher
- **npm**: 9.0 or higher (or yarn)
- **Modern Web Browser**: Chrome, Firefox, Safari, Edge

## Backend Installation (Laravel API)

### 1. Clone the Repository
```bash
git clone <repository-url>
cd sales-api
```

### 2. Install PHP Dependencies
```bash
composer install
```

### 3. Environment Configuration
Create a `.env` file in the `sales-api` directory:

```bash
cp .env.example .env
```

If `.env.example` doesn't exist, create `.env` with the following content:

```env
APP_NAME="Sales Management System"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sales_system
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

MEMCACHED_HOST=127.0.0.1

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1

VITE_APP_NAME="${APP_NAME}"
VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_HOST="${PUSHER_HOST}"
VITE_PUSHER_PORT="${PUSHER_PORT}"
VITE_PUSHER_SCHEME="${PUSHER_SCHEME}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"

# WhatsApp Configuration (Optional)
WHATSAPP_API_URL=
WHATSAPP_API_TOKEN=
WHATSAPP_PHONE_NUMBER=
```

### 4. Generate Application Key
```bash
php artisan key:generate
```

### 5. Database Setup
Create a MySQL database named `sales_system` (or your preferred name) and update the `.env` file with your database credentials.

### 6. Run Database Migrations
```bash
php artisan migrate
```

### 7. Seed the Database (Optional)
```bash
php artisan db:seed
```

### 8. Set Storage Permissions
```bash
php artisan storage:link
```

For Linux/Mac:
```bash
chmod -R 775 storage bootstrap/cache
```

### 9. Clear and Cache Configuration
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

## Frontend Installation (React/TypeScript)

### 1. Navigate to Frontend Directory
```bash
cd ../sales-ui
```

### 2. Install Node.js Dependencies
```bash
npm install
```

### 3. Environment Configuration
Create a `.env` file in the `sales-ui` directory:

```env
VITE_API_BASE_URL=http://localhost:8000/api
VITE_APP_NAME="Sales Management System"
```

### 4. Build Configuration
The frontend is configured to build to `c:/sales/dist` by default. You can modify this in `vite.config.ts` if needed.

## Database Setup

### 1. Create Database
```sql
CREATE DATABASE sales_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Create Database User (Optional but Recommended)
```sql
CREATE USER 'sales_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON sales_system.* TO 'sales_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Update .env File
Update your `.env` file with the correct database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sales_system
DB_USERNAME=sales_user
DB_PASSWORD=your_password
```

## Environment Configuration

### Backend Environment Variables

Key environment variables to configure:

- **Database Configuration**: Update `DB_*` variables with your database credentials
- **App URL**: Set `APP_URL` to your backend URL
- **Mail Configuration**: Configure SMTP settings if email features are needed
- **WhatsApp Configuration**: Set WhatsApp API credentials if using WhatsApp features

### Frontend Environment Variables

- **API Base URL**: Set `VITE_API_BASE_URL` to your backend API URL
- **App Name**: Customize the application name

## Running the Application

### 1. Start Backend Server
```bash
cd sales-api
php artisan serve
```
The backend will be available at `http://localhost:8000`

### 2. Start Frontend Development Server
```bash
cd sales-ui
npm run dev
```
The frontend will be available at `http://localhost:5173`

### 3. Build Frontend for Production
```bash
cd sales-ui
npm run build
```

## Production Deployment

### Backend Production Setup

1. **Set Environment to Production**:
   ```env
   APP_ENV=production
   APP_DEBUG=false
   ```

2. **Optimize Laravel**:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. **Set Proper Permissions**:
   ```bash
   chmod -R 755 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

### Frontend Production Setup

1. **Build for Production**:
   ```bash
   npm run build
   ```

2. **Deploy to Web Server**: Copy the contents of the `dist` folder to your web server's document root.

## Troubleshooting

### Common Backend Issues

1. **Composer Install Fails**:
   - Ensure PHP version is 8.1 or higher
   - Check if all required PHP extensions are installed
   - Clear Composer cache: `composer clear-cache`

2. **Database Connection Issues**:
   - Verify database credentials in `.env`
   - Ensure MySQL service is running
   - Check if database exists and is accessible

3. **Permission Issues**:
   - Ensure storage and bootstrap/cache directories are writable
   - Run: `chmod -R 775 storage bootstrap/cache`

4. **Migration Errors**:
   - Clear cache: `php artisan config:clear`
   - Check database connection
   - Ensure all required tables don't already exist

### Common Frontend Issues

1. **Node Modules Issues**:
   - Delete `node_modules` and `package-lock.json`
   - Run `npm install` again

2. **Build Errors**:
   - Check TypeScript errors: `npm run lint`
   - Ensure all dependencies are properly installed

3. **API Connection Issues**:
   - Verify `VITE_API_BASE_URL` in `.env`
   - Ensure backend server is running
   - Check CORS configuration in backend

### Performance Optimization

1. **Backend Optimization**:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   composer install --optimize-autoloader --no-dev
   ```

2. **Frontend Optimization**:
   - Use production build: `npm run build`
   - Enable gzip compression on web server
   - Use CDN for static assets

## Additional Features

### WhatsApp Integration
If you want to use WhatsApp features:

1. Configure WhatsApp API credentials in `.env`
2. Set up WhatsApp webhook endpoints
3. Configure message templates

### Backup and Restore
The system includes backup functionality. Configure backup settings in the admin panel.

### User Management
Default admin user is created during seeding. Use the admin panel to manage users and permissions.

## Support

For additional support or questions:
- Check the Laravel documentation: https://laravel.com/docs
- Review React and TypeScript documentation
- Check the project's issue tracker for known issues

## License

This project is licensed under the MIT License. 