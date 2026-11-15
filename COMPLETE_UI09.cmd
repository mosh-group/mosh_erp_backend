@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0"
set "MOSH_UI09_PHP="
set "MOSH_UI09_RUNTIME="
if exist "%~dp0..\runtime\windows\php.exe" (
    set "MOSH_UI09_RUNTIME=%~dp0..\runtime\windows"
    goto bundled
)
if exist "%~dp0runtime\windows\php.exe" (
    set "MOSH_UI09_RUNTIME=%~dp0runtime\windows"
    goto bundled
)
for /f "delims=" %%P in ('where php.exe 2^>nul') do if not defined MOSH_UI09_PHP set "MOSH_UI09_PHP=%%P"
if not defined MOSH_UI09_PHP goto missing
 goto ready
:bundled
set "MOSH_UI09_PHP=%MOSH_UI09_RUNTIME%\php.exe"
set "PHPRC=%MOSH_UI09_RUNTIME%\php.ini"
set "MOSH_PHP_EXT_DIR=%MOSH_UI09_RUNTIME%\ext"
set "PHP_INI_SCAN_DIR="
set "PATH=%MOSH_UI09_RUNTIME%;%PATH%"
:ready
echo MOSH UI09 - Complete database tables after merging frontend and backend.
echo Run this file in the CURRENT backend folder, after stopping the server.
echo Existing records and settings are backed up and preserved.
pause
"%MOSH_UI09_PHP%" "%~dp0tools\prepare_ui09.php" --apply
if errorlevel 1 goto failed
echo DONE. Restart your existing server and press Ctrl+F5 in the browser.
pause
exit /b 0
:missing
echo PHP was not found. Use the PHP installation already running your MOSH server.
echo Run: php tools\prepare_ui09.php --apply
pause
exit /b 1
:failed
echo STOP. Keep the server stopped and review the message above.
pause
exit /b 1
