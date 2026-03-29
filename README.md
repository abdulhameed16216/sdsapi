# EB Dashboard - Laravel API

A comprehensive Laravel application with API support for both mobile and web applications.

## Features

- **RESTful API** with Laravel Sanctum authentication
- **Web Dashboard** with user management
- **CORS Support** for mobile app integration
- **Role-based Access Control** (Admin/User)
- **User Management** with CRUD operations
- **Analytics Dashboard** with statistics
- **Responsive Design** for web interface

## API Endpoints

### Authentication
- `POST /api/register` - Register new user
- `POST /api/login` - User login
- `POST /api/logout` - User logout (requires auth)
- `GET /api/user` - Get authenticated user (requires auth)
- `PUT /api/user/profile` - Update user profile (requires auth)
- `POST /api/user/change-password` - Change password (requires auth)
- `POST /api/forgot-password` - Request password reset
- `POST /api/reset-password` - Reset password

### User Management
- `GET /api/users` - List users (requires auth)
- `POST /api/users` - Create user (requires auth)
- `GET /api/users/{id}` - Get user details (requires auth)
- `PUT /api/users/{id}` - Update user (requires auth)
- `DELETE /api/users/{id}` - Delete user (requires auth)

### Dashboard
- `GET /api/dashboard/stats` - Get dashboard statistics (requires auth)
- `GET /api/dashboard/analytics` - Get analytics data (requires auth)
- `GET /api/dashboard/recent-activities` - Get recent activities (requires auth)

### Health Check
- `GET /api/health` - API health check

## Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd eb-dashboard
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database setup**
   ```bash
   # Configure your database in .env file
   php artisan migrate
   php artisan db:seed
   ```

5. **Start the development server**
   ```bash
   php artisan serve
   ```

## Configuration

### Environment Variables

Key environment variables to configure:

```env
# Application
APP_NAME="EB Dashboard"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=eb_dashboard
DB_USERNAME=root
DB_PASSWORD=

# CORS Configuration
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:8080,http://localhost:4200
CORS_ALLOWED_METHODS=GET,POST,PUT,DELETE,OPTIONS
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Requested-With

# API Configuration
API_RATE_LIMIT=60
API_RATE_LIMIT_WINDOW=1
```

### CORS Setup

The application is configured to handle CORS for mobile app integration. Update the `CORS_ALLOWED_ORIGINS` in your `.env` file to include your mobile app's domain.

## API Usage

### Authentication

All protected endpoints require a Bearer token in the Authorization header:

```bash
Authorization: Bearer {your-token}
```

### Example API Calls

**Register a new user:**
```bash
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "phone": "+1234567890"
  }'
```

**Login:**
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "password123"
  }'
```

**Get dashboard stats:**
```bash
curl -X GET http://localhost:8000/api/dashboard/stats \
  -H "Authorization: Bearer {your-token}"
```

## Default Users

After running the database seeder, you'll have these default users:

- **Admin User**
  - Email: `admin@ebdashboard.com`
  - Password: `password`
  - Role: Admin

- **Sample Users**
  - Email: `john@example.com`
  - Password: `password`
  - Role: User

## Web Dashboard

Access the web dashboard at `http://localhost:8000` after starting the server.

Features:
- User authentication
- Dashboard with statistics
- User management
- Analytics charts
- Profile management

## Mobile App Integration

The API is designed to work seamlessly with mobile applications:

1. **Authentication Flow**: Use the login endpoint to get a Bearer token
2. **Token Storage**: Store the token securely in your mobile app
3. **API Calls**: Include the token in the Authorization header for protected routes
4. **CORS**: The API supports CORS for web-based mobile apps

## Development

### Running Tests
```bash
php artisan test
```

### Code Style
```bash
./vendor/bin/pint
```

### Database Seeding
```bash
php artisan db:seed
```

## Security Features

- **Laravel Sanctum** for API authentication
- **CSRF Protection** for web routes
- **Rate Limiting** for API endpoints
- **Password Hashing** with bcrypt
- **CORS Configuration** for secure cross-origin requests
- **Input Validation** on all endpoints

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Support

For support and questions, please contact the development team.
