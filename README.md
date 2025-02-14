# Event Manager API

A RESTful API for managing events, built with Symfony 7. This API allows users to create, manage, and participate in events.
As this is a personal project, all the configurations are pushed, no exceptions, so this will allow me to install the project fast. 

## Features

- User Authentication (JWT)
- Event Management (CRUD operations)
- Event Participation (Join/Leave)
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

## API Endpoints

### Authentication

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
#### Update Profile
```
http
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

#### Register
```http
POST /api/auth/register
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password123",
    "name": "John Doe"
}
```

#### Edit Profile
```http
PUT /api/auth/profile
Authorization: Bearer <your_jwt_token>
Content-Type: application/json
{
"name": "Updated Name", // Optional
"email": "new@example.com", // Optional
"current_password": "old123", // Required only when changing password
"new_password": "new123" // Required only when changing password
}
```

### Event Endpoints

All event endpoints require JWT authentication header:
```http
Authorization: Bearer <your_jwt_token>
```

#### List Events
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
Content-Type: application/json

{
    "title": "New Event",
    "description": "Event description",
    "startDate": "2024-12-31T18:00:00Z",
    "endDate": "2024-12-31T22:00:00Z",
    "location": "Event Location",
    "available_places": 100,
    "price": 0,
    "image_url": "https://example.com/image.jpg"
}
```

#### Update Event
```http
UT /api/events/{id}
Content-Type: application/json
{
    "title": "Updated Title", // Optional
    "description": "Updated desc", // Optional
    "startDate": "2024-12-31T18:00:00Z", // Optional
    "endDate": "2024-12-31T22:00:00Z", // Optional
    "location": "New Location", // Optional
    "available_places": 150, // Optional
    "price": 25.99, // Optional
    "image_url": "https://..." // Optional
}
```
Response (200 OK): Updated event object

#### Delete Event
```http
DELETE /api/events/{id}
```

#### Join Event
```http
POST /api/events/{id}/join
```

#### Leave Event
```http
DELETE /api/events/{id}/leave
```

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
