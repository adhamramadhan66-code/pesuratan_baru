@echo off
setlocal
cd /d "%~dp0"
set "PORT=8000"
set "PHPEXE="

rem --- Cari PHP (PATH, lalu lokasi umum XAMPP / Laragon) ---
where php >nul 2>&1 && set "PHPEXE=php"
if not defined PHPEXE if exist "C:\xampp\php\php.exe" set "PHPEXE=C:\xampp\php\php.exe"
if not defined PHPEXE for /d %%D in ("C:\laragon\bin\php\php*") do if exist "%%D\php.exe" set "PHPEXE=%%D\php.exe"

if defined PHPEXE goto runphp

rem --- PHP tidak ada: server Python hanya untuk halaman statis ---
echo ============================================================
echo  PHP belum ditemukan. API surat & pengarsipan (database) TIDAK akan jalan.
echo  Install PHP atau XAMPP, lalu jalankan file ini lagi.
echo ============================================================
where py >nul 2>&1
if not errorlevel 1 (
  start "Kampusly Local Server" /min cmd /k "cd /d ""%~dp0"" && py -m http.server %PORT% --bind 127.0.0.1"
  goto openbrowser
)
where python >nul 2>&1
if not errorlevel 1 (
  start "Kampusly Local Server" /min cmd /k "cd /d ""%~dp0"" && python -m http.server %PORT% --bind 127.0.0.1"
  goto openbrowser
)
echo Python juga belum ditemukan.
pause
exit /b 1

:runphp
start "Kampusly PHP Server" /min cmd /k "cd /d ""%~dp0"" && ""%PHPEXE%"" -S 127.0.0.1:%PORT% -t ."

:openbrowser
timeout /t 2 /nobreak >nul
start "" "http://127.0.0.1:%PORT%/"
exit /b 0
