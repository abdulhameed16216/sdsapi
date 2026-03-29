@echo off
echo Setting up EB Dashboard Laravel Application...
echo.

echo Step 1: Installing Composer dependencies...
composer install --no-scripts
if %errorlevel% neq 0 (
    echo Error installing composer dependencies
    pause
    exit /b 1
)

echo.
echo Step 2: Creating .env file...
if not exist .env (
    copy .env.example .env
    echo .env file created from .env.example
) else (
    echo .env file already exists
)

echo.
echo Step 3: Generating application key...
php artisan key:generate
if %errorlevel% neq 0 (
    echo Error generating application key
    pause
    exit /b 1
)

echo.
echo Step 4: Running database migrations...
php artisan migrate
if %errorlevel% neq 0 (
    echo Error running migrations. Please check your database configuration in .env
    pause
    exit /b 1
)

echo.
echo Step 5: Seeding database...
php artisan db:seed
if %errorlevel% neq 0 (
    echo Error seeding database
    pause
    exit /b 1
)

echo.
echo Step 6: Installing NPM dependencies...
npm install
if %errorlevel% neq 0 (
    echo Error installing NPM dependencies
    pause
    exit /b 1
)

echo.
echo Setup completed successfully!
echo.
echo Default users created:
echo - Admin: admin@ebdashboard.com / password
echo - User: john@example.com / password
echo.
echo To start the development server, run:
echo php artisan serve
echo.
echo The application will be available at: http://localhost:8000
echo.
pause
