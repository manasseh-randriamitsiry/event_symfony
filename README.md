# Event Manager API

A RESTful API for managing events, built with Symfony 6. This API allows users to create, manage, and participate in events.

## Features

- User Authentication (JWT)
- Event Management
- Event Participation
- Secure Cookie Handling
- Role-based Access Control

## Prerequisites

- PHP 8.1 or higher
- Composer
- Symfony CLI
- Database (MySQL/PostgreSQL)
- OpenSSL for JWT keys

## Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd event_manager
```

2. Install dependencies:
```bash
composer install
```

3. Generate JWT keys:
```bash
php bin/console lexik:jwt:generate-keypair
```

4. Configure your environment variables:
```bash
cp .env .env.local
```
Edit `.env.local` with your database and other configuration settings.

5. Create database and run migrations:
```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
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
    "date": "2024-12-31T00:00:00Z",
    "location": "Event Location",
    "available_places": 100,
    "price": 0,
    "image_url": "https://example.com/image.jpg"
}
```

#### Update Event
```http
PUT /api/events/{id}
Content-Type: application/json

{
    "title": "Updated Title",
    "description": "Updated description"
}
```

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

## Frontend Integration Examples

### Authentication Service
```typescript
// src/services/auth.service.ts
import axios from 'axios';

const API_URL = 'http://localhost:8000/api';

export class AuthService {
  static async login(email: string, password: string) {
    const response = await axios.post(`${API_URL}/auth/login`, {
      email,
      password
    });
    if (response.data.token) {
      localStorage.setItem('token', response.data.token);
    }
    return response.data;
  }

  static getAuthHeader() {
    const token = localStorage.getItem('token');
    return token ? { Authorization: `Bearer ${token}` } : {};
  }
}
```

### Event Service
```typescript
// src/services/event.service.ts
import axios from 'axios';
import { AuthService } from './auth.service';
b
const API_URL = 'http://localhost:8000/api';

export class EventService {
  static async getEvents() {
    return axios.get(`${API_URL}/events`, {
      headers: AuthService.getAuthHeader()
    });
  }

  static async joinEvent(eventId: number) {
    return axios.post(`${API_URL}/events/${eventId}/join`, {}, {
      headers: AuthService.getAuthHeader()
    });
  }
}
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
