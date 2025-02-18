# Event Manager API

A RESTful API for managing events, built with Symfony 7. This API allows users to create, manage, and participate in events.
As this is a personal project, all the configurations are pushed, no exceptions, so this will allow me to install the project fast. 

## Features

- User Authentication (JWT)
- Email Verification for Account Activation
- Password Reset with Email Verification
- Event Management (CRUD operations)
- Event Participation (Join/Leave)
- Event Search and Filtering
- Event Statistics and Analytics
- Secure Cookie Handling
- Role-based Access Control
- SQLite Database for easy development
- Comprehensive Test Suite
- Email Testing with MailHog

## Prerequisites

- PHP 8.3 or higher
- Composer
- Symfony CLI
- SQLite3
- OpenSSL for JWT keys
- Docker (for MailHog)

## Installation

1. Install required PHP extensions:
```bash
sudo apt-get update
sudo apt-get install php8.3 php8.3-cli php8.3-sqlite3 php8.3-xml php8.3-curl php8.3-mbstring php8.3-zip
```

2. Clone the repository:
```bash
git clone <repository-url>
cd event_manager
```

3. Install dependencies:
```bash
https://getcomposer.org/
composer install
```

4. Generate JWT keys:
```bash
php bin/console lexik:jwt:generate-keypair
```

5. Configure your environment:
```bash
cp .env .env.local
```

Edit `.env.local` and ensure the DATABASE_URL and MAILER_DSN are set:
```
DATABASE_URL="sqlite:///%kernel.project_dir%/database/db.sqlite"
MAILER_DSN=smtp://localhost:1025
```

6. Create database and run migrations:
```bash
# Create database directory
mkdir -p database

# Create database and run migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

7. Start MailHog for email testing:
```bash
docker compose up -d mailhog
```
Access MailHog web interface at: http://localhost:8025

8. Install SQLite browser (optional, for database management):
```bash
sudo apt-get install sqlitebrowser
```

To view the database:
```bash
sqlitebrowser database/db.sqlite
```

## API Documentation

### 1. Authentication Endpoints

#### Login
```http
POST /api/auth/login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password123"
}

Response:
{
    "user": {
        "id": 1,
        "email": "user@example.com",
        "name": "User Name"
    },
    "token": "jwt_token_here"
}

Notes: 
- A secure HTTP-only cookie (BEARER) will be set containing the JWT token
- Account must be verified through email before login is allowed
```

#### Register
```http
POST /api/auth/register
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password123",
    "name": "User Name"
}

Response:
{
    "message": "User registered successfully. Please check your email for the verification code.",
    "user": {
        "id": 1,
        "email": "user@example.com",
        "name": "User Name",
        "isVerified": false
    }
}
```

Registration and Verification Process:
1. User submits registration data
2. System validates the input
3. If valid, creates unverified account and sends verification code via email
4. User must verify their account using the code within 15 minutes

#### Verify Account
```http
POST /api/auth/verify-account
Content-Type: application/json

{
    "email": "user@example.com",
    "code": "123456"
}

Response:
{
    "message": "Account verified successfully",
    "user": {
        "id": 1,
        "email": "user@example.com",
        "name": "User Name",
        "isVerified": true
    }
}
```

Account Verification Features:
- 6-digit verification codes
- Codes expire after 15 minutes
- One-time use codes (cleared after verification)
- Automatic code invalidation and cleanup
- Secure state management
- Clear error messages for:
  - Invalid codes
  - Expired codes
  - Already verified accounts
  - Non-existent accounts

Registration Validation Rules:
- Email:
  - Must be a valid email format
  - Maximum length: 180 characters
  - Must be unique (no duplicate accounts)
- Password:
  - Minimum length: 8 characters
  - Maximum length: 4096 characters
  - Must contain at least one letter and one number
  - Cannot be empty
- Name:
  - Minimum length: 2 characters
  - Maximum length: 255 characters
  - Can only contain letters, spaces, hyphens and apostrophes
  - Cannot be empty

Error Response Example:
```http
{
    "message": "Validation failed",
    "errors": {
        "email": ["The email invalid-email is not a valid email"],
        "password": ["Password must contain at least one letter and one number"],
        "name": ["Name can only contain letters, spaces, hyphens and apostrophes"]
    }
}
```

#### Update Profile
```http
PUT /api/auth/profile
Authorization: Bearer <token>
Content-Type: application/json

{
    "name": "Updated Name", // Optional
    "email": "new@example.com", // Optional
    "current_password": "old_password", // Required only when changing password
    "new_password": "new_password" // Required only when changing password
}
```

#### Logout
```http
POST /api/auth/logout
Authorization: Bearer <token>

Response:
{
    "message": "Logged out successfully"
}
```

### Password Reset

#### Request Password Reset
```http
POST /api/auth/forgot-password
Content-Type: application/json

{
    "email": "user@example.com"
}

Response:
{
    "message": "If an account exists for this email, you will receive a verification code"
}
```

#### Verify Reset Code
```http
POST /api/auth/verify-reset-code
Content-Type: application/json

{
    "email": "user@example.com",
    "code": "123456"
}

Response:
{
    "message": "Verification code is valid"
}
```

#### Reset Password
```http
POST /api/auth/reset-password
Content-Type: application/json

{
    "email": "user@example.com",
    "code": "123456",
    "new_password": "new_password123"
}

Response:
{
    "message": "Password has been successfully reset"
}
```

Password Reset Features:
- 6-digit verification codes
- Codes expire after 15 minutes
- One-time use codes (automatically cleared after password reset)
- Automatic code invalidation and cleanup
- Secure state management (codes cleared on password change)
- Rate limiting on verification attempts
- No user enumeration (consistent responses whether email exists or not)

### Development Email Testing

The application uses MailHog for email testing in development:
- All emails are captured by MailHog
- Access the MailHog web interface at http://localhost:8025
- View all sent emails, including password reset codes
- No real emails are sent in development

### 2. Basic Event Management

#### List All Events
```http
GET /api/events
```

#### Get Single Event
```http
GET /api/events/{id}
```

#### Create Event
```http
POST /api/events
Authorization: Bearer <token>
Content-Type: application/json

{
    "title": "Event Title",
    "description": "Event Description",
    "startDate": "2024-12-31T18:00:00Z",
    "endDate": "2024-12-31T22:00:00Z",
    "location": "Event Location",
    "available_places": 100,
    "price": 0,
    "image_url": "https://example.com/image.jpg" // Optional
}
```

#### Update Event
```http
PUT /api/events/{id}
Authorization: Bearer <token>
Content-Type: application/json

{
    "title": "Updated Title", // Optional
    "description": "Updated Description", // Optional
    "startDate": "2024-12-31T19:00:00Z", // Optional
    "endDate": "2024-12-31T23:00:00Z", // Optional
    "location": "Updated Location", // Optional
    "available_places": 150, // Optional
    "price": 10, // Optional
    "image_url": "https://example.com/new-image.jpg" // Optional
}
```

#### Delete Event
```http
DELETE /api/events/{id}
Authorization: Bearer <token>
```

### 3. Event Participation

#### Join Event
```http
POST /api/events/{id}/join
Authorization: Bearer <token>
```

#### Leave Event
```http
DELETE /api/events/{id}/leave
Authorization: Bearer <token>
```

### 4. Advanced Event Features

#### 4.1 Event Discovery

##### Get Upcoming Events
```http
GET /api/events/upcoming

Response:
[
    {
        "id": 1,
        "title": "Future Event",
        "description": "Event Description",
        "startDate": "2024-12-31T18:00:00Z",
        "endDate": "2024-12-31T22:00:00Z",
        "location": "Event Location",
        "available_places": 100,
        "price": 0,
        "attendees": []
    }
]
```

##### Get Past Events
```http
GET /api/events/past

Response:
[
    {
        "id": 2,
        "title": "Past Event",
        "description": "Event Description",
        "startDate": "2023-12-31T18:00:00Z",
        "endDate": "2023-12-31T22:00:00Z",
        "location": "Event Location",
        "available_places": 100,
        "price": 0,
        "attendees": []
    }
]
```

#### 4.2 Search and Filtering

##### Search Events
```http
GET /api/events/search

Query Parameters:
- q: Search term in title and description
- start_date: Filter by start date (ISO 8601 format)
- end_date: Filter by end date (ISO 8601 format)
- location: Filter by location
- min_price: Filter by minimum price
- max_price: Filter by maximum price
- has_available_places: Filter events with available places

Example:
GET /api/events/search?q=concert&location=Paris&min_price=10&max_price=100&has_available_places=1
```

#### 4.3 Event Analytics

##### Get Event Statistics
```http
GET /api/events/{id}/statistics

Response:
{
    "total_places": 100,
    "attendees_count": 45,
    "available_places": 55,
    "occupancy_rate": 45.0,
    "is_full": false
}
```

##### Get Event Participants
```http
GET /api/events/{id}/participants
Authorization: Bearer <token>

Response:
{
    "event_id": 1,
    "event_title": "Event Title",
    "total_participants": 45,
    "participants": [
        {
            "id": 1,
            "name": "User Name",
            "email": "user@example.com"
        }
    ]
}
```

### 5. User Events

#### Get My Created Events
```http
GET /api/events/my-created
Authorization: Bearer <token>

Response:
{
    "total": 2,
    "events": [
        {
            "id": 1,
            "title": "My Event",
            "description": "Event Description",
            "startDate": "2024-12-31T18:00:00Z",
            "endDate": "2024-12-31T22:00:00Z",
            "location": "Event Location",
            "available_places": 100,
            "price": 0,
            "attendees_count": 45,
            "is_full": false
        }
    ]
}
```

#### Get My Attended Events
```http
GET /api/events/my-attended
Authorization: Bearer <token>

Response:
{
    "total": 2,
    "events": [
        {
            "id": 1,
            "title": "Event Title",
            "description": "Event Description",
            "startDate": "2024-12-31T18:00:00Z",
            "endDate": "2024-12-31T22:00:00Z",
            "location": "Event Location",
            "available_places": 100,
            "price": 0,
            "creator": {
                "id": 2,
                "name": "Creator Name"
            }
        }
    ]
}
```

These endpoints allow you to:
- View all events you have created
- View all events you are attending
- Requires authentication (JWT token)
- Returns events with relevant details and statistics

## Testing

Run the test suite:
```bash
php bin/phpunit
```

The test suite includes comprehensive tests for all endpoints, including authentication, event management, and participant handling.

## Error Handling

The API returns standard HTTP status codes:
- 200: Success
- 201: Created
- 204: No Content
- 400: Bad Request
- 401: Unauthorized
- 403: Forbidden
- 404: Not Found
- 409: Conflict
- 500: Internal Server Error

## Contributing

1. Create a new branch for your feature
2. Write tests for new functionality
3. Run the test suite
4. Submit a pull request

## License

MIT License
