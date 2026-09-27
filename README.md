# Faculty Appraisal Management System

A web-based Faculty Appraisal Management System developed for managing faculty tasks, submissions, evaluations, and performance appraisal.

## Project Overview

The Faculty Appraisal Management System helps automate the process of assigning tasks to faculty members, submitting supporting documents, evaluating completed tasks, and maintaining appraisal-related records.

The system provides different access and functionalities for Admin, Principal, and Faculty users.

## Key Features

- Faculty task assignment and management
- Faculty task submission with supporting documents
- Task evaluation and scoring
- Performance appraisal management
- Email notifications for task-related activities
- Admin user management
- Faculty and Principal dashboards
- Monthly and annual reports
- Performance analysis
- Task categories and configurable evaluation settings

## User Roles

### Admin
- Manage faculty and system users
- Assign default tasks
- Manage user accounts
- Activate or deactivate users

### Principal
- Assign tasks to faculty
- Evaluate submitted tasks
- Accept, reject, or request modifications
- View faculty performance details
- Generate reports

### Faculty
- View assigned tasks
- Update task information
- Submit supporting documents
- Track task status

## Technology Stack

- **Frontend:** HTML, CSS, JavaScript
- **Backend:** PHP
- **Database:** SQLite
- **Email Service:** PHPMailer
- **PDF Generation:** Dompdf, TCPDF
- **Development Environment:** XAMPP

## Project Structure

```text
faculty-appraisal-management-system_spsmbh-s/
│
├── assets/
├── config/
├── controllers/
├── database/
├── includes/
├── middleware/
├── models/
├── Views/
├── composer.json
├── composer.lock
├── index.php
├── logout.php
├── router.php
└── .htaccess