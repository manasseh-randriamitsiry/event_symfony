# Event Manager API

A RESTful API for managing events, built with Symfony 7. This API allows users to create, manage, and participate in events.
As this is a personal project, all the configurations are pushed, no exceptions, so this will allow me to install the project fast. 

## Features

- User Authentication (JWT)
- Event Management (CRUD operations)
- Event Participation (Join/Leave)
- Event Search and Filtering
- Event Statistics and Analytics
- Secure Cookie Handling
- Role-based Access Control
- SQLite Database for easy development
- Comprehensive Test Suite

## Prerequisites

- PHP 8.3 or higher
- Composer
- Symfony CLI
- SQLite3
- OpenSSL for JWT keys

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

Edit `.env.local` and ensure the DATABASE_URL is set to:
```
DATABASE_URL="sqlite:///%kernel.project_dir%/database/db.sqlite"
```

6. Create database and run migrations:
```bash
# Create database directory
mkdir -p database

# Create database and run migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

7. Install SQLite browser (optional, for database management):
```bash
sudo apt-get install sqlitebrowser
```

To view the database:
```bash
sudo apt-get install sqlitebrowser
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

Note: A secure HTTP-only cookie (BEARER) will also be set containing the JWT token
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
    "message": "User registered successfully",
    "user": {
        "id": 1,
        "email": "user@example.com",
        "name": "User Name"
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
