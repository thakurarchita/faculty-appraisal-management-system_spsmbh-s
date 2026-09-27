CREATE TABLE sqlite_sequence(name,seq);
CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
CREATE TABLE departments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id TEXT UNIQUE NOT NULL,
            full_name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            mobile TEXT,
            department_id INTEGER,
            designation TEXT,
            employee_type TEXT CHECK(employee_type IN ('Teaching', 'Non-Teaching')),
            role_id INTEGER NOT NULL,
            password TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            is_first_login INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, profile_photo TEXT,
            FOREIGN KEY (role_id) REFERENCES roles(id),
            FOREIGN KEY (department_id) REFERENCES departments(id)
        );
CREATE TABLE task_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            max_marks INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
CREATE TABLE task_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_name TEXT NOT NULL,
            category_id INTEGER NOT NULL,
            employee_type TEXT CHECK(employee_type IN ('Teaching', 'Non-Teaching', 'Both')),
            is_default INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES task_categories(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        );
CREATE TABLE tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            assigned_by INTEGER NOT NULL,
            task_title TEXT NOT NULL,
            task_description TEXT,
            category_id INTEGER NOT NULL,
            due_date DATE,
            priority TEXT CHECK(priority IN ('Low', 'Medium', 'High')),
            attachment TEXT,
            status TEXT DEFAULT 'Pending',
            is_template INTEGER DEFAULT 0,
            template_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, duration TEXT DEFAULT 'Semester',
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (assigned_by) REFERENCES users(id),
            FOREIGN KEY (category_id) REFERENCES task_categories(id),
            FOREIGN KEY (template_id) REFERENCES task_templates(id)
        );
CREATE TABLE task_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            submitted_by INTEGER NOT NULL,
            submission_text TEXT,
            attachment TEXT,
            status TEXT DEFAULT 'Pending',
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id),
            FOREIGN KEY (submitted_by) REFERENCES users(id)
        );
CREATE TABLE task_evaluations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            submission_id INTEGER NOT NULL,
            evaluated_by INTEGER NOT NULL,
            marks_obtained DECIMAL(5,2),
            remarks TEXT,
            status TEXT CHECK(status IN ('Approved', 'Rejected')),
            evaluated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id),
            FOREIGN KEY (submission_id) REFERENCES task_submissions(id),
            FOREIGN KEY (evaluated_by) REFERENCES users(id)
        );
CREATE TABLE performance_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            max_marks INTEGER NOT NULL,
            duration TEXT CHECK(duration IN ('Monthly', 'Semester', 'Yearly')),
            updated_by INTEGER,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES task_categories(id),
            FOREIGN KEY (updated_by) REFERENCES users(id)
        );
CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            link TEXT,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
CREATE TABLE password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            is_used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
CREATE TABLE activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            details TEXT,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
CREATE TABLE academic_years (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year_name TEXT NOT NULL,
    start_date DATE,
    end_date DATE,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
