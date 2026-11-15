@echo off
cd /d "%~dp0"
title MOSH Instagram Worker
where php >nul 2>nul
if errorlevel 1 (
  echo PHP was not found in PATH. Ask IT to run this with the same PHP used by MOSH.
  pause
  exit /b 1
)
php artisan mosh:instagram-work
pause
