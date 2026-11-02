SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS learning_courses (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  title VARCHAR(190) NOT NULL,
  category VARCHAR(120) NULL,
  description TEXT NULL,
  duration_hours DECIMAL(8,2) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_learning_courses_church (church_id,active,title),
  CONSTRAINT fk_learning_courses_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learning_classes (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  course_id CHAR(36) NOT NULL,
  congregation_id CHAR(36) NULL,
  name VARCHAR(190) NOT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  meeting_info VARCHAR(255) NULL,
  capacity INT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  notes TEXT NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_learning_classes_church (church_id,status,start_date),
  KEY idx_learning_classes_course (church_id,course_id),
  CONSTRAINT fk_learning_classes_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_classes_course FOREIGN KEY(course_id) REFERENCES learning_courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_classes_congregation FOREIGN KEY(congregation_id) REFERENCES congregations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learning_class_teachers (
  church_id CHAR(36) NOT NULL,
  class_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,
  teacher_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(class_id,member_id),
  UNIQUE KEY uq_learning_teacher_order (class_id,teacher_order),
  KEY idx_learning_teacher_member (church_id,member_id),
  CONSTRAINT fk_learning_teachers_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_teachers_class FOREIGN KEY(class_id) REFERENCES learning_classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_teachers_member FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learning_enrollments (
  church_id CHAR(36) NOT NULL,
  class_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'enrolled',
  enrolled_on DATE NOT NULL,
  completed_on DATE NULL,
  notes VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(class_id,member_id),
  KEY idx_learning_enrollment_member (church_id,member_id,status),
  CONSTRAINT fk_learning_enrollment_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_enrollment_class FOREIGN KEY(class_id) REFERENCES learning_classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_enrollment_member FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learning_lessons (
  id CHAR(36) NOT NULL,
  church_id CHAR(36) NOT NULL,
  class_id CHAR(36) NOT NULL,
  title VARCHAR(190) NOT NULL,
  lesson_date DATE NOT NULL,
  description TEXT NULL,
  material_url VARCHAR(1000) NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_learning_lessons_class (church_id,class_id,lesson_date),
  CONSTRAINT fk_learning_lessons_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_lessons_class FOREIGN KEY(class_id) REFERENCES learning_classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learning_attendance (
  church_id CHAR(36) NOT NULL,
  lesson_id CHAR(36) NOT NULL,
  member_id CHAR(36) NOT NULL,
  present TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(lesson_id,member_id),
  KEY idx_learning_attendance_member (church_id,member_id),
  CONSTRAINT fk_learning_attendance_church FOREIGN KEY(church_id) REFERENCES churches(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_attendance_lesson FOREIGN KEY(lesson_id) REFERENCES learning_lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_attendance_member FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
