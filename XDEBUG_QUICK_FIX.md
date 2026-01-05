# Xdebug Quick Fix - Breakpoints Not Hitting

## Quick Checklist

### ✅ Step 1: Verify VS Code is Listening
1. Open VS Code
2. Go to Run and Debug (Ctrl+Shift+D)
3. Select "Listen for Xdebug" from dropdown
4. Click the green play button (or press F5)
5. You should see "Listening for Xdebug" in the status bar

### ✅ Step 2: Enable Xdebug Trigger (Choose ONE method)

#### Option A: Browser Extension (EASIEST)
1. Install [Xdebug Helper for Chrome](https://chrome.google.com/webstore/detail/xdebug-helper/eadndfjplgieldjbigjakmdgkmoaaaoc)
2. Click the extension icon
3. Select "Debug" (icon turns green)
4. Refresh your frontend page

#### Option B: Browser Console
1. Open browser console (F12)
2. Run: `localStorage.setItem('xdebug_enabled', 'true')`
3. Refresh the page
4. All API requests will now trigger Xdebug

#### Option C: Manual Cookie
1. Open browser console (F12)
2. Run: `document.cookie = "XDEBUG_SESSION=1; path=/"`
3. Refresh the page

### ✅ Step 3: Set Breakpoint
1. Open your PHP file (e.g., `SaleController.php`)
2. Click in the left margin next to a line number
3. A red dot should appear (this is your breakpoint)

### ✅ Step 4: Make Request
1. From your frontend, trigger an API call
2. VS Code should automatically pause at your breakpoint
3. You can now inspect variables, step through code, etc.

## Common Issues

### Issue: "Path mapping not found"
**Fix:** Update `.vscode/launch.json` path mappings:
```json
"pathMappings": {
    "C:/xampp/htdocs/sales-api": "${workspaceFolder}"
}
```

### Issue: "Xdebug not connecting"
**Fix:** 
1. Check if port 9003 is open: `netstat -an | findstr 9003`
2. Restart Apache in XAMPP
3. Verify Xdebug is loaded: `php -m | findstr xdebug`

### Issue: "Breakpoint not hit but Xdebug connects"
**Fix:**
1. Check path mappings match your actual server path
2. Ensure breakpoint is on an executable line (not comments/blank lines)
3. Try adding `xdebug_break();` directly in your code as a test

### Issue: "Xdebug connects but immediately disconnects"
**Fix:**
1. Check Xdebug log: `C:\xampp\php\logs\xdebug.log`
2. Verify `xdebug.start_with_request=yes` in php.ini
3. Check firewall isn't blocking port 9003

## Test It Works

1. Add this to any controller method:
```php
xdebug_break(); // Force breakpoint
```

2. Make a request - it should pause here
3. If it works, remove `xdebug_break()` and use normal breakpoints

## Still Not Working?

1. **Check Xdebug log:**
   ```
   C:\xampp\php\logs\xdebug.log
   ```

2. **Verify configuration:**
   ```bash
   php -i | findstr xdebug
   ```

3. **Test with direct URL:**
   ```
   http://localhost/sales-api/public/api/sales?XDEBUG_SESSION_START=1
   ```

4. **Restart everything:**
   - Stop Apache in XAMPP
   - Close VS Code
   - Start Apache
   - Open VS Code
   - Start listening for Xdebug
   - Try again

