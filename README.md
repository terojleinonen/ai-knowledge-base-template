📘 AI Knowledge Base – Portfolio Demo Template

A full-stack AI-powered document Q&A system built with Laravel + React + OpenAI.

This repository contains a complete starter template for building an AI-powered Knowledge Base where users can:

Upload PDF / DOCX / TXT documents

Automatically extract and chunk text

Generate vector embeddings using OpenAI

Ask questions and get GPT-powered answers based on their own documents

Use a clean, modern React dashboard

Display a professional SaaS-style landing page

This template is optimized for portfolio/demo use, not production scale.

🚀 Features
Frontend (React + Vite + Tailwind)

Beautiful SaaS-style landing page

Dashboard with:

Document uploader

Document list with status

Chat interface (embeddings + GPT answer)

Fully API-driven

Live mockup image included in UI

Backend (Laravel API)

Document Upload

PDF / DOCX / TXT text extraction

Chunking engine (~800 char segments)

Embedding generation using OpenAI

Semantic search using cosine similarity

GPT-4o-mini question answering with contextual grounding

Simple demo-mode implementation (single user, SQLite)

Reset command to wipe demo data

🧱 Project Structure
ai-knowledge-base-template/
├── README.md
├── backend/          # Laravel API
└── frontend/         # React SPA (Vite + Tailwind)

⚙️ Installation
1. Clone the repo
git clone https://github.com/YOUR_USERNAME/ai-knowledge-base-template.git
cd ai-knowledge-base-template

🖥️ Backend Setup (Laravel API)
cd backend
cp .env.example .env
composer install
php artisan key:generate

Configure your environment:

Open .env and set your OpenAI key:

OPENAI_API_KEY=your-openai-key-here

Use SQLite for demo mode
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite


Create the SQLite file:

mkdir -p database
touch database/database.sqlite

Run migrations
php artisan migrate

Start backend server
php artisan serve


Backend runs at:

http://localhost:8000/api

🎨 Frontend Setup (React)
cd ../frontend
npm install


Create .env file:

VITE_API_BASE=http://localhost:8000/api


Start the development server:

npm run dev


Frontend runs at:

http://localhost:5173

🧠 AI Models Used

text-embedding-3-small — vector embeddings

gpt-4o-mini — compact, fast Q&A generation

These models provide:

Low cost

Fast inference

Good semantic accuracy for document retrieval

📄 Demo Reset Command (optional)

To wipe all uploaded documents + embeddings:

cd backend
php artisan demo:reset


Useful for public demos.

📦 Deployment Notes (Portfolio Mode)
Recommended:

Backend: Render.com free tier

Frontend: Vercel / Netlify

Database: SQLite (demo) or Postgres on Render

Production-level architectures (workers, queues, vector DBs, multi-user auth) are intentionally omitted to keep the project lightweight and easy to deploy for portfolio showcases.

📸 Assets

Your hero mockup is included in:

frontend/src/assets/hero-mockup.png
backend/public/mockups/hero-mockup.png


You can swap it with your own screenshots anytime.

🧩 Next Steps (Optional Enhancements)

If you want to grow the template into a real SaaS:

Add authentication (Laravel Sanctum + React)

Multi-tenant “workspace” system

Background workers for embeddings

Replace local cosine search with Pinecone/Qdrant

Add API token usage limits

Add billing (Stripe)

I can generate these features too — just ask!

👤 Author / Credits

This template was generated collaboratively using ChatGPT and structured as a professional starter kit for AI-powered document question-answering systems.