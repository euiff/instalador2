SET NAMES utf8mb4;

ALTER TABLE church_members
  ADD COLUMN IF NOT EXISTS church_role VARCHAR(50) NOT NULL DEFAULT 'admin' AFTER is_owner,
  ADD COLUMN IF NOT EXISTS permissions JSON NULL AFTER church_role;

UPDATE church_members
SET church_role='owner'
WHERE is_owner=1;

UPDATE church_members
SET church_role='secretary'
WHERE LOWER(church_role) IN ('secretaria','secretario','secretária','secretário');

UPDATE church_members
SET church_role='treasurer'
WHERE LOWER(church_role) IN ('tesouraria','tesoureiro','tesoureira');

UPDATE church_members
SET church_role='pastor'
WHERE LOWER(church_role) IN ('pastor','lider','líder');

UPDATE church_members
SET church_role='admin'
WHERE church_role NOT IN ('owner','admin','pastor','secretary','treasurer');

