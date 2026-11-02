SET NAMES utf8mb4;

INSERT INTO user_roles(id,user_id,role)
SELECT UUID(),cm.user_id,'admin'
FROM church_members cm
WHERE (cm.is_owner=1 OR cm.church_role='admin')
  AND NOT EXISTS (
    SELECT 1 FROM user_roles ur
    WHERE ur.user_id=cm.user_id AND ur.role='admin'
  );
