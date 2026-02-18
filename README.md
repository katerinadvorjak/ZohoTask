# ZohoTask (Vue + Laravel)

Test assignment implementation for creating **Account + linked Deal** in Zoho CRM with automatic token refresh.

## Stack
- Frontend: Vue 3 + Vite
- Backend: Laravel (API)

## Features implemented
- Form fields:
  - Deal name -> `Deal_Name`
  - Deal stage -> `Stage`
  - Account name -> `Account_Name`
  - Account website -> `Website`
  - Account phone -> `Phone`
- Validation and inline errors
- Success/error messages
- Creates Account first, then Deal linked via `Account_Name.id`
- Automatic access token refresh using refresh token

## Project structure
- `backend/` Laravel API
- `frontend/` Vue app

## Backend setup
```bash
cd backend
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Set these env vars in `backend/.env`:
```env
ZOHO_CLIENT_ID=...
ZOHO_CLIENT_SECRET=...
ZOHO_API_DOMAIN=https://www.zohoapis.com
```

## Save token (once)
Use endpoint to store refresh token:
```bash
curl -X POST http://localhost:8000/api/zoho/token \
  -H "Content-Type: application/json" \
  -d '{
    "refresh_token":"YOUR_REFRESH_TOKEN",
    "access_token":"OPTIONAL_INITIAL_ACCESS_TOKEN",
    "api_domain":"https://www.zohoapis.com",
    "expires_in_sec":3600
  }'
```

## Frontend setup
```bash
cd frontend
cp .env.example .env
npm install
npm run dev
```

Default frontend URL: `http://localhost:5173`

## API endpoints
- `POST /api/zoho/token` — save/update token data
- `POST /api/zoho/deal-account` — create linked account + deal

## Notes for submission
- Register Zoho trial account and configure credentials/tokens
- Record required demo video:
  1. Show CRM state before submit
  2. Fill form + submit
  3. Show created/linked records in Zoho CRM
- Share repository link
