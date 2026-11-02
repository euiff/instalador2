SET NAMES utf8mb4;

-- Papéis administrativos de igreja são sempre locais ao tenant e ficam em
-- church_members.church_role. user_roles é reservado ao papel global
-- super_admin da plataforma.
DELETE FROM user_roles WHERE role='admin';
