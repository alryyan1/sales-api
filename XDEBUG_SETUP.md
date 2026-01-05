# Xdebug Setup Guide

## Current Status

✅ Xdebug v3.4.4 is installed and enabled
- Mode: `debug` (debugging enabled)
- Client Host: `127.0.0.1` (localhost)
- Client Port: `9003` (default for Xdebug 3)
- Auto-start: Enabled (`start_with_request=yes`)

## Configuration File

Your PHP configuration file is located at:
```
C:\xampp\php\php.ini
```

## Xdebug Settings

The following Xdebug settings are currently configured:

```ini
[xdebug]
zend_extension=xdebug
xdebug.mode=debug
xdebug.client_host=127.0.0.1
xdebug.client_port=9003
xdebug.start_with_request=yes
xdebug.log=C:\xampp\php\logs\xdebug.log
```

## IDE Setup

### VS Code Setup

1. **Install PHP Debug Extension**
   - Open VS Code
   - Go to Extensions (Ctrl+Shift+X)
   - Search for "PHP Debug" by Xdebug
   - Install it

2. **Create Launch Configuration**
   - Create `.vscode/launch.json` in your project root (sales-api)
   - Add the following configuration:

```json
{
    "version": "0.2.0",
    "configurations": [
        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "port": 9003,
            "pathMappings": {
                "/var/www/html": "${workspaceFolder}",
                "C:/xampp/htdocs": "${workspaceFolder}"
            },
            "log": true
        },
        {
            "name": "Launch currently open script",
            "type": "php",
            "request": "launch",
            "program": "${file}",
            "cwd": "${fileDirname}",
            "port": 9003
        }
    ]
}
```

3. **Start Debugging**
   - Set breakpoints in your PHP code
   - Press F5 or go to Run > Start Debugging
   - Select "Listen for Xdebug"
   - Make a request to your API endpoint

### PhpStorm Setup

1. **Configure PHP Interpreter**
   - Go to File > Settings > PHP
   - Set PHP language level to 8.2
   - Set CLI Interpreter to `C:\xampp\php\php.exe`

2. **Configure Xdebug**
   - Go to File > Settings > PHP > Debug
   - Set Xdebug port to `9003`
   - Check "Can accept external connections"

3. **Create Server Configuration**
   - Go to File > Settings > PHP > Servers
   - Add new server:
     - Name: `sales-api`
     - Host: `localhost`
     - Port: `80` (or your Apache port)
     - Debugger: Xdebug
     - Use path mappings: Yes
     - Project files: `C:\xampp\htdocs\sales-api`
     - Server files: `/sales-api`

4. **Start Debugging**
   - Click the "Start Listening for PHP Debug Connections" button (phone icon)
   - Set breakpoints in your code
   - Make a request to your API

## Testing Xdebug

### Method 1: Using Browser Extension (RECOMMENDED)

1. **Install Browser Extension:**
   - **Chrome**: [Xdebug Helper](https://chrome.google.com/webstore/detail/xdebug-helper/eadndfjplgieldjbigjakmdgkmoaaaoc)
   - **Firefox**: [Xdebug Helper](https://addons.mozilla.org/en-US/firefox/addon/xdebug-helper-for-firefox/)

2. **Configure the Extension:**
   - Right-click the extension icon
   - Set IDE key to: `VSCODE` (for VS Code) or `PHPSTORM` (for PhpStorm)
   - Or leave as default

3. **Enable Debugging:**
   - Click the extension icon
   - Select "Debug" mode
   - The icon should turn green

4. **Start Debugging:**
   - Make sure VS Code is listening (F5 > "Listen for Xdebug")
   - Set breakpoints in your PHP code
   - Make a request from your frontend
   - Execution should pause at breakpoints

### Method 2: Using localStorage (Frontend)

1. Open browser console (F12)
2. Run: `localStorage.setItem('xdebug_enabled', 'true')`
3. Refresh the page
4. All API requests will include `XDEBUG_SESSION_START=1` parameter
5. To disable: `localStorage.removeItem('xdebug_enabled')`

### Method 3: Using Query Parameter

Add `?XDEBUG_SESSION_START=1` to your API URL:
```
http://localhost/sales-api/public/api/sales?XDEBUG_SESSION_START=1
```

### Method 4: Using Cookie

Set a cookie in your browser console:
```javascript
document.cookie = "XDEBUG_SESSION=1; path=/";
```

## Troubleshooting Breakpoints Not Hitting

### 1. Check Path Mappings

The path mappings in `.vscode/launch.json` must match your server path:
```json
"pathMappings": {
    "C:/xampp/htdocs/sales-api": "${workspaceFolder}"
}
```

### 2. Verify Xdebug is Triggered

Check if Xdebug is receiving requests:
- Look at VS Code Debug Console for connection messages
- Check Xdebug log: `C:\xampp\php\logs\xdebug.log`

### 3. Check Port 9003

Ensure port 9003 is not blocked by firewall:
```powershell
netstat -an | findstr 9003
```

### 4. Verify Xdebug Configuration

Run this to check Xdebug settings:
```bash
php -i | findstr xdebug
```

Should show:
- `xdebug.mode => debug`
- `xdebug.client_port => 9003`
- `xdebug.start_with_request => yes`

### 5. Test with Direct URL

Try accessing your API directly with Xdebug trigger:
```
http://localhost/sales-api/public/api/sales?XDEBUG_SESSION_START=1
```

### 6. Check VS Code Debug Console

Look for messages like:
- "Xdebug: [Step Debug] Connected"
- "Xdebug: [Step Debug] Breakpoint resolved"

### 7. Restart Apache

After changing Xdebug settings, restart Apache:
- Open XAMPP Control Panel
- Stop and Start Apache

## Common Issues

### Xdebug Not Connecting

1. **Check if Xdebug is loaded:**
   ```bash
   php -m | findstr xdebug
   ```

2. **Check Xdebug configuration:**
   ```bash
   php -i | findstr xdebug
   ```

3. **Check firewall:** Ensure port 9003 is not blocked

4. **Check client_host:** Should be `127.0.0.1` (not `127.0.01`)

### Port Already in Use

If port 9003 is in use, change it in `php.ini`:
```ini
xdebug.client_port=9004
```
And update your IDE configuration accordingly.

### Performance Issues

If Xdebug slows down your application, you can disable auto-start:
```ini
xdebug.start_with_request=trigger
```

Then only start debugging when you use the trigger (browser extension, query parameter, or cookie).

## Useful Xdebug Functions

In your PHP code, you can use:
- `xdebug_break()` - Force a breakpoint at this line
- `xdebug_info()` - Display Xdebug information

## Logging

Xdebug logs are written to:
```
C:\xampp\php\logs\xdebug.log
```

To enable logging, add to `php.ini`:
```ini
xdebug.log=C:\xampp\php\logs\xdebug.log
xdebug.log_level=0  # 0 = all, 7 = critical only
```

## Next Steps

1. ✅ Xdebug is installed and configured
2. ⬜ Set up your IDE (VS Code or PhpStorm)
3. ⬜ Test with a simple breakpoint
4. ⬜ Configure path mappings for your project

## Resources

- [Xdebug Documentation](https://xdebug.org/docs/)
- [Xdebug 3 Upgrade Guide](https://xdebug.org/docs/upgrade_guide)
- [VS Code PHP Debug Extension](https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug)
- [PhpStorm Xdebug Guide](https://www.jetbrains.com/help/phpstorm/configuring-xdebug.html)

